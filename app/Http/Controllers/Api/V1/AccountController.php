<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListAccountsRequest;
use App\Http\Requests\Api\V1\StoreAccountRequest;
use App\Http\Requests\Api\V1\UpdateAccountRequest;
use App\Http\Resources\V1\AccountResource;
use App\Models\Account;
use App\Repositories\Contracts\AccountRepositoryInterface;
use App\Services\AccountService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @tags Chart of accounts
 */
class AccountController extends Controller
{
    public function __construct(
        private readonly AccountRepositoryInterface $accounts,
        private readonly AccountService $service,
    ) {}

    /**
     * List accounts with their balances.
     */
    public function index(ListAccountsRequest $request): AnonymousResourceCollection
    {
        return AccountResource::collection(
            $this->accounts->paginateWithTotals($request->type(), $request->active(), $request->perPage()),
        );
    }

    public function store(StoreAccountRequest $request): AccountResource
    {
        return AccountResource::make($this->service->create($request->validated()));
    }

    public function show(Account $account): AccountResource
    {
        return AccountResource::make($this->accounts->withTotals($account));
    }

    /**
     * Update an account.
     *
     * The code and type can't change once the account has postings (409).
     */
    public function update(UpdateAccountRequest $request, Account $account): AccountResource
    {
        return AccountResource::make($this->accounts->withTotals($this->service->update($account, $request->validated())));
    }
}
