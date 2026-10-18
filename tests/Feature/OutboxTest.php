<?php

namespace Tests\Feature;

use App\Enums\OutboxStatus;
use App\Exceptions\LedgerException;
use App\Jobs\DeliverOutboxMessage;
use App\Mail\InvoiceIssued;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\OutboxMessage;
use App\Outbox\OutboxHandler;
use App\Services\InvoiceService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

class OutboxTest extends LedgerTestCase
{
    private function issue(?string $email = 'billing@acme.test'): Invoice
    {
        $customer = Customer::factory()->create(['email' => $email]);

        return app(InvoiceService::class)->issue(Invoice::factory()->for($customer)->withLines([[2, 5000, 1800]])->create());
    }

    public function test_issuing_an_invoice_emails_the_customer_after_commit(): void
    {
        Mail::fake();

        $invoice = $this->issue();

        Mail::assertSent(InvoiceIssued::class, fn (InvoiceIssued $mail) => $mail->hasTo('billing@acme.test')
            && $mail->invoice->is($invoice));

        $message = OutboxMessage::sole();
        $this->assertSame(OutboxStatus::Sent, $message->status);
        $this->assertSame(1, $message->attempts);
        $this->assertNotNull($message->processed_at);
        $this->assertSame("invoice_issued:{$invoice->id}", $message->dedupe_key);
    }

    public function test_the_email_shows_the_invoice_totals_and_payment_reference(): void
    {
        $invoice = $this->issue();

        (new InvoiceIssued($invoice->load(['customer', 'lines'])))
            ->assertSeeInHtml($invoice->number)
            ->assertSeeInHtml('118.00 AZN')
            ->assertSeeInHtml('payment reference');
    }

    public function test_a_failed_issue_writes_no_message_and_queues_no_job(): void
    {
        Queue::fake();
        $this->account('sales')->update(['is_active' => false]);

        $this->assertThrows(fn () => $this->issue(), LedgerException::class);

        $this->assertDatabaseCount('outbox_messages', 0);
        Queue::assertNothingPushed();
    }

    public function test_the_job_is_dispatched_only_after_the_transaction_commits(): void
    {
        Queue::fake();

        $this->issue();

        Queue::assertPushed(DeliverOutboxMessage::class, fn (DeliverOutboxMessage $job) => $job->afterCommit === true);
    }

    public function test_delivering_the_same_message_twice_sends_one_email(): void
    {
        Mail::fake();
        $this->issue();
        $message = OutboxMessage::sole();

        // A relay re-dispatch or a duplicate job after the first delivery.
        (new DeliverOutboxMessage($message->id))->handle();

        Mail::assertSentCount(1);
        $this->assertSame(1, $message->refresh()->attempts);
    }

    public function test_a_message_claimed_by_another_worker_is_left_alone(): void
    {
        Mail::fake();
        Queue::fake();
        $this->issue();
        $message = OutboxMessage::sole();
        $message->forceFill(['claimed_at' => now()->subMinute()])->save();

        (new DeliverOutboxMessage($message->id))->handle();

        Mail::assertNothingSent();

        // The other worker died: once the claim is older than the TTL, it can be taken over.
        $this->travel(DeliverOutboxMessage::CLAIM_TTL_MINUTES + 1)->minutes();
        (new DeliverOutboxMessage($message->id))->handle();

        Mail::assertSentCount(1);
        $this->assertSame(OutboxStatus::Sent, $message->refresh()->status);
    }

    public function test_a_customer_without_email_is_skipped_not_failed(): void
    {
        Mail::fake();

        $this->issue(email: null);

        Mail::assertNothingSent();
        $this->assertSame(OutboxStatus::Skipped, OutboxMessage::sole()->status);
    }

    public function test_a_delivery_error_releases_the_claim_for_the_retry_and_fails_after_the_last_attempt(): void
    {
        Queue::fake();
        $this->issue();
        $message = OutboxMessage::sole();

        config(['ledger.outbox_handlers.invoice_issued' => FailingOutboxHandler::class]);

        $job = new DeliverOutboxMessage($message->id);
        $this->assertThrows(fn () => $job->handle(), RuntimeException::class);

        $message->refresh();
        $this->assertSame(OutboxStatus::Pending, $message->status);
        $this->assertNull($message->claimed_at);
        $this->assertSame(1, $message->attempts);
        $this->assertStringContainsString('SMTP connection refused', (string) $message->last_error);

        $job->failed(new RuntimeException('SMTP connection refused'));
        $this->assertSame(OutboxStatus::Failed, $message->refresh()->status);
    }

    public function test_relay_re_dispatches_only_stale_pending_messages(): void
    {
        Queue::fake();
        $this->issue();
        $stale = OutboxMessage::sole();
        OutboxMessage::query()->create(['type' => 'invoice_issued', 'dedupe_key' => 'already-sent', 'payload' => [], 'status' => OutboxStatus::Sent]);
        $this->travel(10)->minutes();
        OutboxMessage::query()->create(['type' => 'invoice_issued', 'dedupe_key' => 'just-created', 'payload' => []]);
        Queue::fake(); // forget the dispatches above

        $this->assertSame(0, Artisan::call('outbox:relay'));
        $this->assertStringContainsString('Re-dispatched 1', Artisan::output());

        Queue::assertPushed(DeliverOutboxMessage::class, 1);
        Queue::assertPushed(DeliverOutboxMessage::class, fn (DeliverOutboxMessage $job) => $job->messageId === $stale->id);
    }

    public function test_relay_is_scheduled(): void
    {
        Artisan::call('schedule:list');

        $this->assertStringContainsString('outbox:relay', Artisan::output());
    }
}

final class FailingOutboxHandler implements OutboxHandler
{
    public function handle(array $payload): bool
    {
        throw new RuntimeException('SMTP connection refused');
    }
}
