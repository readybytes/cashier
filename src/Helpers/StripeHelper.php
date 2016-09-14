<?php
/**
 * Created by PhpStorm.
 * User: rbsl-neelam
 * Date: 25/8/16
 * Time: 3:06 PM
 */

namespace Laravel\Cashier\Helpers;

use Carbon\Carbon;
use Laravel\Cashier\Invoice;
use Laravel\Cashier\PaymentProcessor;
use Laravel\Cashier\Wallet;
use Stripe\BalanceTransaction;
use Stripe\Card;
use Stripe\Charge;
use Stripe\Customer;
use Stripe\Stripe;

class StripeHelper
{
    protected   $processor;
    const TYPE   = "stripe";

    public function __construct()
    {
        $this->processor    = PaymentProcessor::where("processor_type", self::TYPE)->first();

        // set stripe key
        $this->__setStripeKey();
    }

    private function __preparePaymentDetails($user, $request)
    {
        // get the token
        $token      = $request->get("stripeToken");

        // get the payment option
        $option     = $request->get("stripe-payment-option", "new");

        // get the payment details stored with us
        $card_data  = $this->getUserPaymentDetails($user->id, true);

        // cases:
        // 1. We don't have any saved card's details
        // 1. User chooses to pay from saved card
        // 2. User chooses to pay from new card but not save it
        // 3. User chooses to pay from new card & update the same

        if(!$card_data){
            $card_data["token"]         = $token;
            $card_data["customer_id"]   = $this->__createProfile($token, $user->email);
        } else{
            // if user wants to update the card details
            $save_payment_details    = $request->get("save_payment_details");

            if($option == "new"){
                if($save_payment_details == "on"){
                    $customer_id         = $card_data["customer_id"];
                    $this->__updateCardDetails($customer_id, $token);
                } else{
                    $card_data["token"]         = $token;
                }
            } else{
                // do nothing as user has opted to use existing saved card
            }
        }

        return $card_data;
    }

    private function __setStripeKey()
    {
        $config = json_decode($this->processor->processor_config, true);
        if($config["live_account"]){
            $key    = $config["live_secret_key"];
        } else{
            $key    = $config["test_secret_key"];
        }

        // set the API Key
        Stripe::setApiKey($key);
    }

    private function __createProfile($token, $email)
    {
        // create customer at stripe
        $customer   = Customer::create([
                "source"        => $token,
                "description"   => $email . " at " . Carbon::now()->toDateTimeString(),
        ]);

        $last_response  = $customer->getLastResponse()->json;
        $customer_id    = $last_response["id"];

        return $customer_id;
    }

    private function __updateCardDetails($customer_id, $token)
    {
        // get the customer
        $customer   = Customer::retrieve($customer_id);

        // we will not delete the previously saved cards, we will just mark this new card as default source
        $card       = $customer->sources->create([
            "source"    => $token,
        ]);

        // mark the added card as default source
        $customer->default_source   = $card->id;
        $customer->save();
    }

    private function __updateExistingCard($customer_id, $exp_month, $exp_year)
    {
        // get the customer
        $customer   = Customer::retrieve($customer_id);

        // get the saved card id
        $card_id    = $customer->default_source;

        $card               = $customer->sources->retrieve($card_id);
        $card->exp_month    = $exp_month;
        $card->exp_year     = $exp_year;
        $card->save();
    }

    private function __request_payment($payment_details, $amount, $currency)
    {
        $data   = [];

        $data["amount"]         = $amount * 100; // amount in cents
        $data["currency"]       = strtolower($currency);
        if(isset($payment_details["token"])){
            $data["source"]     = $payment_details["token"];
        } else{
            $data["customer"]   = $payment_details["customer_id"];
        }

        try{
            $charge = Charge::create($data);

            // prepare response
            $last_response                  = $charge->getLastResponse();
            $last_response_json             = $last_response->json;

            // status code
            $response["status_code"]        = $last_response->code;
            $response["status"]             = $last_response_json["status"];

            $response["data"]["response"]   = $last_response_json;
            $response["gateway_txn_id"]     = $last_response_json["balance_transaction"];

            // get the processing fees of Stripe
            $txn            = BalanceTransaction::retrieve($response["gateway_txn_id"]);
            $txn_response   = $txn->getLastResponse()->json;

            $response["gateway_txn_fees"]       = $txn_response["fee"];
            $response["data"]["balance_txn"]    = $txn_response;
        } catch(\Exception $e){
            $response["status"]             = "error";
            $response["status_code"]        = 0;
            $response["message"]            = $e->getMessage();
        }

        return $response;
    }

    public static function prepareConfig($request)
    {
        $config = [];
        $config["title"]                = $request->get("title", "");
        $config["description"]          = $request->get("description", "");
        $config["test_secret_key"]      = $request->get("stripe_test_secret_key", "");
        $config["test_publishable_key"] = $request->get("stripe_test_publishable_key", "");
        $config["live_secret_key"]      = $request->get("stripe_live_secret_key", "");
        $config["live_publishable_key"] = $request->get("stripe_live_publishable_key", "");
        $config["live_account"]         = $request->get("stripe_account", "");

        return $config;
    }

    public function getUserPaymentDetails($user_id, $for_payment = false)
    {
        $wallet = Wallet::where("user_id", $user_id)
            ->where("group_id", 0)
            ->first();

        $card_details   = [];

        if($wallet){
            $payment_details    = json_decode($wallet->payment_details, true);
            if(isset($payment_details[$this->processor->processor_type])){
                $stripe_details = $payment_details[$this->processor->processor_type];
                $customer_id    = $stripe_details["customer_id"];

                // set customer_id in card_details
                $card_details["customer_id"] = $stripe_details["customer_id"];

                // if we are here just to get the customer id for making payment, then return now
                if($for_payment){
                    return $card_details;
                }

                // if we need to display the saved payment details, then
                // we should retrieve the default source and get the payment details
                try{
                    $customer_response  = Customer::retrieve($customer_id);
                    $data               = $customer_response->getLastResponse()->json;
                    $card               = $data["sources"]["data"][0];

                    $card_details["number"]      = "************".$card["last4"];
                    $card_details["exp_month"]   = $card["exp_month"];
                    $card_details["exp_year"]    = $card["exp_year"];
                } catch(\Exception $e){
                    $card_details   = [];
                }
            }
        }

        return $card_details;
    }

    public function payCart($request, $user, $cart)
    {
        // get the amount to be paid
        $amount     = $request->get("amount");

        // create invoice
        $invoice    = Invoice::createInvoice($user, $amount, $cart->id);

        // payment details
        $payment_details    = $this->__preparePaymentDetails($user, $request);

        // create transaction
        $txn        = $invoice->createTransaction($this->processor->id, $payment_details);

        // make payment
        $status     = $this->processTransaction($invoice, $payment_details, $txn);

        // check if invoice has been marked paid
        if($status == INVOICE_STATUS_PAID){
            // update Cart
            $cart->updateStatus(CART_STATUS_PAID, NO_GROUP);
            $status = true;
        } else{
            $status = false;
        }

        // prepare response
        $response   = [
            "status"            => $status,
            "message"           => $txn->message,
            "payment_details"   => $payment_details,
        ];

        return $response;
    }

    public function processTransaction($invoice, $payment_details, $txn)
    {
        // make payment
        try{
            $response   = $this->__request_payment($payment_details, $txn->amount, $txn->currency);

            if($response["status_code"] == 200 && $response["status"] == "succeeded"){
                $params                 = json_decode($txn->params, true);
                $params["txn_details"]  = $response["data"];

                // update the payment related details in transaction
                $txn->update([
                    "gateway_txn_id"    => $response["gateway_txn_id"],
                    "gateway_txn_fees"  => $response["gateway_txn_fees"],
                    "params"            => json_encode($params),
                ]);

                // update the transaction status
                $txn->updateStatus(TRANSACTION_STATUS_PAYMENT_COMPLETE);

                // mark the invoice paid
                $invoice->markPaid($txn);
            } else{
                throw new \Exception($response["message"]);
            }

        } catch(\Exception $e){
            $txn->message   = $e->getMessage();
            $txn->updateStatus(TRANSACTION_STATUS_PAYMENT_FAILED);
        }

        return $invoice->status;
    }

    public function updateCardDetails($request, $email)
    {
        try{
            $customer_id    = $request->get("customer_id");
            $token          = $request->get("stripeToken");

            if($customer_id){
                $card_type      = $request->get("stripe-payment-option", 1);
                if($card_type == "new"){
                    $this->__updateCardDetails($customer_id, $token);
                } else{
                    $exp_month  = $request->get("exp_month");
                    $exp_year   = $request->get("exp_year");

                    $this->__updateExistingCard($customer_id, $exp_month, $exp_year);
                }
            } else{
                // user is adding his card for the first time
                $customer_id    = $this->__createProfile($token, $email);
            }

            return ["status" => true, "payment_details" => ["customer_id" => $customer_id]];
        } catch (\Exception $e){
            return ["status" => false];
        }
    }

    public function rechargeWallet($request, $user)
    {
        // get the amount to be paid
        $amount     = $request->get("amount");

        // get the group_id
        $group_id   = $request->get("group_id");

        // create invoice
        $invoice    = Invoice::createInvoice($user, $amount, 0, "Wallet Recharge");

        // payment details
        $payment_details    = $this->__preparePaymentDetails($user, $request);

        // create transaction
        $txn        = $invoice->createTransaction($this->processor->id, $payment_details);

        // make payment
        $status     = $this->processTransaction($invoice, $payment_details, $txn);

        // check if invoice has been marked paid
        if($status == INVOICE_STATUS_PAID){
            // update Wallet Balance
            Wallet::updateWalletAfterRecharge($user->id, $group_id, $amount, $invoice->id);
            $status = true;
        } else{
            $status = false;
        }

        // prepare response
        $response   = [
            "status"            => $status,
            "message"           => $txn->message,
            "payment_details"   => $payment_details,
        ];

        return $response;
    }
}