<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListBankTransactionsRequest;
use App\Http\Resources\V1\BankTransactionResource;
use App\Repositories\Contracts\BankTransactionRepositoryInterface;
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
}
