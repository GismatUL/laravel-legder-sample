<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_lines', function (Blueprint $table) {
            $table->comment('Debit and credit lines of a journal entry; the debits of an entry equal its credits');
            $table->id();
            $table->foreignId('journal_entry_id')->comment('Entry the line belongs to; an entry with lines cannot be deleted')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->comment('Account posted to')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('debit')->default(0)->comment('Debit amount in minor units (cents); 0 on a credit line');
            $table->unsignedBigInteger('credit')->default(0)->comment('Credit amount in minor units (cents); 0 on a debit line');
            $table->string('memo')->nullable()->comment('Optional note for this line, e.g. the invoice number');
            $table->timestamps();

            $table->index(['account_id', 'journal_entry_id']);
        });

        // Backs up LedgerService: each line is either a debit or a credit.
        // Enforced by MySQL 8.0.16+; SQLite can't add CHECK constraints to an existing table.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE journal_lines ADD CONSTRAINT chk_journal_lines_one_side CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
    }
};
