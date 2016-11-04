<?php
/**
 * Created by PhpStorm.
 * User: rbsl-neelam
 * Date: 25/8/16
 * Time: 10:36 AM
 */

namespace Laravel\Cashier;

use Illuminate\Database\Eloquent\Model;

class PostpaidBills extends Model
{
    protected $table        = "postpaid_bills";

    protected $connection   = "vod-tenant";
    
}