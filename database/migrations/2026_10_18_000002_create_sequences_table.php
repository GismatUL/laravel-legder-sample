<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequences', function (Blueprint $table) {
            $table->comment('Counters for gap-free numbers, locked with SELECT ... FOR UPDATE (SequenceService)');
            $table->string('name', 50)->primary()->comment('Sequence name, e.g. invoice');
            $table->unsignedBigInteger('next_value')->default(1)->comment('Number the next caller gets');
            $table->timestamps();
        });

        // Created up front: inserting it on first use, while several requests
        // issue invoices at once, caused deadlocks (see SequenceService).
        DB::table('sequences')->insert([
            'name' => 'invoice',
            'next_value' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('sequences');
    }
};
