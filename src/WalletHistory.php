<?php
/**
 * Created by PhpStorm.
 * User: rbsl-neelam
 * Date: 25/8/16
 * Time: 10:36 AM
 */

namespace Laravel\Cashier;

use Illuminate\Database\Eloquent\Model;

class WalletHistory extends Model
{
    protected $table        = "wallet_history";

    protected $connection   = "vod-tenant";

    public static function addWalletHistory($data)
    {
        $wallet_history     = new WalletHistory();

        foreach ($data as $key => $value) {
            $wallet_history->$key   = $value;
        }

        $wallet_history->save();
    }

    public static function carryForwardBalance($g_wallet_id, $carry_forward_amount)
    {
        $carry_forward_entry    = new WalletHistory();

        $carry_forward_entry->wallet_id = $g_wallet_id;
        $carry_forward_entry->txn_type  = WALLET_TXN_TO_CARRY_FORWARD;
        $carry_forward_entry->amount    = $carry_forward_amount;
        $carry_forward_entry->billed    = 0;

        $carry_forward_entry->save();
    }
}