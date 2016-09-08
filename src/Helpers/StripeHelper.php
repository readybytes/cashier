<?php
/**
 * Created by PhpStorm.
 * User: rbsl-neelam
 * Date: 25/8/16
 * Time: 3:06 PM
 */

namespace Laravel\Cashier\Helpers;

class StripeHelper
{
    public static function prepareConfig($request)
    {
        $config = [];
        $config["title"]                = $request->get("title", "");
        $config["description"]          = $request->get("description", "");
        $config["test_secret_key"]      = $request->get("stripe_test_secret_key", "");
        $config["test_publishable_key"] = $request->get("stripe_test_publishable_key", "");
        $config["live_secret_key"]      = $request->get("stripe_live_secret_key", "");
        $config["live_publishable_key"] = $request->get("stripe_live_publishable_key", "");
        $config["account"]              = $request->get("stripe_account", "");

        return $config;
    }
}
