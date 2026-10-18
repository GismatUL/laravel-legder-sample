<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->comment('Lines imported from bank statements, matched to payments');
            $table->id();
            $table->date('booked_on')->comment('Booking date on the statement');
            $table->bigInteger('amount')->comment('Minor units; positive = money in, negative = money out');
            $table->string('reference')->comment('Reference text from the statement; the automatic match looks for an invoice number in it');
            $table->string('fingerprint', 64)->unique()->comment('Hash of date, amount, reference and occurrence; importing a statement twice adds nothing');
            $table->foreignId('payment_id')->nullable()->unique()->comment('Matched payment; unique, so a payment matches one bank line')->constrained();
            $table->string('match_method', 10)->nullable()->comment('auto (ReconcileBankTransactions) or manual (chosen by a person)');
            $table->foreignId('matched_by')->nullable()->comment('User who matched it manually; null for automatic matches')->constrained('users')->restrictOnDelete();
            $table->timestamp('reconciled_at')->nullable()->index()->comment('When it was matched; null = still open');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
    }
};
