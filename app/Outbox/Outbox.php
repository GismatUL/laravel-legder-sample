<?php

namespace App\Outbox;

use App\Jobs\DeliverOutboxMessage;
use App\Models\OutboxMessage;

class Outbox
{
    /**
     * Call inside the transaction that makes the business change. The job is
     * dispatched after commit, so a worker never sees a message whose
     * transaction might still roll back.
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(string $type, string $dedupeKey, array $payload): OutboxMessage
    {
        $message = OutboxMessage::query()->create([
            'type' => $type,
            'dedupe_key' => $dedupeKey,
            'payload' => $payload,
        ]);

        DeliverOutboxMessage::dispatch($message->id)->afterCommit();

        return $message;
    }
}
