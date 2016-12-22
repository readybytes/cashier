<?php
/**
 * Created by PhpStorm.
 * User: rbsl-neelam
 * Date: 25/8/16
 * Time: 10:36 AM
 */

namespace Laravel\Cashier;

use Illuminate\Database\Eloquent\Model;

class PostpaidBills extends Model
{
    protected $table        = "postpaid_bills";

    protected $connection   = "vod-tenant";

    public static function getPostPaidBillList($data, $rows)
    {
        $query              = PostpaidBills::query();

        $query->select('postpaid_bills.id', 'postpaid_bills.group_id', 'postpaid_bills.amount', 'postpaid_bills.payment_invoice_id',
            'postpaid_bills.discount', 'postpaid_bills.params', 'postpaid_bills.invoiced', 'postpaid_bills.created_at', 'user_group.id',
            'user_group.group_name', 'wallet.group_id', 'wallet.user_id', 'wallet.billing_start_date', 'wallet.billing_end_date')
            ->join('user_group', 'user_group.id', '=', 'postpaid_bills.group_id')
            ->join('wallet', 'wallet.group_id', '=', 'postpaid_bills.group_id')
            ->where('wallet.user_id',0);

        if($data['group_name']){
            $query->where('user_group.group_name', 'LIKE', '%'. $data['group_name'] .'%');
        }
        if($data['discount']){
            $query->where('postpaid_bills.discount', $data['discount']);
        }
        if($data['invoiced'] != null){
            $query->where('postpaid_bills.invoiced', $data['invoiced']);
        }
        if($data['amount_from']){
            $query->where('postpaid_bills.amount','>=', $data['amount_from']);
        }
        if($data['amount_to']){
            $query->where('postpaid_bills.amount','<=', $data['amount_to']);
        }
        if($data['start_date_from']){
            $query->where('wallet.billing_start_date','>=', $data['start_date_from']);
        }
        if($data['start_date_to']){
            $query->where('wallet.billing_start_date','<=', $data['start_date_to']);
        }
        if($data['end_date_from']){
            $query->where('wallet.billing_end_date','>=', $data['end_date_from']);
        }
        if($data['end_date_to']){
            $query->where('wallet.billing_end_date','<=', $data['end_date_to']);
        }
        $list                       = $query->paginate($rows);
        return $list;
    }
}