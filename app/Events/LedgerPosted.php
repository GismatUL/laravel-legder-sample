<?php

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A journal entry was written to the ledger. Dispatched after the posting
 * transaction commits, so listeners never react to an entry that rolled back.
 *
 * Carries ids, not models: listeners load what they need.
 */
final class LedgerPosted implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $journalEntryId,
        public readonly string $entryDate,
    ) {}
}
