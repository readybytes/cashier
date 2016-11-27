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
        $config["title"]            = $request->get("title", "");
        $config["description"]      = $request->get("description", "");
        $config["paypal_email"]     = $request->get("paypal_email", "");
        $config["live_account"]     = $request->get("paypal_account", "");

        return $config;
    }

    public function __validate_ipn(Array $data)
    {
        $processor  =  PaymentProcessor::where('processor_type','paypal')->first();

        $config     = json_decode($processor->processor_config, true);

        if($config["live_account"]){
            $paypal_url  = "https://www.paypal.com/cgi-bin/webscr";
        } else{
            $paypal_url  = "https://www.sandbox.paypal.com/cgi-bin/webscr";
        }

        // STEP 1: read POST data
        // Reading POSTed data directly from $_POST causes serialization issues with array data in the POST.
        // Instead, read raw POST data from the input stream.

        // read the IPN message sent from PayPal and prepend 'cmd=_notify-validate'
        $req = 'cmd=_notify-validate';

        foreach ($data as $key => $value) {
            if($key != "cmd"){
                $value = urlencode(stripslashes($value));
                $req .= "&$key=$value";
            }
        }

        // Step 2: POST IPN data back to PayPal to validate
        $ch = curl_init($paypal_url);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $req);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_FORBID_REUSE, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Connection: Close'));


        if ( !($res = curl_exec($ch)) ) {
            // error_log("Got " . curl_error($ch) . " when processing IPN data");
            curl_close($ch);
            exit;
        }
        curl_close($ch);

        // inspect IPN validation result and act accordingly
        if (strcmp ($res, "VERIFIED") == 0) {
            return true;
        } else if (strcmp ($res, "INVALID") == 0) {
            return false;
        }
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
            $status     = $this->processTransaction($invoice, $txn, $payment_details);

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

    public function processTransaction($invoice, $txn, $payment_details)
    {
        // make payment
        try{
            if($payment_details["status"] == "Completed"){
                $params                 = json_decode($txn->params, true);
                $params["txn_details"]  = $payment_details["gateway_txn_id"];

                // update the payment related details in transaction
                $txn->update([
                    "gateway_txn_id"    => $payment_details["gateway_txn_id"],
                    "params"            => json_encode($params),
                ]);

                // update the transaction status
                $txn->updateStatus(TRANSACTION_STATUS_PAYMENT_COMPLETE);

                // mark the invoice paid
                $invoice->markPaid();
            } else{
                throw new \Exception($payment_details["message"]);
            }

        } catch(\Exception $e){
            $txn->message   = "The source you provided is not in a chargeable state.";
            $txn->updateStatus(TRANSACTION_STATUS_PAYMENT_FAILED);
        }

        return $invoice->status;
    }

    protected function _prepareNvpConfig($gateway_txn_id)
    {
        $version      = phpversion();
        $requestNvp   = [
            'USER'              => "testmerchant_api1.readybytes.in",
            'PWD'               => "1395814319",
            'SIGNATURE'         => "AFcWxV21C7fd0v3bYYYRCpSSRl31Ax2CHjAKHSvw.VmDYUDsAB6uLGkR",
            'VERSION'           => $version,
            'METHOD'            => 'RefundTransaction',
            'TRANSACTIONID'     => $gateway_txn_id,
            'REFUNDTYPE'        => 'Full'

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
            $processor      = PaymentProcessor::where('id',$txn->processor_id)->first();
            $params         = json_decode($processor->processor_config, true);

            if($params["live_account"]) {
                $sandbox = false;
            }

            $apiEndpoint  = 'https://api-3t.' . ($sandbox? 'sandbox.': null);
            $apiEndpoint .= 'paypal.com/nvp';

            $requestNvp   = $this->_prepareNvpConfig($txn->gateway_txn_id);

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

            if (isset($response['ACK']) == 'Success') {

                $params                     = json_decode($txn->params, true);
                $params["refund_details"]   = $response["L_SHORTMESSAGE0"];

                // update the payment related details in transaction
                $txn->update([
                    "params"            => json_encode($params),
                ]);

                // update the transaction status
                $txn->updateStatus(TRANSACTION_STATUS_PAYMENT_REFUND);

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

        // make payment
        $status     = $this->processTransaction($invoice, $txn, $payment_details);

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

        $processor  = PaymentProcessor::where('id',$txn->processor_id)->first();

        // make payment
        $status     = $this->processTransaction($invoice, $txn, $payment_details);

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
            "resource"          =>  $resource,
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

        $status     = $this->processTransaction($invoice, $txn, $payment_details);

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