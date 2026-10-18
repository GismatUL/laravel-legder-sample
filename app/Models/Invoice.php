<?php

namespace App\Models;

use App\Casts\MoneyCast;
use App\Enums\InvoiceStatus;
use App\Support\Money;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Totals, status and ledger links are only changed by InvoiceService,
 * so they are not mass assignable.
 */
#[Fillable(['customer_id', 'due_date', 'notes'])]
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    protected $attributes = [
        'status' => 'draft',
        'subtotal' => 0,
        'tax_total' => 0,
        'total' => 0,
        'amount_paid' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'issue_date' => 'date',
            'due_date' => 'date',
            'subtotal' => MoneyCast::class,
            'tax_total' => MoneyCast::class,
            'total' => MoneyCast::class,
            'amount_paid' => MoneyCast::class,
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<InvoiceLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function outstanding(): Money
    {
        return $this->total->subtract($this->amount_paid);
    }

    public function isEditable(): bool
    {
        return $this->status === InvoiceStatus::Draft;
    }
}
