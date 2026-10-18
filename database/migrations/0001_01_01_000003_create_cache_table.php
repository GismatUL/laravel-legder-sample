<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cache', function (Blueprint $table) {
            $table->comment('Cache entries (CACHE_STORE=database), e.g. rate limiter counters');
            $table->string('key')->primary()->comment('Cache key');
            $table->mediumText('value')->comment('Serialized value');
            $table->bigInteger('expiration')->index()->comment('Unix time after which the entry is ignored');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cache');
    }
};
