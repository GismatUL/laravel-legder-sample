<?php

namespace App\Outbox\Handlers;

use App\Models\Payment;
use App\Outbox\OutboxHandler;
use App\Support\CircuitBreaker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Tells an external system (e.g. the customer's ERP) that a payment was
 * recorded. Off unless LEDGER_WEBHOOK_URL is set.
 *
 * Failure handling, from the inside out:
 * - timeouts: a slow receiver can't hold a worker for long;
 * - two quick retries for connection errors and 5xx/429 (not other 4xx:
 *   a rejected payload won't be accepted on a retry);
 * - a circuit breaker: after repeated failures, deliveries fail fast
 *   instead of waiting on a receiver that is down;
 * - the outbox job's own retries with backoff (DeliverOutboxMessage).
 *
 * Delivery is at least once, so every request carries an Idempotency-Key
 * the receiver can deduplicate on, and an HMAC signature of the body.
 */
class NotifyPaymentWebhook implements OutboxHandler
{
    public function handle(array $payload): bool
    {
        $url = config('ledger.webhook.url');

        if (! is_string($url) || $url === '') {
            return false;
        }

        $payment = Payment::query()->with('invoice')->findOrFail((int) $payload['payment_id']);

        $body = [
            'event' => 'payment.recorded',
            'event_id' => (string) $payload['event_id'],
            'payment' => [
                'id' => $payment->id,
                'invoice_number' => $payment->invoice->number,
                'amount' => $payment->amount,
                'paid_on' => $payment->paid_on->toDateString(),
                'method' => $payment->method->value,
            ],
        ];

        $json = (string) json_encode($body, JSON_THROW_ON_ERROR);

        // retry(3) is three attempts in total (the first + two retries). It
        // throws a RequestException once they are used up, or at once when
        // isTransient() says another attempt won't help.
        $this->breaker()->call(fn () => Http::withHeaders([
            'Idempotency-Key' => $body['event_id'],
            'X-Signature' => 'sha256='.hash_hmac('sha256', $json, (string) config('ledger.webhook.secret')),
        ])
            ->connectTimeout((int) config('ledger.webhook.connect_timeout'))
            ->timeout((int) config('ledger.webhook.timeout'))
            ->retry(3, 250, fn (Throwable $e) => $this->isTransient($e))
            ->withBody($json, 'application/json')
            ->post($url));

        return true;
    }

    private function isTransient(Throwable $e): bool
    {
        if ($e instanceof ConnectionException) {
            return true;
        }

        return $e instanceof RequestException
            && ($e->response->serverError() || $e->response->status() === 429);
    }

    private function breaker(): CircuitBreaker
    {
        return new CircuitBreaker(
            Cache::store(),
            'payment-webhook',
            threshold: (int) config('ledger.webhook.circuit_threshold'),
            coolDownSeconds: (int) config('ledger.webhook.circuit_cool_down'),
        );
    }
}
