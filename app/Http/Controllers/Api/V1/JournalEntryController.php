<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListJournalEntriesRequest;
use App\Http\Requests\Api\V1\StoreJournalEntryRequest;
use App\Http\Resources\V1\JournalEntryResource;
use App\Models\JournalEntry;
use App\Repositories\Contracts\JournalEntryRepositoryInterface;
use App\Services\LedgerService;
use Dedoc\Scramble\Attributes\HeaderParameter;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @tags General ledger
 */
class JournalEntryController extends Controller
{
    public function __construct(private readonly JournalEntryRepositoryInterface $entries) {}

    public function index(ListJournalEntriesRequest $request): AnonymousResourceCollection
    {
        return JournalEntryResource::collection(
            $this->entries->paginate($request->from(), $request->to(), $request->accountId(), $request->perPage()),
        );
    }

    /**
     * Post a manual journal entry.
     *
     * Each line is a debit or a credit. Total debits must equal total credits
     * (422 `ledger.unbalanced` otherwise). An optional `Idempotency-Key`
     * header makes retries safe.
     */
    #[HeaderParameter('Idempotency-Key', 'Optional. A retry with the same key returns the original entry.', required: false, type: 'string')]
    public function store(StoreJournalEntryRequest $request, LedgerService $ledger): JournalEntryResource
    {
        $entry = $ledger->postManual(
            $request->entryDate(),
            $request->string('description')->toString(),
            $request->lines(),
            $request->header('Idempotency-Key'),
        );

        return JournalEntryResource::make($this->entries->loadDetails($entry));
    }

    public function show(JournalEntry $journalEntry): JournalEntryResource
    {
        return JournalEntryResource::make($this->entries->loadDetails($journalEntry));
    }
}
