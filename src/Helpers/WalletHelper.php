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
use Laravel\Cashier\Wallet;

class WalletHelper
{
    protected $refund_support   = false;

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
            $group         = Group::where("id", "=", $group_id)->first();

            $user_obj      = new User(null, env("DB_TENANT_CONNECTION"));

            $g_member      = $user_obj->where("id", $wallet->user_id)->first();

            // payment_details
            $payment_details                = json_decode($wallet->payment_details, true);
            $payment_details["wallet_type"] = $group_id == NO_GROUP ? "self" : "group";
            $payment_details["group_id"]    = $group_id;

            // create invoice
            $invoice = Invoice::createInvoice($user, $amount, $cart->id);

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
            return [
                "status"  => false,
                "message" => $e->getMessage(),
            ];
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
            $invoice    = Invoice::createInvoice($user, $amount, 0, "Url Sharing for ".ucwords($resource_data["movie_title"]));

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
            // prepare response
            $response   = [
                "status"            => false,
                "message"           => $e->getMessage(),
            ];
        }

        return $response;
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
        } catch (\Exception $e) {
            $txn->updateStatus(TRANSACTION_STATUS_PAYMENT_FAILED);
        }

        return $invoice->status;
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
}
