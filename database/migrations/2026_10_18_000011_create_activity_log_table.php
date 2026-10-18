<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $table) {
            $table->comment('Append-only audit log: changes to accounts and customers, manual bank matches');
            $table->id();
            $table->foreignId('user_id')->nullable()->comment('Who did it; null for seeders and the CLI')->constrained()->restrictOnDelete();
            $table->string('event', 30)->comment('created, updated or matched');
            $table->string('subject_type')->comment('Record type: account, customer or bank_transaction');
            $table->unsignedBigInteger('subject_id')->comment('Id of that record');
            $table->json('changes')->nullable()->comment('Created: the attributes. Updated: {field: {old, new}}. Matched: payment and invoice');
            $table->timestamp('created_at')->useCurrent()->index()->comment('When it happened');

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
