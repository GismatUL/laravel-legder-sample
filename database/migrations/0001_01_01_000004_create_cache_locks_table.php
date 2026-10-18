<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cache_locks', function (Blueprint $table) {
            $table->comment('Atomic locks, e.g. the unique lock of ReconcileBankTransactions');
            $table->string('key')->primary()->comment('Lock name');
            $table->string('owner')->comment('Random token of the process holding the lock');
            $table->bigInteger('expiration')->index()->comment('Unix time when the lock is released automatically');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cache_locks');
    }
};
