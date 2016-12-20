<?php
/**
 * Created by PhpStorm.
 * User: rbsl-neelam
 * Date: 25/8/16
 * Time: 3:06 PM
 */

namespace Laravel\Cashier\Helpers;

use Illuminate\Support\Facades\Event;
use App\vod\model\ResourceAllocated;
use Laravel\Cashier\Invoice;
use Laravel\Cashier\Wallet;
use Laravel\Cashier\WalletHistory;

class PaymentBypassHelper
{
    public function payCart($request, $user, $cart)
    {
        try{
            // get the amount to be paid
            $amount     = 0;

            // create invoice
            $invoice    = Invoice::createInvoice($user, NO_GROUP, $amount, false, $cart->id);

            // create transaction
            $txn        = $invoice->createTransaction(PROCESSOR_NONE, "This is a transaction for free subscription");

            // make payment
            $status     = $this->processTransaction($invoice, $txn);

            if($status == INVOICE_STATUS_PAID){
                // update Cart
                $cart->updateStatus(CART_STATUS_PAID, NO_GROUP);
            }

            return true;
        } catch(\Exception $e){
            // remove invoice & txn if created
            if(isset($invoice)){
                $invoice->delete();
            }

            if(isset($txn)){
                $txn->delete();
            }
            
            return false;
        }
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

    private function __preparePaymentDetails($user, $request)
    {
        $payment_data = [
            "time_limit" => $request->get("time_limit"),
        ];

        return $payment_data;
    }

    public function payForTokenBasedUrl($request, $user, $resource_data)
    {
        try{
            // get the amount to be paid
            $amount     = 0;

            // create invoice
            $invoice    = Invoice::createInvoice($user, $resource_data["group_id"], $amount, false, 0, "Url Sharing for ".ucwords($resource_data["movie_title"]));

            // payment details
            $payment_details    = $this->__preparePaymentDetails($user, $request);

            // create transaction
            $txn        = $invoice->createTransaction(PROCESSOR_NONE, $payment_details);

            // make payment
            $status     = $this->processTransaction($invoice, $txn);

            // check if invoice has been marked paid
            if($status == INVOICE_STATUS_PAID){
                $resource   = ResourceAllocated::allocateAnonymousAccess($resource_data);
                //RevenueSplitter::splitSharedLinkRevenue($txn, $resource->id, false);
                $status = true;
            } else{
                $status = false;
            }

            // prepare response
            $response   = [
                "status"            => $status,
                "message"           => $txn->message,
                "payment_details"   => $payment_details,
                "invoice_id"        => $invoice->id,
                "resource"          =>  $resource,
            ];
        } catch(\Exception $e){

            // delete the transaction
            if(isset($txn)){
                // revert the transaction in case it is already processed
                $txn->delete();
            }

            // delete the invoice
            if(isset($invoice)){
                // delete the entries from wallet history
                WalletHistory::where("payment_invoice_id", $invoice->id)->delete();

                $invoice->delete();
            }

            // prepare response
            $response   = [
                "status"            => false,
                "message"           => $e->getMessage(),
            ];

            // TODO::Log the exception properly
        }

        return $response;
    }
}
