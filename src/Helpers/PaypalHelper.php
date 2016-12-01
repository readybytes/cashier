<?php
/**
 * Created by PhpStorm.
 * User: jitendra
 * Date: 27/10/16
 * Time: 12:25 PM
 */

namespace Laravel\Cashier\Helpers;

use App\Listeners\RevenueSplitter;
use App\vod\model\Movie;
use App\vod\model\ResourceAllocated;
use Illuminate\Support\Facades\Config;
use Laravel\Cashier\Cart;
use Laravel\Cashier\Invoice;
use Laravel\Cashier\PaymentProcessor;
use Laravel\Cashier\PostpaidBills;
use Laravel\Cashier\Transaction;
use Laravel\Cashier\Wallet;
use Laravel\Cashier\WalletHistory;

class PayPalHelper
{
    protected   $refund_support = true;

    private function __preparePaymentDetails($user, $request)
    {
        $payment_data = [
            "processor_type"    => $request['processor_type'],
            "method"            => $request['processor_method'],
        ];

        return $payment_data;
    }

    public static function prepareConfig($request)
    {
        $config = [];
        $config["title"]                  = $request->get("title", "");
        $config["description"]            = $request->get("description", "");
        $config["paypal_email"]           = $request->get("paypal_email", "");
        $config["sandbox_api_username"]   = $request->get("sandbox_api_username", "");
        $config["sandbox_api_password"]   = $request->get("sandbox_api_password", "");
        $config["sandbox_api_signature"]  = $request->get("sandbox_api_signature", "");
        $config["live_api_username"]      = $request->get("live_api_username", "");
        $config["live_api_password"]      = $request->get("live_api_password", "");
        $config["live_api_signature"]     = $request->get("live_api_signature", "");
        $config["live_account"]           = $request->get("paypal_account", 0);

        return $config;
    }

    public function payCart($request, $user, $cart)
    {
        try{
            $processor  = PaymentProcessor::where("processor_type", "paypal")->first();

            // get the amount to be paid
            $amount     = $request['amount'];

            // create invoice
            $invoice    = Invoice::createInvoice($user, NO_GROUP, $amount, true, $cart->id);

            // create transaction
            $txn        = $invoice->createTransaction($processor->id, $request);

            // check if invoice id
            $response = [
                "status"     => true,
                "invoice_id" => $invoice->id,
            ];

        } catch(\Exception $e){

            // delete the transaction
            if(isset($txn)){
                // revert the transaction in case it is already processed
                if($txn->payment_status == TRANSACTION_STATUS_PAYMENT_COMPLETE){
                    $this->requestRefund($txn, $invoice);
                }
                $txn->delete();
            }

            // delete the invoice
            if(isset($invoice)){
                $invoice->delete();
            }

            // prepare response
            $response   = [
                "status"            => false,
                "message"           => "failed",
            ];

            // TODO::Log the exception properly
        }

        return $response;
    }

    public function afterPaymentWithPayPal($payment_details, $invoice, $txn)
    {
        try{
            $processor      = PaymentProcessor::where('id',$txn->processor_id)->first();
            $params         = json_decode($processor->processor_config, true);

            if($params["live_account"]) {
                $sandbox = false;
            } else{
                $sandbox = true;
            }

            $response   = $this->__getTransactionDetails($payment_details['gateway_txn_id'], $sandbox, $processor);

            if($response['ACK'] == "Success") {
                $payment_details['gateway_txn_fees'] = $response['FEEAMT'];
            }
            $status     = $this->processTransaction($invoice, $txn, $payment_details, $response);

            // check if invoice has been marked paid
            if($status == INVOICE_STATUS_PAID){
                // update Cart

                $params   = json_decode($invoice->params, true);

                $cart     = Cart::where('id',$params['cart_id'])->first();
                $cart->updateStatus(CART_STATUS_PAID, NO_GROUP, $txn);
                $status = true;
            } else{
                $status = false;
            }

            // prepare response
            $response   = [
                "status"            => $status,
                "message"           => $txn->message,
                "payment_details"   => true,
                "invoice_id"        => $invoice->id,
            ];


        } catch(\Exception $e){

            // prepare response
            $response   = [
                "status"            => false,
                "message"           => "failed",
            ];

            // TODO::Log the exception properly
        }

        return $response;
    }

    public function processTransaction($invoice, $txn, $payment_details, $response)
    {
        // make payment
        try{
            $params                             = json_decode($txn->params, true);
            $params["txn_details"]["response"]  = $response;

            // update the payment related details in transaction
            $txn->update([
                "gateway_txn_fees"  => $payment_details["gateway_txn_fees"] ? $payment_details["gateway_txn_fees"] : 0,
                "gateway_txn_id"    => $payment_details["gateway_txn_id"],
                "params"            => json_encode($params),
            ]);

            // update the transaction status
            $txn->updateStatus(TRANSACTION_STATUS_PAYMENT_COMPLETE);

            // mark the invoice paid
            $invoice->markPaid();

        } catch(\Exception $e){
            $txn->message   = "The source you provided is not in a chargeable state.";
            $txn->updateStatus(TRANSACTION_STATUS_PAYMENT_FAILED);
        }

        return $invoice->status;
    }

    private function __getTransactionDetails($txn_id, $sandbox, $processor)
    {
        $requestNvp = $this->_prepareNvpConfig($txn_id, $sandbox, $processor, 'GetTransactionDetails');

        $response   = $this->__sendApiRequest($sandbox, $requestNvp);

        return $response;
    }

    protected function _prepareNvpConfig($gateway_txn_id, $sandbox, $processor, $method)
    {
        $version      = phpversion();

        $processor_config = json_decode($processor->processor_config, true);

        $requestNvp   = [
            'USER'              => $sandbox ? $processor_config['sandbox_api_username'] : $processor_config['live_api_username'],
            'PWD'               => $sandbox ? $processor_config['sandbox_api_password'] : $processor_config['live_api_password'],
            'SIGNATURE'         => $sandbox ? $processor_config['sandbox_api_signature'] : $processor_config['live_api_signature'],
            'VERSION'           => $version,
            'METHOD'            => $method,
            'TRANSACTIONID'     => $gateway_txn_id,
        ];

        return $requestNvp;
    }

    public function isRefundSupported()
    {
        // Yes, PayPal supports refunds
        return $this->refund_support;
    }

    public function requestRefund($txn, $invoice, $sandbox = true)
    {
        // make refund
        try{
            // create a txn for refund
            $refund_txn = Transaction::createTransactionForRefund($txn);

            $processor      = PaymentProcessor::where('id',$txn->processor_id)->first();
            $params         = json_decode($processor->processor_config, true);

            if($params["live_account"]) {
                $sandbox = false;
            }

            $requestNvp             = $this->_prepareNvpConfig($txn->gateway_txn_id, $sandbox, $processor, 'RefundTransaction');
            $requestNvp['REFUNDTYPE'] = 'Full';

            $response   = $this->__sendApiRequest($sandbox, $requestNvp);

            if (isset($response['ACK']) == 'Success') {

                $params                     = json_decode($refund_txn->params, true);
                $params["refund_details"]   = $response;

                // update the payment related details in transaction
                $refund_txn->update([
                    "gateway_txn_id"    => $response["REFUNDTRANSACTIONID"],
                    "params"            => json_encode($params),
                ]);

                // update the transaction status
                $refund_txn->updateStatus(TRANSACTION_STATUS_PAYMENT_REFUND);

                // mark the invoice refunded
                $invoice->markRefunded();

            } else{
                throw new \Exception($response['"L_LONGMESSAGE0']);
            }

        } catch(\Exception $e){
            $txn->message   = $e->getMessage();
            $txn->updateStatus(TRANSACTION_STATUS_REFUND_FAILED);
        }

        $status = $invoice->status == INVOICE_STATUS_REFUNDED ? true : false;
        return $status;
    }

    private function __sendApiRequest($sandbox, $requestNvp)
    {
        $apiEndpoint  = 'https://api-3t.' . ($sandbox? 'sandbox.': null);
        $apiEndpoint .= 'paypal.com/nvp';

        $curl = curl_init();

        curl_setopt($curl, CURLOPT_URL, $apiEndpoint);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($requestNvp));

        $responseNvp = urldecode(curl_exec($curl));

        curl_close($curl);

        $response = array();

        parse_str($responseNvp, $response);

        return $response;
    }

    public function rechargeWallet($request, $user)
    {
        try{
            $processor  = PaymentProcessor::where("processor_type", $request['processor_type'])->first();

            // get the amount to be paid
            $amount     = $request['amount'];

            // create invoice
            $invoice    = Invoice::createInvoice($user, $request['group_id'], $amount, true, 0, "Wallet Recharge");

            // payment details
            $payment_details    = $this->__preparePaymentDetails($user, $request);

            $payment_details["group_id"] = $request['group_id'];

            // create transaction
            $txn        = $invoice->createTransaction($processor->id, $payment_details);

            $response = [
                "status"     => true,
                "invoice_id" => $invoice->id,
            ];

        } catch(\Exception $e){

            // delete the transaction
            if(isset($txn)){
                // revert the transaction in case it is already processed
                if($txn->payment_status == TRANSACTION_STATUS_PAYMENT_COMPLETE){
                    $this->requestRefund($txn, $invoice);
                }
                $txn->delete();
            }

            // delete the invoice
            if(isset($invoice)){
                $invoice->delete();
            }

            // prepare response
            $response   = [
                "status"            => false,
                "message"           => "failed",
            ];

            // TODO::Log the exception properly
        }

        return $response;
    }

    public function afterRechargeWallet($payment_details, $user, $invoice_id)
    {
        $invoice    = Invoice::where('id',$invoice_id)->first();

        $txn        = Transaction::where('invoice_id',$invoice_id)->first();
        $params     = json_decode($txn->params,true);

        $group_id   = $params["payment_details"]["group_id"];
        $amount     = $payment_details['amount'];

        $processor  = PaymentProcessor::where('id',$txn->processor_id)->first();
        $params     = json_decode($processor->processor_config, true);

        if($params["live_account"]) {
            $sandbox = false;
        } else{
            $sandbox = true;
        }

        $response   = $this->__getTransactionDetails($payment_details['gateway_txn_id'], $sandbox, $processor);

        if($response['ACK'] == "Success") {
            $payment_details['gateway_txn_fees'] = $response['FEEAMT'];
        }

        // make payment
        $status     = $this->processTransaction($invoice, $txn, $payment_details, $response);

        // check if invoice has been marked paid
        if($status == INVOICE_STATUS_PAID){
            // update Wallet Balance
            $user_id    = $group_id ? 0 : $user->id;

            Wallet::updateWalletAfterRecharge($user_id, $group_id, $amount, $invoice->id);
            $status = true;
        } else{
            $status = false;
        }

        // prepare response
        $response   = [
            "status"            => $status,
            "message"           => $txn->message,
            "payment_details"   => $payment_details,
            "group_id"          => $group_id,
            "processor_type"    => $processor->processor_type,
        ];

        return $response;
    }

    public function payForTokenBasedUrl($request, $user, $resource_data)
    {
        try{
            $processor  = PaymentProcessor::where("processor_type", $request['processor_type'])->first();

            // get the amount to be paid
            $amount     = $request['amount'];

            // create invoice
            $invoice    = Invoice::createInvoice($user, $resource_data['group_id'], $amount, true, 0, "Url Sharing for ".ucwords($resource_data["movie_title"]));

            // payment details
            $payment_details             = $this->__preparePaymentDetails($user, $request);

            $payment_details["time_limit"]      = $request['time_limit'];
            $payment_details["movie_id"]        = $resource_data['movie_id'];
            $payment_details["group_id"]        = $resource_data['group_id'];

            // create transaction
            $txn        = $invoice->createTransaction($processor->id, $payment_details);

            // prepare response
            $response = [
                "status"     => true,
                "invoice_id" => $invoice->id,
            ];

        } catch(\Exception $e){

            // delete the transaction
            if(isset($txn)){
                // revert the transaction in case it is already processed
                if($txn->payment_status == TRANSACTION_STATUS_PAYMENT_COMPLETE){
                    $this->requestRefund($txn, $invoice);
                }
                $txn->delete();
            }

            // delete the invoice
            if(isset($invoice)){
                $invoice->delete();
            }

            // prepare response
            $response   = [
                "status"            => false,
                "message"           => $e->getMessage(),
            ];

            // TODO::Log the exception properly
        }

        return $response;
    }

    public function afterPayForTokenBasedUrl($invoice_id, $user, $payment_details)
    {
        $invoice    = Invoice::where('id',$invoice_id)->first();

        $txn        = Transaction::where('invoice_id',$invoice_id)->first();
        $params     = json_decode($txn->params,true);

        $resource_data      = [
            "movie_id"      => $params["payment_details"]["movie_id"],
            "group_id"      => $params["payment_details"]["group_id"],
            "user_id"       => $user->id,
            "time_limit"    => $params["payment_details"]["time_limit"],
            "movie_title"   => Movie::find($params["payment_details"]["movie_id"])->title,
        ];

        $processor           = PaymentProcessor::where('id',$txn->processor_id)->first();
        $processor_config    = json_decode($processor->processor_config, true);

        if($processor_config["live_account"]) {
            $sandbox = false;
        } else{
            $sandbox = true;
        }

        $response   = $this->__getTransactionDetails($payment_details['gateway_txn_id'], $sandbox, $processor);

        if($response['ACK'] == "Success") {
            $payment_details['gateway_txn_fees'] = $response['FEEAMT'];
        }

        // make payment
        $status     = $this->processTransaction($invoice, $txn, $payment_details, $response);

        // check if invoice has been marked paid
        if($status == INVOICE_STATUS_PAID){
            $resource   = ResourceAllocated::allocateAnonymousAccess($resource_data);
            RevenueSplitter::splitSharedLinkRevenue($txn, $resource->id);
            $status = true;
        } else{
            $status = false;
        }

        // prepare response
        $response   = [
            "status"            => $status,
            "message"           => $txn->message,
            "payment_details"   => $payment_details,
            "invoice_id"        => $invoice->id,
            "resource"          => $resource,
            "processor_type"    => $processor->processor_type,
            "movie_id"          => $resource_data["movie_id"],
            "group_id"          => $resource_data["group_id"],
        ];

        return $response;
    }

    public function payForPostpaidBill($request, $user, $invoice_id)
    {
        try{

            $processor  = PaymentProcessor::where("processor_type", $request['processor_type'])->first();

            // get invoice
            $invoice    = Invoice::find($invoice_id);

            // payment details
            $payment_details    = $this->__preparePaymentDetails($user, $request);

            // create transaction
            $txn        = $invoice->createTransaction($processor->id, $payment_details);

            // prepare response
            $response   = [
                "status"            => true,
                "message"           => $txn->message,
                "payment_details"   => $payment_details,
                "invoice_id"        => $invoice->id,
            ];
        } catch(\Exception $e){

            // delete the transaction
            if(isset($txn)){
                // revert the transaction in case it is already processed
                if($txn->payment_status == TRANSACTION_STATUS_PAYMENT_COMPLETE){
                    $this->requestRefund($txn, $invoice, true);
                }
                $txn->delete();
            }

            // delete the invoice
            if(isset($invoice)){
                // delete the entries from wallet history
                WalletHistory::where("payment_invoice_id", $invoice->id)->delete();

                $invoice->delete();
            }

            // prepare response
            $response   = [
                "status"            => false,
                "message"           => $e->getMessage(),
            ];

            // TODO::Log the exception properly
        }

        return $response;
    }

    public function afterPayForPostpaidBill($payment_details, $user, $invoice_id)
    {
        $invoice    = Invoice::where('id',$invoice_id)->first();

        $txn        = Transaction::where('invoice_id',$invoice_id)->first();

        $processor  = PaymentProcessor::where('id',$txn->processor_id)->first();
        $params     = json_decode($processor->processor_config, true);

        if($params["live_account"]) {
            $sandbox = false;
        } else{
            $sandbox = true;
        }

        $response   = $this->__getTransactionDetails($payment_details['gateway_txn_id'], $sandbox, $processor);

        if($response['ACK'] == "Success") {
            $payment_details['gateway_txn_fees'] = $response['FEEAMT'];
        }

        $status     = $this->processTransaction($invoice, $txn, $payment_details, $response);

        // check if invoice has been marked paid
        if($status == INVOICE_STATUS_PAID){
            // update postpaid bill status
            PostpaidBills::where("payment_invoice_id", $invoice->id)
                ->update(["invoiced" => 1]);

            $status = true;
        } else{
            $status = false;
        }

        // prepare response
        $response   = [
            "status"            => $status,
            "message"           => $txn->message,
            "payment_details"   => $payment_details,
            "invoice_id"        => $invoice->id,
        ];

        return $response;
    }
}