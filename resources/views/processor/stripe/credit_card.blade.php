<?php
/**
 * Created by PhpStorm.
 * User: rbsl-neelam
 * Date: 3/9/16
 * Time: 7:01 PM
 */
?>
<div class="uk-panel uk-panel-box uk-margin-large-top uk-margin-large-bottom">
    <form class="uk-form uk-clearfix uk-form-stacked">
        <div class="uk-form-row uk-margin-top">
            <label class="uk-form-label uk-h5">Card Number</label>
            <div class="uk-form-controls">
                <input type="text" placeholder="Card Number" class="uk-form-width-medium">
            </div>
        </div>
        <div class="uk-form-row uk-margin-top">
            <label class="uk-form-label uk-h5">Expiry Month / Expiry Year</label>
            <div class="uk-form-controls">
                <select name="payment_data[expiration_month]" class="vod-processor-select" id="payment-processor-stripe-card-expiry-month">
                    <option value="" selected="selected" disabled>MM </option>
                    <option value="01">January</option>
                    <option value="02">February</option>
                    <option value="03">March</option>
                    <option value="04">April</option>
                    <option value="05">May</option>
                    <option value="06">June</option>
                    <option value="07">July</option>
                    <option value="08">August</option>
                    <option value="09">September</option>
                    <option value="10">October</option>
                    <option value="11">November</option>
                    <option value="12">December</option>
                </select>
                /
                <?php $current_year =  date("Y");?>
                <select name="payment_data[expiration_year]" class="vod-processor-select" id="payment-processor-stripe-card-expiry-year">
                    <option value="" selected="selected" disabled>YY </option>
                    @for($year = $current_year; $year <= $current_year + 20; $year++)
                        <option value="{{$year}}">{{$year}}</option>
                    @endfor
                </select>
            </div>
        </div>
        <div class="uk-form-row uk-margin-top">
            <label class="uk-form-label uk-h5">CVV</label>
            <div class="uk-form-controls">
                <input type="text" placeholder="CVV">
            </div>
        </div>
        <div class="uk-form-row uk-margin-top">
            <input type="checkbox" class="uk-margin-right">
            <label><small>Save this card for faster checkout</small></label>
        </div>
    </form>
</div>
<div class="uk-form-row uk-margin-top uk-text-center">
    <a href="#" class="vod-pay-now uk-text-bold uk-button uk-margin-top uk-border-rounded uk-margin-large-bottom">Pay Now</a>
</div>