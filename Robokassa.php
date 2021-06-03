<?php

require_once('api/Simpla.php');

class Robokassa extends Simpla
{
    private $payment_method = [],
        $order = [],
        $payment_settings = [],
        $debug = 0;

    public function checkout_form($order_id, $button_text = null)
    {
        if(empty($button_text))
            $button_text = 'Перейти к оплате';

        $this->order = $this->orders->get_order((int)$order_id);
        $this->payment_method = $this->payment->get_payment_method($this->order->payment_method_id);
        $this->payment_settings = $this->payment->get_payment_settings($this->payment_method->id);

        $price = $this->money->convert($this->order->total_price, $this->payment_method->currency_id, false);

        // Debug mode
        $this->payment_settings['robokassa_debug'] == 1 AND $_SESSION['admin'] ? $this->debug = 1 : $this->debug = 0;

        $success_url = $this->config->root_url.'/order/'.$this->order->url;
        $fail_url = $this->config->root_url.'/order/'.$this->order->url;

        // регистрационная информация (логин, пароль #1)
        // registration info (login, password #1)
        $mrh_login = $this->payment_settings['login'];
        $mrh_pass1 = $this->payment_settings['password1'];

        // номер заказа
        // number of order
        $inv_id = $this->order->id;

        // описание заказа
        // order description
        $inv_desc = 'Оплата заказа №'.$inv_id;

        // метод оплаты - текущий
        $shp_item = $this->payment_method->id;

        // предлагаемая валюта платежа
        // default payment e-currency
        $in_curr = "PCR";

        // язык
        // language
        $culture = $this->payment_settings['language'];

        // Информация о товарах для чека
        $receipt = [
            "sho" => $this->payment_settings['sno'],
        ];

        $purchases = $this->normalize($this->orders->get_purchases(['order_id' => $this->order->id]));

        foreach($purchases as $key => $purchase) {
            $receipt['items'][$key] = [
                "name" => $purchase->product_name,
                "quantity" => $purchase->amount,
                "sum" => $purchase->price * $purchase->amount,
                "payment_method" => "full_payment",
                "payment_object" => "commodity",
                "tax" => ($this->payment_settings['sno'] == 'osn') ? $this->payment_settings['tax'] : 'none'
            ];
        }

        // Добавляем доставку в чек
        if ($this->payment_settings['robokassa_delivery'] == 'item' AND $this->order->delivery_id AND $this->order->delivery_price > 0 AND !$this->order->separate_delivery) {

            $delivery = $this->delivery->get_delivery($this->order->delivery_id);

            $key = count($receipt['items']);
            $receipt['items'][$key] = [
                "name" => $delivery->name,
                "quantity" => 1,
                "sum" => $this->order->delivery_price,
                "payment_method" => "full_payment",
                "payment_object" => "service",
                "tax" => ($this->payment_settings['sno'] == 'osn') ? $this->payment_settings['tax'] : 'none'
            ];
        }

        $receipt = json_encode($receipt);

        if ($this->debug) {
            print '<pre>';
            echo '<h3>Сумма заказа для Робокассы:</h3>';
            var_dump($this->order->total_price);
            echo '<h3>Текущий заказ в БД:</h3>';
            var_dump($this->order);
            echo '<h3>Корзина для ФЗ-54:</h3>';
            var_dump(json_decode($receipt));
            print '</pre>';
        }

        // формирование подписи
        // generate signature
        $crc  = md5("$mrh_login:$price:$inv_id:$receipt:$mrh_pass1");

        $button =	"<form accept-charset='cp1251' action='https://merchant.roboxchange.com/Index.aspx' method=POST>".
            "<input type=hidden name=MrchLogin value='$mrh_login'>".
            "<input type=hidden name=OutSum value='$price'>".
            "<input type=hidden name=InvId value='$inv_id'>".
            "<input type=hidden name=Desc value='$inv_desc'>".
            "<input type=hidden name=SignatureValue value='$crc'>".
            "<input type=hidden name=IncCurrLabel value='$in_curr'>".
            "<input type=hidden name=Culture value='$culture'>".
            "<input type=hidden name=Receipt value='". urlencode($receipt) . "'>".
            "<input type=submit class=checkout_button value='Перейти к оплате &#8594;'>".
            "</form>";
        return $button;
    }



    /**
     * Подгоняет стоимость товаров в чеке, кроме доставки, к общей цене заказа
     *
     * @param object $purchases товары в заказе
     * @return object $purchases
     */
    private function normalize($purchases)
    {
        // Общая стоимость заказа (с учетом процентной скидки)
        $total_price = $this->order->total_price;

        // Если есть доставка, отнимаем стоимость доставки от общей суммы заказа
        if ($this->order->delivery_price && $this->order->delivery_price > 0 && !$this->order->separate_delivery) {
            $total_price -= $this->order->delivery_price;
        }

        // Добавляем стоимость скидки coupon_discount
        $total_price += $this->order->coupon_discount;

        $items_total_price = 0; // Общая стоимость позиций по отдельности.
        $corrected_purchases = []; // Корректирующий массив цен
        foreach ($purchases as $key => $item) {
            $items_total_price += $item->price * $item->amount;
            $corrected_purchases[$key] = $item->price;
        }

        /**
         * размазываем discount на все товары
         * discount - процентная скидка
         */
        if ($this->order->discount > 0) {

            // объем процентной скидки - вычитаю процент скидки / пользовательской скидки
            $discount_sum = ($items_total_price * $this->order->discount) / 100;

            foreach ($purchases as $key => $item) {
                $corrected_purchases[$key] -= $this->coefficient_price($item->amount, $item->price, $discount_sum, $items_total_price);

                if ($this->debug) {
                    echo 'Цена за item с учетом процентной скидки: ' . $corrected_purchases[$key] . '<br>';
                }
            }
        }

        /**
         * размазываем coupon_discount на все товары
         * coupon_discount - скидка по купону
         */
        if ($this->order->coupon_discount > 0) {
            foreach ($purchases as $key => $item) {
                // Вычислим процентное соотношение item price * amount от общей суммы заказа
                $corrected_purchases[$key] -= $this->coefficient_price($item->amount, $item->price, $this->order->coupon_discount, $items_total_price);

                if ($this->debug) {
                    echo 'Цена за item с учетом скидки по купону: ' . $corrected_purchases[$key] . '<br>';
                }
            }
        }


        /*
         * DELIVERY
         * Добавляем доставку в каждый товар
        */
        if ($this->payment_settings['robokassa_delivery'] == 'include_item' AND !$this->order->separate_delivery) {
            foreach ($purchases as $key => $item) {
                $corrected_purchases[$key] += $this->coefficient_price($item->amount, $item->price, $this->order->delivery_price, $items_total_price);

                if ($this->debug) {
                    echo 'Цена за item с учетом доставки: ' . $corrected_purchases[$key] . '<br>';
                }
            }
        }


        /*
         * Смотрим финальную разницу, если она есть, добавляем к последней позиции разницу.
         */
        $all_sum = 0;
        $all_sum_diff = 0;
        foreach ($purchases as $key => $item) {
            $item->price = $corrected_purchases[$key];
            $all_sum += $item->price * $item->amount;
        }

        // Если доставка как отдельная позиция
        if($this->payment_settings['robokassa_delivery'] == 'item' && $this->order->delivery_price && $this->order->delivery_price > 0 && !$this->order->separate_delivery) {
            $all_sum += $this->order->delivery_price;
        }

        // Корректируем
        if ($this->order->total_price != $all_sum) {
            $all_sum_diff = $this->order->total_price - $all_sum;
            $all_sum_diff = round($all_sum_diff, 2);

            // or `array_key_last` if php 7 >= 7.3.0
            end($purchases);
            $last_key = key($purchases);

            // Дублируем последнюю позицию в чеке, если их несколько для правильной корректировки цен
            if ($purchases[$last_key]->amount > 1) {
                $purchases[$last_key]->amount -= 1;
                $copy_item = clone $purchases[$last_key];
                $copy_item->amount = 1;
                $copy_item->price += $all_sum_diff;
                $purchases[] = $copy_item;
            } else {
                $purchases[$last_key]->price += $all_sum_diff / $purchases[$last_key]->amount;
            }

            if($this->debug) {
                echo '<h3>Корректировка сумы заказа</h3>';
                echo '$this->order->total_price: ' . $this->order->total_price . '<br/>';
                echo '$all_sum: ' . $all_sum . '<br/>';
                echo 'Отличие от суммы заказа: ' . $all_sum_diff . '<br/>';
            }
        }

        return $purchases;
    }

    /**
     * Считаем коэффициент
     *
     * @param $item_amount
     * @param $item_price
     * @param $sum
     * @param $items_total_price
     * @return false|float
     */
    private function coefficient_price($item_amount, $item_price, $sum, $items_total_price) {
        $coefficient = ($item_amount * $item_price) * 100 / $items_total_price;
        return ceil(round((($sum * $coefficient) / 100) / $item_amount, 2));
    }

}
