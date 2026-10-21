<?php

namespace App\Reports;

use Closure;
use Illuminate\Contracts\Cache\Repository;

/**
 * Caches report data computed from the ledger.
 *
 * Invalidation uses a version number instead of deleting keys: every key
 * includes the current version, and a posting increments it. That clears
 * every report and every `as_of` date at once (a back-dated entry changes
 * past dates too) without cache tags, which the database and file drivers
 * don't support. Old versions expire on their own after the TTL.
 */
class ReportCache
{
    private const VERSION_KEY = 'reports:version';

    public function __construct(
        private readonly Repository $cache,
        private readonly int $ttlSeconds,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $compute
     * @return T
     */
    public function remember(string $report, string $variant, Closure $compute): mixed
    {
        return $this->cache->remember("reports:v{$this->version()}:{$report}:{$variant}", $this->ttlSeconds, $compute);
    }

    public function invalidate(): void
    {
        // add() only writes when the key is missing, so a concurrent first
        // invalidation can't reset a version another request already raised.
        $this->cache->add(self::VERSION_KEY, 1);
        $this->cache->increment(self::VERSION_KEY);
    }

    private function version(): int
    {
        return (int) $this->cache->get(self::VERSION_KEY, 1);
    }
}
