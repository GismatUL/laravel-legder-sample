<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->comment('Admin panel sessions (SESSION_DRIVER=database)');
            $table->string('id')->primary()->comment('Session id from the cookie');
            $table->foreignId('user_id')->nullable()->index()->comment('Logged-in user; null before login');
            $table->string('ip_address', 45)->nullable()->comment('Client IP, IPv4 or IPv6');
            $table->text('user_agent')->nullable()->comment('Client browser');
            $table->longText('payload')->comment('Serialized session data');
            $table->integer('last_activity')->index()->comment('Unix time of the last request; used to expire sessions');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
