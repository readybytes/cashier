<?php
/**
 * Created by PhpStorm.
 * User: rbsl-neelam
 * Date: 25/8/16
 * Time: 3:06 PM
 */

namespace Laravel\Cashier\Helpers;

use App\User;
use App\vod\model\Group;
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

    public function processTransaction($invoice, $wallet, $txn, $group, $g_member)
    {
        // make payment
        try {
            $wallet->balance         = $wallet->balance - $invoice->total;
            $wallet->consumed_amount = $wallet->consumed_amount + $invoice->total;
            $wallet->save();

            // update group member wallet if user is using group wallet
            if($group){
                $g_member_array = array_values(json_decode($group->members, TRUE));
                for($i = 0; $i < count($g_member_array); $i++){

                    if($g_member->email == $g_member_array[$i]['email']){
                        $members                   = [
                            'email'                => $g_member_array[$i]['email'],
                            'limit'                => $g_member_array[$i]['limit'] - $invoice->total,
                            'is_admin'             => $g_member_array[$i]['is_admin'],
                        ];

                        unset($g_member_array[$i]);
                        $g_member_array[$i] = $members;
                    }
                }
                $group->update(['members' => json_encode($g_member_array)]);
            }


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
