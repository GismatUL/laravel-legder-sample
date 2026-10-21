<?php

namespace App\Services;

use App\Data\AccountTotals;
use App\Reports\ReportCache;
use App\Reports\TrialBalance;
use App\Repositories\Contracts\JournalEntryRepositoryInterface;
use Carbon\CarbonInterface;

class ReportService
{
    public function __construct(
        private readonly JournalEntryRepositoryInterface $entries,
        private readonly ReportCache $cache,
    ) {}

    /**
     * Totals are aggregated in SQL (one grouped query), not in PHP, and
     * cached until the next posting or account change (see ReportCache).
     */
    public function trialBalance(?CarbonInterface $asOf = null): TrialBalance
    {
        // Cached as plain arrays: the cache refuses to unserialize objects
        // (cache.serializable_classes = false), which also keeps a poisoned
        // cache entry from instantiating arbitrary classes.
        $rows = $this->cache->remember(
            'trial-balance',
            $asOf?->toDateString() ?? 'all',
            fn () => $this->entries->totalsByAccount($asOf)->map(fn (AccountTotals $totals) => $totals->toArray())->all(),
        );

        return new TrialBalance(collect($rows)->map(fn (array $row) => AccountTotals::fromArray($row)), $asOf);
    }
}
