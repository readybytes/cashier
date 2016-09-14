<?php
/**
 * Created by PhpStorm.
 * User: rbsl-neelam
 * Date: 25/8/16
 * Time: 10:36 AM
 */

namespace Laravel\Cashier;

use App\vod\model\Collection;
use App\vod\model\Plan;
use App\vod\model\Tag;
use App\vod\model\Movie;
use App\vod\model\MovieTagMapping;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class Cart extends Model
{
    protected $table        = "cart";

    protected $connection   = "vod-tenant";

    public static function saveCart($id, $data)
    {
        if(!$id){
            $cart   = new Cart();
        } else{
            $cart   = Cart::find($id);
        }

        foreach($data as $key => $value){
            $cart->$key    = $value;
        }

        $cart->save();
    }

    public static function getUserCart($user)
    {
        if($user){
            // get the cart owned by user
            $cart   = Cart::where("user_id", $user->id)
                ->where("status", CART_STATUS_DRAFTED)
                ->first();
        } else{
            // get the cart of active session
            $session_id     = request()->session()->get("session_id");
            $cart   = Cart::where("session_id", $session_id)
                ->where("status", CART_STATUS_DRAFTED)
                ->first();
        }

        return $cart;
    }

    public static function addResourceToCart($plan_id, $user)
    {
        // prepare data for cart

        // get the cart owned by user
        $cart   = self::getUserCart($user);

        if(!$cart){
            $cart               = new Cart();
            $cart->user_id      = $user ? $user->id : 0;
            $cart->session_id   = $user ? 0 : time();
            $cart->status       = CART_STATUS_DRAFTED;
            $cart->params       = json_encode(["plans" => [$plan_id]]);

            // store the cart session_id in session also
            request()->session()->put('session_id', $cart->session_id);
        } else{
            $params         = json_decode($cart->params, true);

            // get the old plans to make sure that same resource is not added twice
            // if this is the case, we will automatically remove the previous plan of that resource & bind the new one

            $old_plans      = Plan::whereIn("id", array_values($params["plans"]))->get();
            if(!in_array($plan_id, array_values($params["plans"]))){
                $new_plan   = Plan::find($plan_id);

                $plan_added = false;

                foreach($old_plans as $old_plan){
                    if($new_plan->resource_type == $old_plan->resource_type
                        && $new_plan->resource_id == $old_plan->resource_id){

                        // remove the old plan of that resource associated with this cart
                        $key    = array_search($old_plan->id, $params["plans"]);
                        unset($params["plans"][$key]);

                        // add the new plan of this resource to the cart
                        $params["plans"][]  = $plan_id;
                        $plan_added         = true;
                    }
                }

                if(!$plan_added){
                    $params["plans"][]  = $plan_id;
                }
            }

            $cart->params   = json_encode($params);
        }

        $cart->save();

        // return the number of items in cart
        return self::getCartLength($cart);
    }

    // to delete any plan associated with any resource from the cart
    public function deleteResourceFromCart($plan_id)
    {
        $params = json_decode($this->params, true);

        // find the key of that plan to unset
        $key    = array_search($plan_id, $params["plans"]);
        unset($params["plans"][$key]);

        // update the params in cart
        $this->params   = json_encode($params);
        $this->save();

        // return the no of items present in cart
        return count($params["plans"]);
    }

    // to return the number of items present in cart
    public static function getCartLength($cart)
    {
        if($cart){
            $params = json_decode($cart->params, true);
            return count($params["plans"]);
        } else{
            return 0;
        }
    }

    // get the items of cart
    public function getCartItems()
    {
        $params     = json_decode($this->params, true);
        $plans      = $params["plans"];

        $items      = [];
        $total      = 0;

        foreach($plans as $plan_id){
            try{
                $item               = [];
                $item["plan_id"]    = $plan_id;

                $plan               = Plan::find($plan_id);
                $plan_details       = json_decode($plan->plan_details, true);

                if($plan->resource_type == RESOURCE_TYPE_MOVIE){
                    $resource   = Movie::find($plan->resource_id);
                    $item['link']       = route("tenant::front::movie::index", [config("vod.active_subdomain"), $resource->slug, $resource->id]);
                    $item['tags']       = MovieTagMapping::getResourceTags([$resource->id]);
                    $item['poster']     = asset('assets/image/'.config("vod.active_site").'/movie_banner/')."/{$resource->poster}";

                } else{
                    $resource   = Collection::find($plan->resource_id);
                    $item['link']       = route("tenant::front::collection::index", [config("vod.active_subdomain"), $resource->slug, $resource->id]);
                    $item['movie_ids']  = explode(",", $resource->collection_movie);
                    $item['tags']       = MovieTagMapping::getResourceTags($item['movie_ids']);
                    $item['poster']     = asset('assets/image/'.config("vod.active_site").'/collection_poster/')."/$resource->poster}";
                }

                $item['title']          = ucwords($resource->title);
                $item['tags']           = implode(",", $item["tags"]);
                $item['plan_type']      = $plan->plan_type == PLAN_TYPE_PURCHASE ? "Download" : "Rent";
                $item['duration']       = $plan_details["time"] . " " . $plan_details["time_unit"] . " Plan";
                $item['subtotal']       = $plan_details["amount"];

                $items[]    = $item;
                $total     += $item['subtotal'];
            } catch(\Exception $e){
                continue;
            }
        }

        return ["items" => $items, "total" => $total];
    }

    // apply discount
    public function applyDiscount($discount)
    {
        $params             = json_decode($this->params, true);
        $params["discount"] = $discount->id;

        $this->params       = json_encode($params);
        $this->save();
    }

    // remove discount
    public function removeDiscount()
    {
        $params             = json_decode($this->params, true);
        unset($params["discount"]);

        $this->params       = json_encode($params);
        $this->save();
    }

    // get cart total
    public function getCartTotal()
    {
        $params     = json_decode($this->params, true);
        $plans      = $params["plans"];
        $total      = 0;

        foreach($plans as $plan_id){
            try{
                $plan           = Plan::find($plan_id);
                $plan_details   = json_decode($plan->plan_details, true);
                $total         += $plan_details["amount"];
            } catch(\Exception $e){
                continue;
            }
        }

        if(isset($params["discount"])){
            $discount   = Discount::find($params["discount"]);
            if($discount){
                // apply discount only if the discount is not expired
                $current_date   = Carbon::now()->toDateTimeString();
                if($current_date >= $discount->start_date && $current_date <= $discount->end_date){
                    $total  = $total - (0.01 * $discount->discount_percent * $total);
                } else{
                    // do nothing
                }
            }
        }
        return $total;
    }

    // update cart status
    public function updateStatus($status, $group_id)
    {
        $this->status   = $status;
        $this->save();

        if($this->status == CART_STATUS_PAID){

            // create subscription for all plans
            $subscription_ids       = [];
            $params                 = json_decode($this->params, true);
            foreach($params["plans"] as $plan_id){
                $subscription_id    = Subscription::createSubscription($this->user_id, $plan_id, $group_id);
                $subscription_ids[] = $subscription_id;
            }

            $params["subscriptions"]    = $subscription_ids;
            $this->params               = json_encode($params);
            $this->save();
        }
    }
}