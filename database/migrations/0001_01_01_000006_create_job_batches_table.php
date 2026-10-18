<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_batches', function (Blueprint $table) {
            $table->comment('Progress of job batches (Bus::batch); not used by the app yet');
            $table->string('id')->primary()->comment('Batch UUID');
            $table->string('name')->comment('Batch name');
            $table->integer('total_jobs')->comment('Jobs in the batch');
            $table->integer('pending_jobs')->comment('Jobs not finished yet');
            $table->integer('failed_jobs')->comment('Jobs that failed');
            $table->longText('failed_job_ids')->comment('JSON list of the failed job ids');
            $table->mediumText('options')->nullable()->comment('Serialized batch callbacks and options');
            $table->integer('cancelled_at')->nullable()->comment('Unix time the batch was cancelled');
            $table->integer('created_at')->comment('Unix time the batch was created');
            $table->integer('finished_at')->nullable()->comment('Unix time the last job finished');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_batches');
    }
};
