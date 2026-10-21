<?php

namespace App\Listeners;

use App\Events\LedgerPosted;
use App\Reports\ReportCache;

/**
 * Runs synchronously: the next report request after a posting must already
 * see it. A queued listener would leave a window with a stale trial balance.
 */
class InvalidateReportCache
{
    public function __construct(private readonly ReportCache $reports) {}

    public function handle(LedgerPosted $event): void
    {
        $this->reports->invalidate();
    }
}
