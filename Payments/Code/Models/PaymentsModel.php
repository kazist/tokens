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

    public function appendSearchQuery($query) {

        $this->ingore_search_query = true;
        return parent:: appendSearchQuery($query);
    }

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

        $gateway = $this->getGatewayByName('tokens');

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

        $this->saveTokenPayment($required_amount);
        $this->updateToken($token_obj);

        return $is_valid;
    }

    public function saveTokenPayment($amount) {
        
        $factory = new KazistFactory();
        $user = $factory->getUser();
        $token = $this->request->get('token');

        $token_obj = new \stdClass();
        $token_obj->token = $token;
        $token_obj->user_id = $user->id;
        $token_obj->amount = $amount;

        $factory->saveRecord('#__tokens_payments', $token_obj);
    }

    public function updateToken($token_obj) {

        $factory = new KazistFactory();
        $user = $factory->getUser();

        $token_obj->used = 1;
        $token_obj->date_used = date('Y-m-d H:i:s');
        $token_obj->used_by = $user->id;

        $factory->saveRecord('#__tokens_tokens', $token_obj);
    }

    public function getToken($token) {
        $factory = new KazistFactory();


        $query = new Query();
        $query->select('tp.*, tt.limit_amount');
        $query->from('#__tokens_tokens', 'tp');
        $query->leftJoin('tp', '#__tokens_types', 'tt', 'tt.id = tp.type_id');
        $query->where('tp.token=:token');
        $query->andWhere('(tp.used=0 OR tp.used IS NULL)');
        $query->setParameter('token', $token);


        $record = $query->loadObject();

        return $record;
    }

}
