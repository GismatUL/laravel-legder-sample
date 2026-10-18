<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->comment('Jobs that failed on their last attempt; resend with queue:retry');
            $table->id();
            $table->string('uuid')->unique()->comment('Job UUID, used by queue:retry and queue:forget');
            $table->string('connection')->comment('Queue connection the job ran on');
            $table->string('queue')->comment('Queue name');
            $table->longText('payload')->comment('Serialized job, so it can be retried');
            $table->longText('exception')->comment('Exception and stack trace of the last attempt');
            $table->timestamp('failed_at')->useCurrent()->comment('When the last attempt failed');

            $table->index(['connection', 'queue', 'failed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_jobs');
    }
};
