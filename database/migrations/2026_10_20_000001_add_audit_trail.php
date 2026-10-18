<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who posted the entry. Null for system postings (queued jobs, seeders, CLI).
        // Restrict: a user with postings can't be deleted, the history keeps its author.
        Schema::table('journal_entries', function (Blueprint $table) {
            $table->foreignId('posted_by')->nullable()->after('posted_at')->constrained('users')->restrictOnDelete();
        });

        // Append-only record of changes to master data (accounts, customers)
        // and of manual decisions (bank matches). Ledger postings don't need
        // it: journal entries are immutable and carry posted_by themselves.
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('event', 30);
            $table->morphs('subject');
            $table->json('changes')->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');

        Schema::table('journal_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('posted_by');
        });
    }
};
