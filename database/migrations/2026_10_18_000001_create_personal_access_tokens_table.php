<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->comment('API tokens (Sanctum), issued by POST /api/v1/auth/tokens');
            $table->id();
            $table->string('tokenable_type')->comment('Owner model alias: user');
            $table->unsignedBigInteger('tokenable_id')->comment('Owner id (users.id)');
            $table->text('name')->comment('Device name the client sent, e.g. "cli"');
            $table->string('token', 64)->unique()->comment('SHA-256 hash of the token; the plain token is shown only once');
            $table->text('abilities')->nullable()->comment('JSON list, e.g. ["ledger:read","ledger:write"]; never more than the role allows');
            $table->timestamp('last_used_at')->nullable()->comment('Last request made with the token');
            $table->timestamp('expires_at')->nullable()->index()->comment('After this the token is rejected (LEDGER_API_TOKEN_DAYS)');
            $table->timestamps();

            $table->index(['tokenable_type', 'tokenable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
