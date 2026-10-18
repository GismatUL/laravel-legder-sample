<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->comment('Chart of accounts');
            $table->id();
            $table->string('code', 20)->unique()->comment('Account number, e.g. 1100; locked once the account has postings');
            $table->string('name')->comment('Account name, e.g. Accounts receivable');
            $table->string('type', 20)->index()->comment('asset, liability, equity, revenue or expense (App\Enums\AccountType); locked once the account has postings');
            $table->boolean('is_active')->default(true)->comment('Inactive accounts accept no new postings; accounts are never deleted');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
