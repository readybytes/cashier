<?php
/**
 * Created by PhpStorm.
 * User: rbsl-neelam
 * Date: 25/8/16
 * Time: 10:36 AM
 */

namespace Laravel\Cashier;

use Illuminate\Database\Eloquent\Model;

class PaymentProcessor extends Model
{
    protected $table        = "payment_processor";

    protected $connection   = "vod-tenant";

    protected $_permits = null;

    protected $guarded = array();

    public function checkPermission($object, $action, $permissions)
    {
        if($permissions == 1){
            return true;
        }
        else{
            if($this->_permits == null){
                $this->_permits = explode(',', $permissions);
            }

            $check = strtolower("$object.$action");

            return in_array($check, $this->_permits);
        }
    }


    public function checkAdmin($scope)
    {
        //admin
        if($scope == ADMIN || $scope == SITE_ADMIN || $scope == SUPER_ADMIN )
        {
            return true;
        }
    }

    public static function saveProcessor($id, $data)
    {
        if(!$id){
            $processor  = new PaymentProcessor();
        } else{
            $processor  = PaymentProcessor::find($id);
        }

        foreach($data as $key => $value){
            $processor->$key    = $value;
        }

        $processor->save();
    }

    public static function getPaymentProcessors()
    {
        // get the processor details
        $processor        = PaymentProcessor::where("published", 1)
            ->whereNotIn("processor_type", ["offline", "paypal", "wallet"])
            ->first();

        return $processor;
    }

    public static function getOfflinePaymentProcessor()
    {
        // get the offline payment processor

        $processor  = PaymentProcessor::where("processor_type", "offline")
            ->where("published", 1)
            ->first();

        return $processor;
    }

    public static function getOnlinePaymentProcessors()
    {
        // get the processors
        $processors        = PaymentProcessor::where("published", 1)
            ->whereNotIn("processor_type", ["offline", "wallet"])
            ->get();

        return $processors;
    }
}