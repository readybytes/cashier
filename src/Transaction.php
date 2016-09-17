<?php
/**
 * Created by PhpStorm.
 * User: rbsl-neelam
 * Date: 25/8/16
 * Time: 10:36 AM
 */

namespace Laravel\Cashier;

use App\Events\TransactionCompleted;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

class Transaction extends Model
{
    protected $table        = "transaction";

    protected $connection   = "vod-tenant";

    protected $fillable     = [
        "user_id", "invoice_id", "processor_id", "amount", "currency", "payment_status", "gateway_txn_id",
        "params", "gateway_txn_fees", "message"
    ];

    public static function createTransaction($invoice, $processor_id, $payment_details)
    {
        $transaction                    = new Transaction();

        $transaction->user_id           = $invoice->user_id;
        $transaction->invoice_id        = $invoice->id;
        $transaction->processor_id      = $processor_id;
        $transaction->amount            = $invoice->total;
        $transaction->currency          = $invoice->currency;
        $transaction->payment_status    = TRANSACTION_STATUS_NONE;

        $params                         = json_decode($invoice->params, true);
        $params["payment_details"]      = $payment_details;

        $transaction->params         = json_encode($params);

        $transaction->save();

        return $transaction;
    }

    public function updateStatus($status)
    {
        $this->payment_status   = $status;
        $this->save();

        if($status == TRANSACTION_STATUS_PAYMENT_COMPLETE){
            Event::fire(new TransactionCompleted($this));
        }
    }
}