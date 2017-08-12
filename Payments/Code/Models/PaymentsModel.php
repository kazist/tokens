<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace Tokens\Payments\Code\Models;

defined('KAZIST') or exit('Not Kazist Framework');

use Kazist\KazistFactory;
use Payments\Payments\Code\Models\PaymentsModel AS BasePaymentsModel;
use Kazist\Service\Database\Query;

/**
 * Description of MarketplaceModel
 *
 * @author sbc
 */
class PaymentsModel extends BasePaymentsModel {

    public function notificationTransaction($payment_id) {

        $this->completeTransaction($payment_id);
    }

    public function completeTransaction($payment_id) {

        $token = $this->request->request->get('token');

        $process_token = $this->processTokenPayment($payment_id);

        if ($process_token) {
            parent::successfulTransaction($payment_id, trim($token));
        } else {
            parent::failTransaction($payment_id);
        }
    }

    public function cancelTransaction($payment_id) {

        parent::cancelTransaction($payment_id);
    }

    public function processTokenPayment($payment_id) {

        $is_valid = true;

        $factory = new KazistFactory();

        $token = $this->request->request->get('token');

        $gateway = $this->getGatewayByName('token');

        $token_obj = $this->getToken(trim($token));
        $payment = $this->getPaymentById($payment_id);
        $deductions = json_decode($payment->deductions);
        $required_amount = ($deductions->amount) ? $deductions->amount : $payment->amount;
        $paid_amount = $token_amount = ($token_obj->amount) ? $token_obj->amount : '';
        $limit_amount = $token_obj->limit_amount;

        $payment->code = $token;
        $payment->receipt_no = $payment->receipt_no;
        $payment->type = 'token';
        $payment->gateway_id = $gateway->id;

        if ($limit_amount && $required_amount > $token_amount && $required_amount <= $limit_amount) {
            $paid_amount = $required_amount;
        }


        if (!is_object($token_obj)) {
            $factory->enqueueMessage('Token (' . $token . ') does not exist.', 'error');
            return false;
        }

        parent::savePaidAmount($payment, $required_amount, $paid_amount);

        if ($paid_amount < $required_amount) {
            $is_valid = false;
        }

        $this->updateToken($token_obj);

        return $is_valid;
    }

    public function updateToken($token_obj) {

        $factory = new KazistFactory();
        $user = $factory->getUser();

        $token_obj->used = 1;
        $token_obj->date_used = date('Y-m-d H:i:s');
        $token_obj->used_by = $user->id;

        $factory->saveRecord('#__tokens_payments', $token_obj);
    }

    public function getToken($token) {
        $factory = new KazistFactory();


        $query = new Query();
        $query->select('ft.*, ftt.limit_amount');
        $query->from('#__tokens_payments', 'ft');
        $query->leftJoin('ft', '#__tokens_payments_types', 'ftt', 'ftt.id = ft.type_id');
        $query->where('ft.token=:token');
        $query->andWhere('(ft.used=0 OR ft.used IS NULL)');
        $query->setParameter('token', $token);


        $record = $query->loadObject();

        return $record;
    }

}
