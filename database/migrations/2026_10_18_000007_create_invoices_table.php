<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->comment('Sales invoices. Only drafts can change; issued invoices are corrected by voiding');
            $table->id();
            $table->foreignId('customer_id')->comment('Customer billed')->constrained()->restrictOnDelete();
            $table->string('number', 30)->nullable()->unique()->comment('Gap-free invoice number, e.g. INV-000001; null while draft');
            $table->string('status', 20)->default('draft')->index()->comment('draft, issued, partially_paid, paid or void (App\Enums\InvoiceStatus)');
            $table->date('issue_date')->nullable()->comment('Set when the invoice is issued');
            $table->date('due_date')->nullable()->comment('Payment due date; defaults to issue date + payment terms');
            $table->unsignedBigInteger('subtotal')->default(0)->comment('Sum of line amounts before VAT, minor units');
            $table->unsignedBigInteger('tax_total')->default(0)->comment('Sum of line VAT, minor units (rounded per line)');
            $table->unsignedBigInteger('total')->default(0)->comment('subtotal + tax_total, minor units');
            $table->unsignedBigInteger('amount_paid')->default(0)->comment('Sum of recorded payments, minor units; never above total');
            $table->foreignId('journal_entry_id')->nullable()->comment('Ledger entry posted when the invoice was issued')->constrained();
            $table->foreignId('void_entry_id')->nullable()->comment('Reversing entry posted when the invoice was voided')->constrained('journal_entries');
            $table->text('notes')->nullable()->comment('Free text printed on the invoice');
            $table->timestamps();
        });

        // Back up InvoiceService and PaymentService: consistent totals, no overpayment.
        // Enforced by MySQL 8.0.16+; SQLite can't add CHECK constraints to an existing table.
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE invoices ADD CONSTRAINT chk_invoices_total CHECK (total = subtotal + tax_total)');
            DB::statement('ALTER TABLE invoices ADD CONSTRAINT chk_invoices_not_overpaid CHECK (amount_paid <= total)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
