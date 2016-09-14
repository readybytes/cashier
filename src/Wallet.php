<?php
/**
 * Created by PhpStorm.
 * User: rbsl-neelam
 * Date: 25/8/16
 * Time: 10:36 AM
 */

namespace Laravel\Cashier;

use Illuminate\Database\Eloquent\Model;

class Wallet extends Model
{
    protected $table        = "wallet";

    protected $connection   = "vod-tenant";

    public static function updatePaymentDetails($user_id, $processor_type, $payment_data)
    {
        // update the payment details of user
        $wallet     = Wallet::where("user_id", $user_id)
            ->where("group_id", 0)
            ->first();

        if(!$wallet){
            $wallet = new Wallet();
            $wallet->user_id    = $user_id;
            $wallet->group_id   = 0;
        }
        $payment_details                    = json_decode($wallet->payment_details, true);
        $payment_details[$processor_type]   = $payment_data;
        $wallet->payment_details            = json_encode($payment_details);
        $wallet->save();
    }

    public static function updateWalletAfterRecharge($user_id, $group_id, $amount, $invoice_id)
    {
        $wallet = Wallet::where("user_id", $user_id)
            ->where("group_id", $group_id)
            ->first();

        // update wallet balance
        $wallet->balance    = $wallet->balance + $amount;

        // update invoice_ids
        $invoice_ids        = self::getRechargeHistory($wallet);

        if(!in_array($invoice_id, $invoice_ids)){
            $invoice_ids[]  = $invoice_id;
        }

        // update recharge_history
        $wallet->recharge_history   = implode(",", $invoice_ids);
        $wallet->save();
    }

    public static function getRechargeHistory($wallet)
    {
        if(str_contains($wallet->recharge_history, ",")){
            $invoice_ids    = explode(",", $wallet->recharge_history);
        } else{
            if($wallet->recharge_history){
                $invoice_ids[]  = $wallet->recharge_history;
            } else{
                $invoice_ids    = [];
            }
        }

        return $invoice_ids;
    }

    public static function getWallet($user_id, $group_id, $create = false)
    {
        $wallet         = Wallet::where("user_id", $user_id)
            ->where("group_id", $group_id)
            ->first();

        if(!$wallet && $create){
            $wallet             = new Wallet();
            $wallet->user_id    = $user_id;
            $wallet->group_id   = $group_id;
            $wallet->save();
        }

        return $wallet;
    }
}