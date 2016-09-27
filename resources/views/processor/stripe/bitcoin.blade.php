<?php
/**
 * Created by PhpStorm.
 * User: rbsl-neelam
 * Date: 3/9/16
 * Time: 7:01 PM
 */

$js_load_status    = isset($stripe_bitcoin_js_added) ? $stripe_bitcoin_js_added : false;
?>

@if(!$js_load_status)
    <?php $stripe_bitcoin_js_added = true;?>
    <script type="text/javascript">
        var form_submitted  = false;
        $(document).ready(function () {

            function validateEmail(email) {
                var re = /^(([^<>()[\]\\.,;:\s@\"]+(\.[^<>()[\]\\.,;:\s@\"]+)*)|(\".+\"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/;
                return re.test(email);
            }

            $("form[data-processor-type^='bitcoin-stripe']").submit(function (event) {

                var email   = $(this).find("input[name='customer_email']").val();
                if(!email || !validateEmail(email)){
                    $(".payment-errors").html("Enter valid email address").show();
                    load_vod_loader = false;
                    return false;
                } else{
                    $(".payment-errors").html("").hide();

                    load_vod_loader = true;
                    if(!form_submitted){
                        $(this).get(0).submit();
                        form_submitted  = true;
                    }
                    return false;
                }
                return false;
            });
        });
    </script>
@endif

<div class="uk-alert uk-alert-danger payment-errors" style="display: none;">
    <!-- to display errors returned by createToken -->
</div>

<div class="uk-form-row uk-margin-top" data-name="customer_email">
    <label>Email associated with your Bitcoin Wallet</label>
    <div class="uk-form-controls">
        <input type="email" data-name="customer_email" name="customer_email" placeholder="Email" class="uk-form-width-medium">
    </div>
</div>

<input type='hidden' name='stripeToken' value='{{@$payment_details["token"]}}' />