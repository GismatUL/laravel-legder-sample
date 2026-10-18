<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankTransaction extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'booked_on' => 'date',
            'amount' => MoneyCast::class,
            'reconciled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function matchedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by');
    }

    /** @param  Builder<BankTransaction>  $query */
    public function scopeUnreconciled(Builder $query): void
    {
        $query->whereNull('reconciled_at');
    }
}
