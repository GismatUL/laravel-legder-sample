<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            // auto (ReconcileBankTransactions) or manual (a person chose the payment)
            $table->string('match_method', 10)->nullable()->after('payment_id');
            $table->foreignId('matched_by')->nullable()->after('match_method')->constrained('users')->restrictOnDelete();
        });

        // Every match before this migration was made by the automatic job.
        DB::table('bank_transactions')->whereNotNull('payment_id')->update(['match_method' => 'auto']);
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('matched_by');
            $table->dropColumn('match_method');
        });
    }
};
