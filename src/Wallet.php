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

        if(!$wallet){
            $wallet = new Wallet();
            $wallet->group_id   = $group_id;
            $wallet->user_id    = $user_id;
            $wallet->balance    = 0;
        }

        // update wallet balance
        $wallet->balance    = $wallet->balance + $amount;

        // add this txn in wallet history
        WalletHistory::addWalletHistory([
            "wallet_id"             => $wallet->id,
            "txn_type"              => WALLET_TXN_FOR_RECHARGE,
            "amount"                => $amount, // should be positive
            "billed"                => $wallet->credits_limit ? false : true,
            "payment_invoice_id"    => $invoice_id
        ]);

        $wallet->save();
    }

    public function getRechargeHistory()
    {
        $invoice_ids    = WalletHistory::where("wallet_id", $this->id)
            ->where("txn_type", WALLET_TXN_FOR_RECHARGE)
            ->pluck("payment_invoice_id");

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