<?php

namespace Tests\Feature;

use App\Exceptions\CircuitOpenException;
use App\Support\CircuitBreaker;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Tests\TestCase;

class CircuitBreakerTest extends TestCase
{
    private function breaker(): CircuitBreaker
    {
        return new CircuitBreaker(Cache::store(), 'test', threshold: 3, coolDownSeconds: 60);
    }

    private function failOnce(CircuitBreaker $breaker): void
    {
        $this->assertThrows(fn () => $breaker->call(fn () => throw new RuntimeException('down')), RuntimeException::class);
    }

    public function test_opens_after_the_threshold_and_then_fails_fast_without_calling(): void
    {
        $breaker = $this->breaker();
        $this->failOnce($breaker);
        $this->failOnce($breaker);
        $this->assertFalse($breaker->isOpen());

        $this->failOnce($breaker);
        $this->assertTrue($breaker->isOpen());

        $dependency = new class
        {
            public int $calls = 0;
        };
        $this->assertThrows(fn () => $breaker->call(fn () => $dependency->calls++), CircuitOpenException::class);
        $this->assertSame(0, $dependency->calls);
    }

    public function test_after_the_cool_down_a_successful_call_closes_the_circuit(): void
    {
        $breaker = $this->breaker();
        $this->failOnce($breaker);
        $this->failOnce($breaker);
        $this->failOnce($breaker);

        $this->travel(61)->seconds();

        $this->assertSame('ok', $breaker->call(fn () => 'ok'));
        $this->assertFalse($breaker->isOpen());

        // The failure count was reset too: one new failure doesn't reopen it.
        $this->failOnce($breaker);
        $this->assertFalse($breaker->isOpen());
    }

    public function test_after_the_cool_down_a_failed_probe_opens_it_again(): void
    {
        $breaker = $this->breaker();
        $this->failOnce($breaker);
        $this->failOnce($breaker);
        $this->failOnce($breaker);

        $this->travel(61)->seconds();
        $this->failOnce($breaker);

        $this->assertTrue($breaker->isOpen());
    }

    public function test_a_success_resets_the_count_of_failures_in_a_row(): void
    {
        $breaker = $this->breaker();
        $this->failOnce($breaker);
        $this->failOnce($breaker);
        $breaker->call(fn () => null);
        $this->failOnce($breaker);
        $this->failOnce($breaker);

        $this->assertFalse($breaker->isOpen());
    }
}
