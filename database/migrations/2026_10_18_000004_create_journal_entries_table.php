<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->comment('General ledger entries. Immutable once posted: corrections are reversing entries');
            $table->id();
            $table->date('entry_date')->index()->comment('Accounting date the entry belongs to (reports filter on it)');
            $table->string('description')->comment('What the entry is for, e.g. Invoice INV-000001 issued to Acme');
            $table->string('idempotency_key', 100)->nullable()->unique()->comment('Makes retries safe: e.g. payment:<key>, invoice:<id>:issue, manual:<key>');
            $table->foreignId('reversal_of_id')->nullable()->unique()->comment('Entry this one reverses; unique, so an entry is reversed at most once')->constrained('journal_entries');
            $table->string('source_type')->nullable()->comment('Document that created the entry: invoice; null = manual entry');
            $table->unsignedBigInteger('source_id')->nullable()->comment('Id of that document');
            $table->timestamp('posted_at')->comment('When the entry was written');
            $table->foreignId('posted_by')->nullable()->comment('User who posted it; null for jobs, seeders and the CLI')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }
};
