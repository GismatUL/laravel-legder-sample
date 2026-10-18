<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('tax_id', 20)->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->string('number', 30)->nullable()->unique();
            $table->string('status', 20)->default('draft')->index();
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('tax_total')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('amount_paid')->default(0);
            $table->foreignId('journal_entry_id')->nullable()->constrained();
            $table->foreignId('void_entry_id')->nullable()->constrained('journal_entries');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price');
            // Tax rate in basis points: 1800 = 18.00%
            $table->unsignedInteger('tax_rate_bp')->default(0);
            $table->unsignedBigInteger('line_subtotal')->default(0);
            $table->unsignedBigInteger('line_tax')->default(0);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->date('paid_on');
            $table->string('method', 30);
            $table->string('reference', 100)->nullable();
            $table->string('idempotency_key', 100)->unique();
            $table->foreignId('journal_entry_id')->constrained();
            $table->timestamps();
        });

        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->date('booked_on');
            // Positive = money in, negative = money out (minor units)
            $table->bigInteger('amount');
            $table->string('reference');
            // Hash of the statement row, so importing the same statement twice is harmless.
            $table->string('fingerprint', 64)->unique();
            $table->foreignId('payment_id')->nullable()->unique()->constrained();
            $table->timestamp('reconciled_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_lines');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('customers');
    }
};
