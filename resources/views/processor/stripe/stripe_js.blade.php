<script type="text/javascript" src="https://js.stripe.com/v2/"></script>
<script type="text/javascript">
    var form_to_be_submitted;
    var submitted = false;

    $(document).ready(function () {
        // this identifies your website in the createToken call below
        Stripe.setPublishableKey('{{$p_key}}');

        function stripeResponseHandler(status, response) {
            if (response.error) {
                // re-enable the submit button
                $('.submit-button').removeAttr("disabled");
                // show the errors on the form
                $(".payment-errors").html(response.error.message).show();
                setTimeout(function () {
                    $(".payment-errors").html("").hide();
                }, 3000);

            } else {
                var form$ = form_to_be_submitted;

                // token contains id, last4, and card type
                var token = response['id'];

                // insert the token into the form so it gets submitted to the server
                form$.find("input[name='stripeToken']").val(token);

                // and submit
                if(!submitted){
                    submitted   = true;
                    form$.get(0).submit();
                }

                return false;
            }
        }

        function validateCardData(number, cvc, exp_month, exp_year) {
            // validate data
            var errors      = [];

            if(!Stripe.card.validateCardNumber(number)){
                errors.push("{{trans("front/user.invalid_card_number")}}");
            }

            if(!Stripe.card.validateExpiry(exp_month, exp_year)){
                errors.push("{{trans("front/user.invalid_expiry")}}");
            }

            if(!Stripe.card.validateCVC(cvc)){
                errors.push("{{trans("front/user.invalid_cvv")}}");
            }

            if(errors.length){
                var error_msg   = "";
                $(errors).each(function (key, value) {
                    error_msg += value + "<br/>";
                })

                $(".payment-errors").html(error_msg).show();
                setTimeout(function () {
                    $(".payment-errors").html("").hide();
                }, 3000);

                return false;
            }

            return true;
        }

        function validateToken(number, cvc, exp_month, exp_year) {
            var errors      = [];
            if(number == ""){
                errors.push("{{trans("front/user.invalid_card_number")}}");
            }

            if(!Stripe.card.validateExpiry(exp_month, exp_year)){
                errors.push("{{trans("front/user.card_error")}}");
            }

            if(errors.length){
                var error_msg   = "";
                $(errors).each(function (key, value) {
                    error_msg += value + "<br/>";
                })

                $(".payment-errors").html(error_msg).show();
                setTimeout(function () {
                    $(".payment-errors").html("").hide();
                }, 3000);

                return false;
            }

            return true;
        }

        $("form[data-processor-type^='stripe']").submit(function (event) {
            // disable the submit button to prevent repeated clicks
            $('.submit-button').attr("disabled", "disabled");

            var number      = $(this).find("input[data-name='number']").val();
            var cvc         = $(this).find("input[data-name='cvc']").val();
            var exp_month   = $(this).find("select[data-name='exp_month']").val();
            var exp_year    = $(this).find("select[data-name='exp_year']").val();

            if(number.indexOf("************") >= 0){
                // although the fields are already disabled, we should do validation for security reasons
                var validation  = validateToken(number, cvc, exp_month, exp_year);

                if(validation){
                    $(this).get(0).submit();

                    load_vod_loader = true;
                    return false; // submit from callback
                } else{
                    load_vod_loader = false;
                }
            } else{
                var validation  = validateCardData(number, cvc, exp_month, exp_year);

                form_to_be_submitted    = $(this);
                if(validation){
                    // createToken returns immediately - the supplied callback submits the form if there are no errors
                    Stripe.createToken({
                        number: number,
                        cvc: cvc,
                        exp_month: exp_month,
                        exp_year: exp_year,
                    }, stripeResponseHandler);

                    load_vod_loader = true;
                    return false; // submit from callback
                } else{
                    load_vod_loader = false;
                }
            }
            return false;
        });
    });
</script>