<?php
/**
 * Created by PhpStorm.
 * User: rbsl-neelam
 * Date: 25/8/16
 * Time: 3:06 PM
 */

namespace Laravel\Cashier\Helpers;

class OfflineHelper
{
    protected   $processor;
    protected   $refund_support = false;
    const TYPE   = "offline";

    public static function prepareConfig($request)
    {
        $config = [];
        $config["title"]                = $request->get("title", "");
        $config["description"]          = $request->get("description", "");
        $config["bank_name"]            = $request->get("offline_bank_name", "");
        $config["account_number"]       = $request->get("offline_account_number", "");

        return $config;
    }

    public function isRefundSupported()
    {
        // NO, Offline transactions don't support refunds
        return $this->refund_support;
    }
}
