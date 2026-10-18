<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->comment('Transactional outbox: side effects (emails) written with the business change, sent later by DeliverOutboxMessage');
            $table->id();
            $table->string('type', 50)->comment('Message type; selects the handler in config/ledger.php, e.g. invoice_issued');
            $table->string('dedupe_key', 100)->unique()->comment('One message per business event, e.g. invoice_issued:42');
            $table->json('payload')->comment('Data for the handler, e.g. {"invoice_id": 42}');
            $table->string('status', 10)->default('pending')->comment('pending, sent, skipped (nothing to deliver) or failed (gave up)');
            $table->unsignedInteger('attempts')->default(0)->comment('Delivery attempts so far');
            $table->timestamp('claimed_at')->nullable()->comment('Set while a worker delivers; a claim older than 10 minutes means the worker died');
            $table->timestamp('processed_at')->nullable()->comment('When it was sent or skipped');
            $table->string('last_error', 500)->nullable()->comment('Error of the last failed attempt');
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
