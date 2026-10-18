<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->comment('Queued jobs waiting for a worker (QUEUE_CONNECTION=database)');
            $table->id();
            $table->string('queue')->index()->comment('Queue name, e.g. default');
            $table->longText('payload')->comment('Serialized job class and its data');
            $table->unsignedSmallInteger('attempts')->comment('How many times a worker has started the job');
            $table->unsignedInteger('reserved_at')->nullable()->comment('Unix time a worker took the job; null = waiting');
            $table->unsignedInteger('available_at')->comment('Unix time from which the job may run (delay, backoff)');
            $table->unsignedInteger('created_at')->comment('Unix time the job was queued');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs');
    }
};
