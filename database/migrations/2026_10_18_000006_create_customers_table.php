<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->comment('Customers that receive invoices');
            $table->id();
            $table->string('name')->comment('Customer or company name, printed on invoices');
            $table->string('email')->nullable()->comment('Where invoice emails go; null = no email is sent');
            $table->string('tax_id', 20)->nullable()->comment('Customer tax number (VÖEN), optional');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
