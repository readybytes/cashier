<?php
/**
 * Created by PhpStorm.
 * User: rbsl-neelam
 * Date: 25/8/16
 * Time: 3:06 PM
 */

namespace Laravel\Cashier\Helpers;

use App\Listeners\RevenueSplitter;
use App\vod\model\ResourceAllocated;
use App\User;
use App\vod\model\Group;
use App\vod\model\UserGroup;
use Illuminate\Support\Facades\Event;
use Laravel\Cashier\Invoice;
use Laravel\Cashier\PostpaidBills;
use Laravel\Cashier\Transaction;
use Laravel\Cashier\Wallet;
use Laravel\Cashier\WalletHistory;

class WalletHelper
{
    protected $refund_support   = true;

    public function payCart($request, $user, $cart)
    {
        try{
            // get the amount to be paid
            $amount = $request->get("amount");

            // get the group_id of wallet used
            $group_id = $request->get("vod-payment-wallet-gid");

            // get the wallet to be used for transaction
            $wallet = Wallet::where("user_id", $user->id)
                ->where("group_id", $group_id)
                ->first();

            // get the group member detail who used wallet
            $group         = UserGroup::where("id", "=", $group_id)->first();

            $user_obj      = new User(null, env("DB_TENANT_CONNECTION"));

            $g_member      = $user_obj->where("id", $wallet->user_id)->first();

            // payment_details
            $payment_details                = json_decode($wallet->payment_details, true);
            $payment_details["wallet_type"] = $group_id == NO_GROUP ? "self" : "group";
            $payment_details["group_id"]    = $group_id;

            // create invoice
            $invoice = Invoice::createInvoice($user, $amount, false, $cart->id);

            // create transaction
            $txn = $invoice->createTransaction(PROCESSOR_WALLET, $payment_details);

            // make payment
            $status = $this->processTransaction($invoice, $wallet, $txn, $group, $g_member);

            if ($status == INVOICE_STATUS_PAID) {
                // update Cart
                $cart->updateStatus(CART_STATUS_PAID, $group_id, $txn);
            }

            return [
                "status"     => true,
                "invoice_id" => $invoice->id,
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

            return [
                "status"  => false,
                "message" => $e->getMessage(),
            ];

            // TODO::Log the exception properly
        }
    }

    public function payForTokenBasedUrl($request, $user, $resource_data)
    {
        try{
            // get the amount to be paid
            $amount     = $request->get("amount");

            // get the group_id of wallet used
            $group_id = $request->get("vod-payment-wallet-gid");

            // get the wallet to be used for transaction
            $wallet = Wallet::where("user_id", $user->id)
                ->where("group_id", $group_id)
                ->first();

            // get the group member detail who used wallet
            $group         = UserGroup::where("id", "=", $group_id)->first();

            $user_obj      = new User(null, env("DB_TENANT_CONNECTION"));

            $g_member      = $user_obj->where("id", $wallet->user_id)->first();

            // payment_details
            $payment_details                = json_decode($wallet->payment_details, true);
            $payment_details["wallet_type"] = $group_id == NO_GROUP ? "self" : "group";
            $payment_details["group_id"]    = $group_id;

            // create invoice
            $invoice    = Invoice::createInvoice($user, $amount, false, 0, "Url Sharing for ".ucwords($resource_data["movie_title"]));

            // create transaction
            $txn = $invoice->createTransaction(PROCESSOR_WALLET, $payment_details);

            // make payment
            $status = $this->processTransaction($invoice, $wallet, $txn, $group, $g_member);

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
                "invoice_id"        => $invoice->id,
                "resource"          => $resource,
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

    public function payForPostpaidBill($request, $user, $invoice_id)
    {
        try{

            // get the wallet to be used for transaction
            $wallet = Wallet::where("user_id", $user->id)
                ->where("group_id", NO_GROUP)
                ->first();


            // payment_details
            $payment_details                = json_decode($wallet->payment_details, true);
            $payment_details["wallet_type"] = "self";
            $payment_details["group_id"]    = 0;

            // get invoice
            $invoice = Invoice::find($invoice_id);

            // create transaction
            $txn = $invoice->createTransaction(PROCESSOR_WALLET, $payment_details);

            // make payment
            $status = $this->processTransaction($invoice, $wallet, $txn, null, null);

            if ($status == INVOICE_STATUS_PAID) {
                // update postpaid bill status
                PostpaidBills::where("payment_invoice_id", $invoice->id)
                    ->update(["invoiced" => 1]);
            }

            return [
                "status"     => true,
                "invoice_id" => $invoice->id,
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

            return [
                "status"  => false,
                "message" => $e->getMessage(),
            ];

            // TODO::Log the exception properly
        }
    }

    public function processTransaction($invoice, $wallet, $txn, $group, $g_member)
    {
        // make payment
        try {
            $wallet->balance         = $wallet->balance - $invoice->total;
            $wallet->consumed_amount = $wallet->consumed_amount + $invoice->total;
            $wallet->save();

            $txn->updateStatus(TRANSACTION_STATUS_PAYMENT_COMPLETE);
            $invoice->markPaid();

            // find out if its prepaid purchase or postpaid purchase
            $g_wallet   = $group ? Wallet::getWallet(0, $group->id) : null;

            // add this txn in wallet history
            WalletHistory::addWalletHistory([
                "wallet_id"             => $wallet->id,
                "txn_type"              => $g_wallet && $g_wallet->credits_limit ? WALLET_TXN_FOR_POSTPAID_PURCHASE : WALLET_TXN_FOR_PREPAID_PURCHASE,
                "amount"                => -1 * $invoice->total, // should be negative
                "billed"                => $g_wallet && $g_wallet->credits_limit ? false : true,
                "payment_invoice_id"    => $invoice->id
            ]);
        } catch (\Exception $e) {
            $txn->updateStatus(TRANSACTION_STATUS_PAYMENT_FAILED);
        }

        return $invoice->status;
    }

    public function processRefund($txn, $wallet, $group_id)
    {
        // make payment
        try {

            $wallet->balance            = $wallet->balance + $txn->amount;
            $wallet->consumed_amount    = $wallet->consumed_amount - $txn->amount;
            $wallet->save();

            // get the group wallet
            $g_wallet   = Wallet::getWallet(0, $group_id);

            // add this txn in wallet history
            WalletHistory::addWalletHistory([
                "wallet_id"             => $wallet->id,
                "txn_type"              => WALLET_TXN_FOR_REFUND,
                "amount"                => $txn->amount, // should be positive
                "billed"                => $g_wallet->credits_limit ? false : true,
                "payment_invoice_id"    => $txn->invoice_id
            ]);
        } catch (\Exception $e) {
            return [
                "status"    => false,
                "message"   => $e->getMessage(),
            ];
        }

        return ["status" => true];
    }

    public function getUserPaymentDetails($user_id, $for_checkout = false)
    {
        // we don't need to display any payment details for the user for allowing payments through wallet
        return [];
    }

    // return the possible gateway transaction fees if this amount had been paid through Stripe
    public static function getTransactionFees($processor, $amount)
    {
        // when payment is done through wallet no extra fees would be deducted afterwards
        return 0;
    }

    public function isRefundSupported()
    {
        return $this->refund_support;
    }

    public function requestRefund($txn, $invoice, $delete_txn = false)
    {
        // make refund
        try{
            // get the wallet details
            $txn_params = json_decode($txn->params, true);
            $wallet     = Wallet::getWallet($txn->user_id, $txn_params["payment_details"]["group_id"]);

            // create a txn for refund
            $refund_txn = Transaction::createTransactionForRefund($txn);

            $response   = $this->processRefund($txn, $wallet, $txn_params["payment_details"]["group_id"]);

            if($response["status"]){
                // update the transaction status
                $refund_txn->updateStatus(TRANSACTION_STATUS_PAYMENT_REFUND);

                // mark the invoice refunded
                $invoice->markRefunded();

                // delete txn if needed - in case its called to handle exception
                if($delete_txn){
                    $refund_txn->delete();
                }
            } else{
                throw new \Exception($response["message"]);
            }

        } catch(\Exception $e){
            if(isset($refund_txn)){
                $refund_txn->delete();
            }
        }

        $status = $invoice->status == INVOICE_STATUS_REFUNDED ? true : false;
        return $status;
    }
}
