<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->comment('Pending password resets (Laravel)');
            $table->string('email')->primary()->comment('User the reset was requested for');
            $table->string('token')->comment('Hash of the token sent by email');
            $table->timestamp('created_at')->nullable()->comment('When the reset was requested; old tokens expire');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
