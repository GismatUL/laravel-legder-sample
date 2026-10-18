<?php

namespace App\Services;

use App\Reports\TrialBalance;
use App\Repositories\Contracts\JournalEntryRepositoryInterface;
use Carbon\CarbonInterface;

class ReportService
{
    public function __construct(private readonly JournalEntryRepositoryInterface $entries) {}

    /**
     * Totals are aggregated in SQL (one grouped query), not in PHP.
     */
    public function trialBalance(?CarbonInterface $asOf = null): TrialBalance
    {
        return new TrialBalance($this->entries->totalsByAccount($asOf), $asOf);
    }
}
