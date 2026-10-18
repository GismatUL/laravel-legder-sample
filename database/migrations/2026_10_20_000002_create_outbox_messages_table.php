<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transactional outbox: a side effect outside the database (an email) is
 * written as a row in the same transaction as the business change, and
 * delivered afterwards by a queued job. If the transaction rolls back, the
 * message is gone too; if the queue loses the job, `outbox:relay` re-dispatches it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50);
            // One message per business event, e.g. "invoice_issued:42".
            $table->string('dedupe_key', 100)->unique();
            $table->json('payload');
            // pending, sent, skipped (nothing to deliver), failed (gave up)
            $table->string('status', 10)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            // Set while a worker is delivering; an old claim means that worker died.
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
