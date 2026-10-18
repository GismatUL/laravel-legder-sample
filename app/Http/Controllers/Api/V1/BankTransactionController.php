<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListBankTransactionsRequest;
use App\Http\Requests\Api\V1\MatchBankTransactionRequest;
use App\Http\Resources\V1\BankTransactionResource;
use App\Models\BankTransaction;
use App\Repositories\Contracts\BankTransactionRepositoryInterface;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Services\BankReconciler;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @tags Bank reconciliation
 */
class BankTransactionController extends Controller
{
    public function index(ListBankTransactionsRequest $request, BankTransactionRepositoryInterface $transactions): AnonymousResourceCollection
    {
        return BankTransactionResource::collection($transactions->paginate($request->reconciled(), $request->perPage()));
    }

    /**
     * Match a bank line to a payment by hand.
     *
     * For lines the automatic run couldn't match (e.g. no invoice number in
     * the reference). The amounts must be equal (422), and neither the line
     * nor the payment may be matched already (409).
     */
    public function match(MatchBankTransactionRequest $request, BankTransaction $bankTransaction, BankReconciler $reconciler, PaymentRepositoryInterface $payments): BankTransactionResource
    {
        $payment = $payments->find($request->integer('payment_id'));

        return BankTransactionResource::make($reconciler->matchManually($bankTransaction, $payment)->load('payment.invoice'));
    }
}
