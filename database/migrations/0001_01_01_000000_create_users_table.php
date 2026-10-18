<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->comment('People who log in to the admin panel or request API tokens');
            $table->id();
            $table->string('name')->comment('Display name, shown as the author of postings');
            $table->string('email')->unique()->comment('Login for the admin panel and the token endpoint');
            $table->string('role', 20)->default('viewer')->comment('admin, accountant or viewer (App\Enums\UserRole); new users get the least access');
            $table->timestamp('email_verified_at')->nullable()->comment('When the address was confirmed; null = not confirmed');
            $table->string('password')->comment('Password hash, never the plain password');
            $table->rememberToken()->comment('Token for the "remember me" login cookie');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
