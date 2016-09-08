<?php
/**
 * Created by PhpStorm.
 * User: rbsl-neelam
 * Date: 25/8/16
 * Time: 10:36 AM
 */

namespace Laravel\Cashier;

use App\Events\CreateTransaction;
use App\Events\InvoicePaid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

class Invoice extends Model
{
    protected $table        = "invoice";

    protected $connection   = "vod-tenant";

    public static function createInvoice($user, $amount, $cart_id)
    {
        $invoice                    = new Invoice();

        $invoice->serial            = Invoice::generateInvoiceSerial();
        $invoice->user_id           = $user->id;
        $invoice->total             = $amount;
        $invoice->currency          = config("vod.currency");
        $invoice->status            = INVOICE_STATUS_NONE;

        // set cart_id in params
        $params["cart_id"]          = $cart_id;
        $invoice->params            = json_encode($params);

        $invoice->save();

        return $invoice;
    }

    public static function generateInvoiceSerial()
    {
        $last_serial        = Invoice::max('serial');
        if($last_serial){
            return $last_serial + 1;
        } else{
            return 1;
        }
    }

    public function createTransaction($processor_id, $payment_details)
    {
        $transaction    = Transaction::createTransaction($this, $processor_id, $payment_details);
        return $transaction;
    }

    public function markPaid($transaction)
    {
        $this->transaction_id   = $transaction->id;
        $this->status           = INVOICE_STATUS_PAID;
        $this->save();
    }

}