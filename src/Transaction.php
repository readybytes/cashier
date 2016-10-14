<?php
/**
 * Created by PhpStorm.
 * User: rbsl-neelam
 * Date: 25/8/16
 * Time: 10:36 AM
 */

namespace Laravel\Cashier;

use App\Events\TransactionRefunded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

class Transaction extends Model
{
    protected $table        = "payment_transaction";

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

        if($status == TRANSACTION_STATUS_PAYMENT_COMPLETE){
            $this->message      = "Payment completed!";
        }

        if($status == TRANSACTION_STATUS_PAYMENT_REFUND){
            $this->message      = "Payment completed!";
            Event::fire(new TransactionRefunded($this));
        }


        $this->save();
    }

    public function requestRefund()
    {
        // get invoice associated with this transaction
        $invoice    = Invoice::find($this->invoice_id);

        $processor  = PaymentProcessor::find($this->processor_id);

        $helper     = "Laravel\\Cashier\\Helpers\\".ucfirst($processor->processor_type)."Helper";

        $processor  = new $helper();
        $response   = $processor->requestRefund($this, $invoice);

        return $response;
    }

    public static function markComplete($invoice, $processor_id, $gateway_txn_id, $offline_txn_notes)
    {
        try{
            $txn        = Transaction::where("invoice_id", $invoice->id)
                ->first();

            if(!$txn){
                $txn    = Transaction::createTransaction($invoice, $processor_id, null);
            }

            $txn->processor_id          = $processor_id;
            $txn->payment_status        = TRANSACTION_STATUS_PAYMENT_COMPLETE;
            $txn->message               = "Payment completed!";

            $params                     = json_decode($txn->params, true);

            if($gateway_txn_id){
                $processor_type = PaymentProcessor::find($processor_id)->processor_type;
                $helper         = "Laravel\\Cashier\\Helpers\\".ucfirst($processor_type)."Helper";
                $helper_obj     = new $helper();

                list($gateway_txn_id, $gateway_txn_fees, $txn_details)  =   $helper_obj->getTransactionDetails($gateway_txn_id);
                $txn->gateway_txn_id    = $gateway_txn_id;
                $txn->gateway_txn_fees  = $gateway_txn_fees;

                $params["txn_details"]  = $txn_details;
                $params["message"]      = "Payment remotely accepted and marked Paid by Admin";
            }

            if($offline_txn_notes){
                $params["offline_txn_notes"]    = $offline_txn_notes;
            }

            $txn->params    = json_encode($params);

            $txn->save();

            return $txn;

        } catch(\Exception $e){
            // revert the status updated
            if(isset($txn)){
                $txn->payment_status    = TRANSACTION_STATUS_PAYMENT_PENDING;
                $txn->message           = "";
                $txn->gateway_txn_id    = "";
                $txn->gateway_txn_fees  = 0;

                if(isset($params) && isset($params["offline_txn_notes"])){
                    unset($params["offline_txn_notes"]);
                }

                $txn->save();
            }

            return false;
        }
    }
}