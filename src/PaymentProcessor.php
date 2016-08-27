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
}