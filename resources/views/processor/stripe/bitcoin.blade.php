<?php
/**
 * Created by PhpStorm.
 * User: rbsl-neelam
 * Date: 3/9/16
 * Time: 7:01 PM
 */
?>

@if(!Session::get("stripe_bitcoin_js_added_in_".$bitcoin_loader))
    <?php \Illuminate\Support\Facades\Session::put("stripe_bitcoin_js_added_in_".$bitcoin_loader, true);?>
    @include("vendor.cashier.processor.stripe.stripe_bitcoin_js")
@endif

<div class="uk-alert uk-alert-danger payment-errors" style="display: none;">
    <!-- to display errors returned by createToken -->
</div>

<div class="uk-form-row uk-margin-top" data-name="customer_email">
    <label>{{trans("front/user.bitcoin_email")}}</label>
    <div class="uk-form-controls">
        <input type="email" data-name="customer_email" name="customer_email" placeholder="Email" class="uk-form-width-medium">
    </div>
</div>

<input type='hidden' name='stripeToken' value='{{@$payment_details["token"]}}' />