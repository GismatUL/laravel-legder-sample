<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\JournalEntryResource;
use App\Models\JournalEntry;
use App\Repositories\Contracts\JournalEntryRepositoryInterface;
use App\Services\LedgerService;

/**
 * @tags General ledger
 */
class ReverseJournalEntryController extends Controller
{
    /**
     * Reverse a manual journal entry.
     *
     * Posts a new entry with the opposite amounts, dated today. Entries
     * created by invoices or payments are reversed from the document (409).
     */
    public function __invoke(JournalEntry $journalEntry, LedgerService $ledger, JournalEntryRepositoryInterface $entries): JournalEntryResource
    {
        return JournalEntryResource::make($entries->loadDetails($ledger->reverseManual($journalEntry, today())));
    }
}
