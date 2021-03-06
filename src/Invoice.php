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
use App\Events\PostpaidPaymentCompleted;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

class Invoice extends Model
{
    protected $table        = "payment_invoice";

    protected $connection   = "vod-tenant";

    public static function createInvoice($user, $group_id, $amount, $generate_serial_no, $cart_id, $desc = null)
    {
        $invoice                    = new Invoice();

        $invoice->invoice_serial    = $generate_serial_no ? Invoice::generateInvoiceSerial() : null;
        $invoice->proforma_serial   = !$generate_serial_no ? Invoice::generateProformaSerial() : null;
        $invoice->user_id           = $user->id;
        $invoice->group_id          = $group_id ? $group_id : NO_GROUP;
        $invoice->total             = $amount;
        $invoice->currency          = config("vod.currency");
        $invoice->status            = INVOICE_STATUS_NONE;

        // set cart_id in params
        $params["cart_id"]          = $cart_id;
        if($desc){
            $params["description"]  = $desc;
        }

        $invoice->params            = json_encode($params);

        // due_date
        $invoice->due_date          = Carbon::now()->addDays(15)->toDateTimeString();

        $invoice->save();

        return $invoice;
    }

    public static function generateInvoiceSerial()
    {
        $last_serial        = Invoice::max('invoice_serial');
        if($last_serial){
            return $last_serial + 1;
        } else{
            return 1;
        }
    }

    public static function generateProformaSerial()
    {
        $last_serial        = Invoice::max('proforma_serial');
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

    public function markPaid()
    {
        $this->status           = INVOICE_STATUS_PAID;
        $this->paid_date        = Carbon::now()->toDateTimeString();
        $this->save();
    }

    public function markRefunded()
    {
        $this->status           = INVOICE_STATUS_REFUNDED;
        $this->refund_date      = Carbon::now()->toDateTimeString();
        $this->save();
    }

    public static function getInvoiceList($data, $rows, $invoice_id = null)
    {
        $query          = Invoice::query();

        $query->select('payment_invoice.id', 'payment_invoice.invoice_serial', 'payment_invoice.proforma_serial',
            'payment_invoice.total', 'payment_invoice.status', 'payment_invoice.created_at', 'payment_invoice.paid_date', 'users.email')
            ->join('users', 'users.id', '=', 'payment_invoice.user_id');

        if($invoice_id){
            $query->where("payment_invoice.invoice", $invoice_id);
        }

        if($data['invoice_serial']){
            $query->where('payment_invoice.invoice_serial', $data['invoice_serial']);
        }
        if($data['proforma_serial']){
            $query->where('payment_invoice.proforma_serial', $data['proforma_serial']);
        }
        if($data['user_email']){
            $query->where('users.email', 'LIKE', '%'. trim($data['user_email']) .'%');
        }
        if($data['amount_from']){
            $query->where('payment_invoice.total','>=', $data['amount_from']);
        }
        if($data['amount_to']){
            $query->where('payment_invoice.total','<=', $data['amount_to']);
        }
        if($data['invoice_status']){
            $query->where('payment_invoice.status', $data['invoice_status']);
        }
        if($data['paid_date_from']){
            $query->where('payment_invoice.paid_date','>=', $data['paid_date_from']);
        }
        if($data['paid_date_to']){
            $query->where('payment_invoice.paid_date','<=', $data['paid_date_to']);
        }

        $invoice_list   = $query->orderBy('payment_invoice.created_at', 'DESC')
            ->groupBy('payment_invoice.id')
            ->paginate($rows);
        return $invoice_list;
    }

    public function markPaidManually($processor_id, $gateway_txn_id, $offline_txn_notes, $gateway_txn_fees)
    {
        // first of all, check if this invoice is associated with any transaction or not
        // if not, create the txn simultaneously

        try{
            $txn    = Transaction::markComplete($this, $processor_id, $gateway_txn_id, $offline_txn_notes, $gateway_txn_fees);
            
            if($txn){
                $this->status       = INVOICE_STATUS_PAID;
                $this->paid_date    = Carbon::now()->toDateTimeString();
                $this->save();

                // check if any cart is associated with this invoice
                $params             = json_decode($this->params, true);
                $cart_id            = $params["cart_id"];

                $cart               = Cart::find($cart_id);
                if($cart){
                    // update Cart
                    $cart->updateStatus(CART_STATUS_PAID, NO_GROUP, $txn);
                }

                // update postpaid bill status
                PostpaidBills::where("payment_invoice_id", $this->id)
                    ->update(["invoiced" => 1]);

                Event::fire(new PostpaidPaymentCompleted(config("vod.active_site"), $this->id, $this->total, $txn->gateway_txn_fees));

                return [
                    "status"        => "success",
                    "message"       => "Invoice has been successfully marked paid!",
                ];
            } else{
                throw new \Exception();
            }
        } catch(\Exception $e){
            // revert the changes
            $this->status       = INVOICE_STATUS_DUE;
            $this->paid_date    = "0000-00-00 00:00:00";
            $this->save();
            
            return [
                "status"        => "danger",
                "message"       => "Something went wrong! Please try again.",
            ];
        }
    }

    public static function getUserInvoice($user_id = 0, $group_id = 0, $page = null)
    {
        $invoice = Invoice::where('user_id',$user_id)
            ->where('group_id',$group_id)
            ->where('status',INVOICE_STATUS_PAID)
            ->paginate($perPage = 10, $columns = ['*'], $pageName = 'page', $page);

        return $invoice;
    }

    public static function getGroupInvoice($group_id = 0, $page = null)
    {
        $invoice = Invoice::select('payment_invoice.id', 'payment_invoice.user_id', 'payment_invoice.group_id', 'payment_invoice.invoice_serial', 'payment_invoice.proforma_serial', 'payment_invoice.paid_date', 'payment_invoice.total', 'payment_invoice.status', 'users.email')
            ->join('users', 'payment_invoice.user_id', '=', 'users.id')
            ->where('payment_invoice.group_id', $group_id)
            ->where('payment_invoice.status', INVOICE_STATUS_PAID)
            ->paginate($perPage = 10, $columns = ['*'], $pageName = 'page', $page);

        return $invoice;
    }
}