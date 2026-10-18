<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Creates the invoice counter up front, so SequenceService never has to
 * insert it while several requests issue invoices at the same time.
 * A database that already issued invoices keeps its counter.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('sequences')->insertOrIgnore([
            'name' => 'invoice',
            'next_value' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Keeps the counter: deleting it would restart invoice numbering at 1.
    }
};
