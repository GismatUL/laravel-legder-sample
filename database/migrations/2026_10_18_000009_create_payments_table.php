<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->comment('Customer payments recorded against invoices');
            $table->id();
            $table->foreignId('invoice_id')->comment('Invoice paid')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount')->comment('Amount received, minor units');
            $table->date('paid_on')->comment('Date the money arrived; between the issue date and today');
            $table->string('method', 30)->comment('bank_transfer, card or cash (App\Enums\PaymentMethod)');
            $table->string('reference', 100)->nullable()->comment('Bank or provider reference, e.g. transfer number');
            $table->string('idempotency_key', 100)->unique()->comment('From the Idempotency-Key header; a retry with the same key returns this payment');
            $table->foreignId('journal_entry_id')->comment('Ledger entry posted for the payment (Dr Bank, Cr Receivable)')->constrained();
            $table->timestamps();
        });

        // Backs up PaymentService: a payment is never zero.
        // Enforced by MySQL 8.0.16+; SQLite can't add CHECK constraints to an existing table.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE payments ADD CONSTRAINT chk_payments_positive CHECK (amount > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
