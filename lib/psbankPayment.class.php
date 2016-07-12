<?php

/**
 *
 * @author wa-plugins.ru
 * @name psbank
 * @description psbank Payments
 *
 */
class psbankPayment extends waPayment implements waIPayment {

    const MAC_REQUEST = 'request';
    const MAC_RESPONSE = 'response';

    private $url_test = 'https://test.3ds.payment.ru/cgi-bin/cgi_link';
    private $url = 'https://3ds.payment.ru/cgi-bin/cgi_link';
    private $currency = array(
        'RUB',
    );

    protected function initControls() {
        $this->registerControl('ResponseUrlControl');

        parent::initControls();
    }

    public static function settingResponseUrlControl($name, $params = array()) {
        $instance = $params['instance'];

        if ((int) $instance->key) {
            $control = sprintf('<strong>%s?app_id=%s&merchant_id=%d</strong>', $instance->getRelayUrl(), wa()->getApp(), $instance->key);
        } else {
            $control = '<strong>Корректный URL будет доступен после сохранения настроек</strong>';
        }

        return $control;
    }

    public function allowedCurrency() {
        return $this->currency;
    }

    private function getUrl() {
        if ($this->test_mode) {
            return $this->url_test;
        } else {
            return $this->url;
        }
    }

    private function checkSettings() {
        if (empty($this->TERMINAL)) {
            throw new waException('Ошибка оплаты. Укажите значение TERMINAL');
        }
        if (empty($this->MERCHANT)) {
            throw new waException('Ошибка оплаты. Укажите значение MERCHANT');
        }
        if (empty($this->KEY)) {
            throw new waException('Ошибка оплаты. Укажите значение KEY');
        }
    }

    public function payment($payment_form_data, $order_data, $auto_submit = false) {
        $this->checkSettings();
        $order = waOrder::factory($order_data);
        if (!in_array($order->currency_id, $this->allowedCurrency())) {
            throw new waException('Ошибка оплаты. Валюта не поддерживается');
        }



        $data = array(
            'AMOUNT' => number_format($order->total, 2, '.', ''),
            'CURRENCY' => $order->currency_id,
            'ORDER' => str_pad($order->id, 6, '0', STR_PAD_LEFT),
            'DESC' => 'Оплата заказа ' . shopHelper::encodeOrderId($order->id),
            'TERMINAL' => $this->TERMINAL,
            'TRTYPE' => '1', // оплата
            'MERCH_NAME' => wa()->accountName(),
            'MERCHANT' => $this->MERCHANT,
            'EMAIL' => '',
            'TIMESTAMP' => gmdate('YmdHis'),
            'NONCE' => $this->generateNonce(),
            'BACKREF' => $this->getAdapter()->getBackUrl(waAppPayment::URL_SUCCESS),
            'P_SIGN' => null,
        );

        $mac = $this->generateMac($data, self::MAC_REQUEST);
        $hmac = $this->generateHmac($mac, $this->KEY);
        $data['P_SIGN'] = $hmac;

        $view = wa()->getView();
        $view->assign('hidden_fields', $data);
        $view->assign('form_url', $this->getUrl());
        $view->assign('auto_submit', $auto_submit);
        return $view->fetch($this->path . '/templates/payment.html');
    }

    protected function callbackInit($request) {
        if (!empty($request['app_id']) && !empty($request['merchant_id'])) {
            $this->app_id = $request['app_id'];
            $this->merchant_id = $request['merchant_id'];
        }
        return parent::callbackInit($request);
    }

    protected function callbackHandler($request) {
        try {
            $this->checkSettings();
            if (!isset($request['RESULT'])) {
                throw new waException('Пустой ответ банка');
            }

            $transaction_data = $this->formalizeData($request);

            $mac = $this->generateMac($request, self::MAC_RESPONSE);
            $hmac = $this->generateHmac($mac, $this->KEY);

            if ($hmac != $request['P_SIGN']) {
                $message = 'Неверный ответ банка';
                $app_payment_method = self::CALLBACK_DECLINE;
                $transaction_data['state'] = self::STATE_DECLINED;
            } else {
                switch ($request['RESULT']) {
                    case 0:
                        $message = 'Операция успешно завершена';
                        $app_payment_method = self::CALLBACK_PAYMENT;
                        $transaction_data['state'] = self::STATE_CAPTURED;
                        break;
                    case 1:
                        $message = 'Операция успешно завершена';
                        $app_payment_method = self::CALLBACK_PAYMENT;
                        $transaction_data['state'] = self::STATE_CAPTURED;
                        break;
                    case 2:
                        $message = 'Запрос отклонен  банком';
                        $app_payment_method = self::CALLBACK_DECLINE;
                        $transaction_data['state'] = self::STATE_DECLINED;
                        break;
                    case 3:
                        $message = 'Запрос отклонен платежным шлюзом';
                        $app_payment_method = self::CALLBACK_DECLINE;
                        $transaction_data['state'] = self::STATE_DECLINED;
                        break;
                }
            }
            self::log($this->id, $message);

            $transaction_data = $this->saveTransaction($transaction_data, $request);
            $this->execAppCallback($app_payment_method, $transaction_data);
        } catch (Exception $ex) {
            self::log($this->id, $ex->getMessage());
        }
    }

    protected function formalizeData($transaction_raw_data) {

        $transaction_data = parent::formalizeData($transaction_raw_data);
        $transaction_data['native_id'] = $transaction_raw_data['ORDER'];
        $transaction_data['order_id'] = (int) $transaction_raw_data['ORDER'];
        $transaction_data['currency_id'] = $transaction_raw_data['CURRENCY'];
        $transaction_data['amount'] = $transaction_raw_data['AMOUNT'];

        return $transaction_data;
    }

    private function generateNonce() {
        $chars = "0123456789ABCDEF";
        $nonce = '';
        $length = rand(16, 32);
        for ($i = 0; $i < $length; $i++) {
            $n = rand(0, strlen($chars) - 1);
            $nonce .= $chars{$n};
        }
        return $nonce;
    }

    private function generateMac($data, $type = self::MAC_REQUEST) {
        if ($type == self::MAC_REQUEST) {
            $keys = explode(',', 'AMOUNT,CURRENCY,ORDER,MERCH_NAME,MERCHANT,TERMINAL,EMAIL,TRTYPE,TIMESTAMP,NONCE,BACKREF');
        } elseif ($type == self::MAC_RESPONSE) {
            $keys = explode(',', 'AMOUNT,CURRENCY,ORDER,MERCH_NAME,MERCHANT,TERMINAL,EMAIL,TRTYPE,TIMESTAMP,NONCE,BACKREF,RESULT,RC,RCTEXT,AUTHCODE,RRN,INT_REF');
        }

        $mac = '';
        foreach ($keys as $key) {
            if (isset($data[$key])) {
                $value = (string) $data[$key];
                if (strlen($value)) {
                    $mac .= strlen($value) . $value;
                } else {
                    $mac .= '-';
                }
            }
        }
        return $mac;
    }

    private function generateHmac($mac, $secretkey) {
        $hmac = hash_hmac('sha1', $mac, pack('H*', $secretkey));
        $hmac = strtoupper($hmac);
        return $hmac;
    }

}
