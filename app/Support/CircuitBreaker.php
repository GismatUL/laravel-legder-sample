<?php

namespace App\Support;

use App\Exceptions\CircuitOpenException;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Throwable;

/**
 * Stops calling a dependency that keeps failing.
 *
 * Closed: calls go through; failures are counted within a window.
 * Open: after `threshold` failures in a row, calls fail immediately with
 * CircuitOpenException for `coolDownSeconds`, without touching the network.
 * Half-open: after the cool-down, one call is let through. Success closes the
 * circuit; failure opens it again for another cool-down.
 *
 * State lives in the cache, so every worker shares it. With Redis the
 * counters are atomic; with the array driver (tests) they're per process.
 */
class CircuitBreaker
{
    public function __construct(
        private readonly Repository $cache,
        private readonly string $name,
        private readonly int $threshold = 5,
        private readonly int $coolDownSeconds = 60,
    ) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $call
     * @return T
     *
     * @throws CircuitOpenException
     */
    public function call(Closure $call): mixed
    {
        if ($this->isOpen()) {
            throw CircuitOpenException::for($this->name, $this->retryAfter());
        }

        try {
            $result = $call();
        } catch (Throwable $e) {
            $this->recordFailure();

            throw $e;
        }

        $this->cache->forget($this->key('failures'));

        return $result;
    }

    public function isOpen(): bool
    {
        return $this->retryAfter() > 0;
    }

    private function recordFailure(): void
    {
        // The window resets after a quiet period, so a few failures spread
        // over days never open the circuit.
        $this->cache->add($this->key('failures'), 0, $this->coolDownSeconds * 5);
        $failures = (int) $this->cache->increment($this->key('failures'));

        if ($failures >= $this->threshold) {
            $this->cache->put($this->key('open_until'), now()->addSeconds($this->coolDownSeconds)->getTimestamp(), $this->coolDownSeconds);
        }
    }

    private function retryAfter(): int
    {
        $openUntil = (int) $this->cache->get($this->key('open_until'), 0);

        return max($openUntil - now()->getTimestamp(), 0);
    }

    private function key(string $suffix): string
    {
        return "circuit:{$this->name}:{$suffix}";
    }
}
