<?php

namespace App\Outbox;

/**
 * Delivers one type of outbox message. Must be safe to run more than once
 * for the same message: delivery is at-least-once (see DeliverOutboxMessage).
 */
interface OutboxHandler
{
    /**
     * @param  array<string, mixed>  $payload
     * @return bool false if there was nothing to deliver (the message is marked skipped)
     */
    public function handle(array $payload): bool;
}
