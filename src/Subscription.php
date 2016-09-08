<?php
/**
 * Created by PhpStorm.
 * User: rbsl-neelam
 * Date: 25/8/16
 * Time: 10:36 AM
 */

namespace Laravel\Cashier;

use App\vod\model\Plan;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $table        = "subscription";

    protected $connection   = "vod-tenant";

    public static function createSubscription($user_id, $plan_id)
    {
        $subscription                       = new Subscription();

        $subscription->user_id              = $user_id;
        $subscription->plan_id              = $plan_id;
        $subscription->status               = SUBSCRIPTION_STATUS_ACTIVE;

        $start_date = Carbon::now();
        $subscription->subscription_date    = $start_date->toDateTimeString();

        $plan               = Plan::find($plan_id);
        $plan_details       = json_decode($plan->plan_details, true);

        $func               = "add".ucfirst($plan_details["time_unit"])."s";
        $expiration_date    = $start_date->$func($plan_details["time"]);

        $subscription->expiration_date      = $expiration_date->toDateTimeString();

        $subscription->save();

        return $subscription->id;
    }

    public function updateStatus($status)
    {
        $this->status   = $status;
        $this->save();
    }
}