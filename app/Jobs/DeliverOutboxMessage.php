<?php

namespace App\Jobs;

use App\Enums\OutboxStatus;
use App\Models\OutboxMessage;
use App\Outbox\OutboxHandler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * Delivers one outbox message, at least once.
 *
 * A worker first claims the message with a single conditional UPDATE, so
 * two workers (a retry, a relay re-dispatch) never deliver it at the same
 * time, and no database lock is held while the email is being sent. If a
 * worker dies after sending but before marking the message sent, the claim
 * expires and the message is delivered again: an email can arrive twice,
 * but it is never lost. Exactly-once would need the receiving side to
 * deduplicate, which SMTP can't.
 */
class DeliverOutboxMessage implements ShouldQueue
{
    use Queueable;

    public const CLAIM_TTL_MINUTES = 10;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [30, 120, 600, 1800];

    public int $timeout = 60;

    public function __construct(public readonly int $messageId) {}

    public function handle(): void
    {
        if (! $this->claim()) {
            return; // already delivered, given up, or being delivered by another worker
        }

        $message = OutboxMessage::query()->findOrFail($this->messageId);

        try {
            $delivered = $this->handler($message->type)->handle($message->payload);
        } catch (Throwable $e) {
            // Release the claim so the queue's retry can pick it up right away.
            $message->forceFill([
                'claimed_at' => null,
                'last_error' => Str::limit($e::class.': '.$e->getMessage(), 490),
            ])->save();

            throw $e;
        }

        $message->forceFill([
            'status' => $delivered ? OutboxStatus::Sent : OutboxStatus::Skipped,
            'processed_at' => now(),
            'claimed_at' => null,
            'last_error' => null,
        ])->save();
    }

    public function failed(?Throwable $e): void
    {
        OutboxMessage::query()
            ->whereKey($this->messageId)
            ->where('status', OutboxStatus::Pending)
            ->update(['status' => OutboxStatus::Failed, 'claimed_at' => null, 'updated_at' => now()]);

        // The message text can contain the recipient's address; it is kept in
        // outbox_messages.last_error, not in the log.
        Log::error('Outbox delivery failed', ['message_id' => $this->messageId, 'exception' => $e === null ? null : $e::class]);
    }

    private function claim(): bool
    {
        return OutboxMessage::query()
            ->whereKey($this->messageId)
            ->where('status', OutboxStatus::Pending)
            ->where(fn ($query) => $query
                ->whereNull('claimed_at')
                ->orWhere('claimed_at', '<', now()->subMinutes(self::CLAIM_TTL_MINUTES)))
            ->update(['claimed_at' => now(), 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]) === 1;
    }

    private function handler(string $type): OutboxHandler
    {
        $class = config("ledger.outbox_handlers.{$type}");

        if (! is_string($class) || ! is_a($class, OutboxHandler::class, true)) {
            throw new LogicException("No outbox handler for message type [{$type}].");
        }

        return app($class);
    }
}
