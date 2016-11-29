<?php
/**
 * Created by PhpStorm.
 * User: jitendra
 * Date: 8/11/16
 * Time: 6:50 PM
 */
?>

<div class="uk-alert uk-alert-danger payment-errors" style="display: none;">
    <!-- to display errors returned by createToken -->
</div>

<input type="hidden" name="business" value="{{ $config["paypal_email"] }}">
<input type="hidden" name="_token" value="{{ csrf_token() }}">
<input type="hidden" name="cmd" value="_xclick">
<input type="hidden" name="rm" value="2">
<input type="hidden" name="no_note" value="1">
<input type="hidden" name="invoice">
<input type="hidden" name="currency_code" value="{{config("vod.currency")}}">

