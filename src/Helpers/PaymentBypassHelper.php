<?php
/**
 * Created by PhpStorm.
 * User: rbsl-neelam
 * Date: 25/8/16
 * Time: 3:06 PM
 */

namespace Laravel\Cashier\Helpers;

use Illuminate\Support\Facades\Event;
use Laravel\Cashier\Invoice;
use Laravel\Cashier\Wallet;

class PaymentBypassHelper
{
    public function payCart($request, $user, $cart)
    {
        // get the amount to be paid
        $amount     = 0;

        // create invoice
        $invoice    = Invoice::createInvoice($user, $amount, $cart->id);

        // create transaction
        $txn        = $invoice->createTransaction(PROCESSOR_NONE, "This is a transaction for free subscription");

        // make payment
        $status     = $this->processTransaction($invoice, $txn);

        if($status == INVOICE_STATUS_PAID){
            // update Cart
            $cart->updateStatus(CART_STATUS_PAID, NO_GROUP);
        }

        return true;
    }

    public function processTransaction($invoice, $txn)
    {
        // make payment
        try{
            // nothing needs to be deducted

            $txn->updateStatus(TRANSACTION_STATUS_PAYMENT_COMPLETE);
            $invoice->markPaid();
        } catch(\Exception $e){
            $txn->updateStatus(TRANSACTION_STATUS_PAYMENT_FAILED);
        }

        return $invoice->status;
    }
}
