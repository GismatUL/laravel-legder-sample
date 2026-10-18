<?php

namespace App\Console\Commands;

use App\Enums\OutboxStatus;
use App\Jobs\DeliverOutboxMessage;
use App\Models\OutboxMessage;
use Illuminate\Console\Command;

/**
 * Safety net for the outbox. The normal path dispatches a job right after
 * commit; this re-dispatches messages whose job was lost (process killed
 * between commit and dispatch, queue flushed) or whose worker died mid-delivery.
 * Duplicate dispatches are harmless: DeliverOutboxMessage claims before it delivers.
 */
class RelayOutbox extends Command
{
    protected $signature = 'outbox:relay {--older-than=5 : Only messages pending for at least this many minutes}';

    protected $description = 'Re-dispatch outbox messages that are still pending';

    public function handle(): int
    {
        $cutoff = now()->subMinutes((int) $this->option('older-than'));
        $count = 0;

        OutboxMessage::query()
            ->where('status', OutboxStatus::Pending)
            ->where('created_at', '<=', $cutoff)
            ->where(fn ($query) => $query
                ->whereNull('claimed_at')
                ->orWhere('claimed_at', '<', now()->subMinutes(DeliverOutboxMessage::CLAIM_TTL_MINUTES)))
            ->chunkById(200, function ($messages) use (&$count) {
                foreach ($messages as $message) {
                    DeliverOutboxMessage::dispatch($message->id);
                    $count++;
                }
            });

        $this->info("Re-dispatched {$count} pending outbox messages.");

        return self::SUCCESS;
    }
}
