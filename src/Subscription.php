<?php
/**
 * Created by PhpStorm.
 * User: rbsl-neelam
 * Date: 25/8/16
 * Time: 10:36 AM
 */

namespace Laravel\Cashier;

use App\Events\SubscriptionCreated;
use App\vod\model\Plan;
use App\vod\model\ResourceAllocator;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;

class Subscription extends Model
{
    protected $table        = "subscription";

    protected $connection   = "vod-tenant";

    public static function createSubscription($user_id, $plan_id, $group_id)
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

        Event::fire(new SubscriptionCreated($subscription, $group_id));

        return $subscription->id;
    }

    public function updateStatus($status)
    {
        $this->status   = $status;
        $this->save();
    }

    public static function getSubscriptionStatus($movie_id)
    {
        // get the subscription id associated with logged in user for this movie
        $resource_allocator     = ResourceAllocator::where("user_id", Auth::user()->id)
            ->where("movie_id", $movie_id)
            ->first();

        if($resource_allocator){
            $subscription           = Subscription::find($resource_allocator->subscription_id);

            // check expiration; if the movie is expired
            if($subscription->expiration_date < Carbon::now()->toDateTimeString()){

                // mark its status as expired here itself
                if($subscription->status != SUBSCRIPTION_STATUS_EXPIRED){
                    $subscription->status   = SUBSCRIPTION_STATUS_EXPIRED;
                    $subscription->save();
                }
            }

            return $subscription->status;
        } else{
            return false;
        }
    }
} 