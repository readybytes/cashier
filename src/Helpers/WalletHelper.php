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

class WalletHelper
{
    public function payCart($request, $user, $cart)
    {
        // get the amount to be paid
        $amount = $request->get("amount");

        // get the group_id of wallet used
        $group_id = $request->get("vod-payment-wallet-gid");

        // get the wallet to be used for transaction
        $wallet = Wallet::where("user_id", $user->id)
            ->where("group_id", $group_id)
            ->first();

        // create invoice
        $invoice = Invoice::createInvoice($user, $amount, $cart->id);

        // create transaction
        $txn = $invoice->createTransaction(PROCESSOR_WALLET, $wallet->payment_details);

        // make payment
        $status = $this->processTransaction($invoice, $wallet, $txn);

        if ($status == INVOICE_STATUS_PAID) {
            // update Cart
            $cart->updateStatus(CART_STATUS_PAID, $group_id);
        }

        return [
            "status"     => true,
            "invoice_id" => $invoice->id,
        ];
    }

    public function processTransaction($invoice, $wallet, $txn)
    {
        // make payment
        try {
            $wallet->balance = $wallet->balance - $invoice->total;
            $wallet->save();

            $txn->updateStatus(TRANSACTION_STATUS_PAYMENT_COMPLETE);
            $invoice->markPaid($txn);
        } catch (\Exception $e) {
            $txn->updateStatus(TRANSACTION_STATUS_PAYMENT_FAILED);
        }

        return $invoice->status;
    }

    public function getUserPaymentDetails($user_id, $for_checkout = false)
    {
        // we don't need to display any payment details for the user for allowing payments through wallet
        return [];
    }
}
