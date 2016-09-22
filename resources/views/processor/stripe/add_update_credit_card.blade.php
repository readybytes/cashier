<?php
/**
 * Created by PhpStorm.
 * User: rbsl-neelam
 * Date: 3/9/16
 * Time: 7:01 PM
 */

$processor  = \Laravel\Cashier\PaymentProcessor::where("processor_type", "stripe")->first();
$config     = json_decode($processor->processor_config, true);

if($config["live_account"]){
    $p_key  = $config["live_publishable_key"];
} else{
    $p_key  = $config["test_publishable_key"];
}

?>
@if(!isset($stripe_js_added))
    <?php $stripe_js_added = true;?>
    @include("vendor.cashier.processor.stripe.stripe_js")
@endif

<script type="text/javascript">
    $(document).ready(function () {
        $("input[name='stripe-payment-option-update']").change(function () {
            var new_card    = $(this).data("new-card");
            if(new_card == 1){
                $("input[data-name='number']").val("").prop("readonly", false);
                $("select[data-name='exp_month']").val("");
                $("select[data-name='exp_year']").val("");
                $("input[data-name='cvc']").val("");
                $("div[data-name='cvc_block']").show();
                $("div[data-name='save_payment_details']").show();
            } else{
                $("input[data-name='number']").val("{{@$payment_details["number"]}}").prop("readonly", true);
                $("select[data-name='exp_month']").val("{{@$payment_details["exp_month"]}}");
                $("select[data-name='exp_year']").val("{{@$payment_details["exp_year"]}}");
                $("div[data-name='cvc_block']").hide();
                $("div[data-name='save_payment_details']").hide();
            }
        });
    });
</script>

<div class="uk-alert uk-alert-danger payment-errors" style="display: none;">
    <!-- to display errors returned by createToken -->
</div>

@if(count($payment_details))
    <div class="uk-alert uk-padding-remove">
        <div class="uk-form-controls">
            <label class="uk-margin-large-right"><input type="radio" value="old" data-new-card="0" name="stripe-payment-option-update" checked> Update Saved Card</label>
            <label class="uk-margin-large-left"><input type="radio" value="new" data-new-card="1" name="stripe-payment-option-update"> Add New Card</label>
        </div>
    </div>
@endif

<div class="uk-form-row">
    <label class="uk-form-label uk-h5">Card Number</label>
    <div class="uk-form-controls">
        <input type="text" data-name="number" value="{{@$payment_details["number"]}}" placeholder="Card Number" class="uk-form-width-medium" @if(count($payment_details)) readonly @endif>
    </div>
</div>
<div class="uk-form-row uk-margin-top">
    <label class="uk-form-label uk-h5">Expiry Month / Expiry Year</label>
    <div class="uk-form-controls">
        <select name="exp_month" data-name="exp_month" class="vod-processor-select" id="payment-processor-stripe-card-expiry-month">
            <option value="" selected="selected" disabled>MM </option>
            <option value="1" @if(@$payment_details["exp_month"] == "1") selected @endif>January</option>
            <option value="2" @if(@$payment_details["exp_month"] == "2") selected @endif>February</option>
            <option value="3" @if(@$payment_details["exp_month"] == "3") selected @endif>March</option>
            <option value="4" @if(@$payment_details["exp_month"] == "4") selected @endif>April</option>
            <option value="5" @if(@$payment_details["exp_month"] == "5") selected @endif>May</option>
            <option value="6" @if(@$payment_details["exp_month"] == "6") selected @endif>June</option>
            <option value="7" @if(@$payment_details["exp_month"] == "7") selected @endif>July</option>
            <option value="8" @if(@$payment_details["exp_month"] == "8") selected @endif>August</option>
            <option value="9" @if(@$payment_details["exp_month"] == "9") selected @endif>September</option>
            <option value="10" @if(@$payment_details["exp_month"] == "10") selected @endif>October</option>
            <option value="11" @if(@$payment_details["exp_month"] == "11") selected @endif>November</option>
            <option value="12" @if(@$payment_details["exp_month"] == "12") selected @endif>December</option>
        </select>
        /
        <?php $current_year =  date("Y");?>
        <select name="exp_year" data-name="exp_year" class="vod-processor-select" id="payment-processor-stripe-card-expiry-year">
            <option value="" selected="selected" disabled>YY </option>
            @for($year = $current_year; $year <= $current_year + 20; $year++)
                <option value="{{$year}}" @if(@$payment_details["exp_year"] == $year) selected @endif>{{$year}}</option>
            @endfor
        </select>
    </div>
</div>
<div class="uk-form-row uk-margin-top" data-name="cvc_block" @if(count($payment_details)) style="display: none" @endif>
    <label class="uk-form-label uk-h5">CVV</label>
    <div class="uk-form-controls">
        <input type="text" data-name="cvc" name="cvc" placeholder="CVV" class="uk-form-width-medium">
    </div>
</div>
<input type='hidden' name='customer_id' value='{{@$payment_details["customer_id"]}}' />
<input type='hidden' name='stripeToken' value='{{@$payment_details["token"]}}' />