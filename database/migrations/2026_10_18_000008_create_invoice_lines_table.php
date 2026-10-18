<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->comment('Lines of an invoice; amounts are recalculated by InvoiceService');
            $table->id();
            $table->foreignId('invoice_id')->comment('Invoice the line belongs to; deleted with a draft invoice')->constrained()->cascadeOnDelete();
            $table->string('description')->comment('What was sold');
            $table->unsignedInteger('quantity')->comment('Whole units');
            $table->unsignedBigInteger('unit_price')->comment('Price per unit before VAT, minor units');
            $table->unsignedInteger('tax_rate_bp')->default(0)->comment('VAT rate in basis points: 1800 = 18%');
            $table->unsignedBigInteger('line_subtotal')->default(0)->comment('quantity x unit_price, minor units');
            $table->unsignedBigInteger('line_tax')->default(0)->comment('VAT of this line, rounded half up, minor units');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
