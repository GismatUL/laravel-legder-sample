<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // admin, accountant, viewer (see App\Enums\UserRole). New users get the least access.
            $table->string('role', 20)->default('viewer')->after('email');
        });

        // Before this migration every user had full access. Keep that for
        // existing users, so the upgrade doesn't lock anyone out.
        DB::table('users')->update(['role' => 'admin']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
