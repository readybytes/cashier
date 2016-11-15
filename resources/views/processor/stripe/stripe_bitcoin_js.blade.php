<?php
/**
 * Created by PhpStorm.
 * User: neelam
 * Date: 1/10/16
 * Time: 1:56 PM
 */
?>
<script type="text/javascript">
    var form_submitted  = false;
    $(document).ready(function () {

        function validateEmail(email) {
            var re = /^(([^<>()[\]\\.,;:\s@\"]+(\.[^<>()[\]\\.,;:\s@\"]+)*)|(\".+\"))@((\[[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\.[0-9]{1,3}\])|(([a-zA-Z\-0-9]+\.)+[a-zA-Z]{2,}))$/;
            return re.test(email);
        }

        $("form[data-processor-type^='bitcoin-stripe']").submit(function (event) {

            if(!form_submitted){
                var email   = $(this).find("input[name='customer_email']").val();
                if(!email || !validateEmail(email)){
                    $(".payment-errors").html("{{trans("front/user.email_error")}}").show();
                    load_vod_loader = false;
                    return false;
                } else{
                    $(".payment-errors").html("").hide();

                    load_vod_loader = true;

                    $(this).get(0).submit();
                    form_submitted  = true;

                    return false;
                }
            }

            return false;
        });
    });
</script>

