<?php

namespace Tests\Feature;

use App\Enums\OutboxStatus;
use App\Enums\PaymentMethod;
use App\Exceptions\CircuitOpenException;
use App\Jobs\DeliverOutboxMessage;
use App\Models\Invoice;
use App\Models\OutboxMessage;
use App\Models\Payment;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Support\Money;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

class PaymentWebhookTest extends LedgerTestCase
{
    private const URL = 'https://erp.example.test/hooks/payments';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config([
            'ledger.webhook.url' => self::URL,
            'ledger.webhook.secret' => 'test-secret',
            'ledger.webhook.circuit_threshold' => 2,
        ]);
    }

    private function recordPayment(string $key = 'psp-1'): Payment
    {
        $invoice = app(InvoiceService::class)->issue(Invoice::factory()->withLines([[1, 10000, 1800]])->create());

        return app(PaymentService::class)->record($invoice, Money::parse('50.00'), today(), PaymentMethod::BankTransfer, $key);
    }

    private function webhookMessage(): OutboxMessage
    {
        return OutboxMessage::query()->where('type', 'payment_recorded')->sole();
    }

    public function test_a_recorded_payment_is_posted_to_the_webhook_with_a_signature_and_idempotency_key(): void
    {
        Http::fake([self::URL => Http::response(status: 204)]);

        $payment = $this->recordPayment();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) use ($payment) {
            $expected = 'sha256='.hash_hmac('sha256', $request->body(), 'test-secret');

            return $request->url() === self::URL
                && $request->header('Idempotency-Key') === ["payment_recorded:{$payment->id}"]
                && $request->header('X-Signature') === [$expected]
                && $request['event'] === 'payment.recorded'
                && $request['payment']['amount'] === ['amount' => '50.00', 'currency' => 'AZN'];
        });
        $this->assertSame(OutboxStatus::Sent, $this->webhookMessage()->status);
    }

    public function test_without_a_url_the_message_is_skipped(): void
    {
        config(['ledger.webhook.url' => null]);
        Http::fake();

        $this->recordPayment();

        Http::assertNothingSent();
        $this->assertSame(OutboxStatus::Skipped, $this->webhookMessage()->status);
    }

    public function test_server_errors_are_retried_and_then_left_for_the_queue_retry(): void
    {
        Queue::fake();
        Http::fake([self::URL => Http::response(status: 503)]);
        $this->recordPayment();
        $message = $this->webhookMessage();

        $this->assertThrows(fn () => (new DeliverOutboxMessage($message->id))->handle(), RequestException::class);

        Http::assertSentCount(3); // the first attempt and two quick retries
        $message->refresh();
        $this->assertSame(OutboxStatus::Pending, $message->status);
        $this->assertStringContainsString('503', (string) $message->last_error);
    }

    public function test_a_rejected_payload_is_not_retried_immediately(): void
    {
        Queue::fake();
        Http::fake([self::URL => Http::response(status: 422)]);
        $this->recordPayment();

        $this->assertThrows(fn () => (new DeliverOutboxMessage($this->webhookMessage()->id))->handle(), RequestException::class);

        Http::assertSentCount(1);
    }

    public function test_after_repeated_failures_the_circuit_opens_and_deliveries_fail_fast(): void
    {
        Queue::fake();
        Http::fake([self::URL => Http::failedConnection()]);
        $this->recordPayment('psp-1');
        $this->recordPayment('psp-2');
        $this->recordPayment('psp-3');
        $messages = OutboxMessage::query()->where('type', 'payment_recorded')->orderBy('id')->get();

        foreach ($messages->take(2) as $message) {
            $this->assertThrows(fn () => (new DeliverOutboxMessage($message->id))->handle());
        }
        $sentBefore = Http::recorded()->count();

        $this->assertThrows(fn () => (new DeliverOutboxMessage($messages[2]->id))->handle(), CircuitOpenException::class);

        $this->assertSame($sentBefore, Http::recorded()->count(), 'No request while the circuit is open');
        $this->assertStringContainsString('is open', (string) $messages[2]->refresh()->last_error);
    }
}
