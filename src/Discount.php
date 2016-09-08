<?php
/**
 * Created by PhpStorm.
 * User: rbsl-neelam
 * Date: 25/8/16
 * Time: 10:36 AM
 */

namespace Laravel\Cashier;

use Illuminate\Database\Eloquent\Model;

class Discount extends Model
{
    protected $table        = "discount";

    protected $connection   = "vod-tenant";

    protected $fillable = [
        'title', 'coupon_code', 'discount_percent', 'start_date', 'end_date', 'published', 'params'
    ];

    protected $_permits = null;

    public static function getDiscountList()
    {
        $value  = Discount::orderBy('id', 'asc')->paginate(10);
        return $value;
    }

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

    public function saveData($id, $data)
    {
        if($id){
            $discount   = Discount::find($id);
        } else{
            $discount   = new Discount();
        }

        foreach($data as $key => $value){
            $discount->$key = $value;
        }

        $discount->save();
    }
}