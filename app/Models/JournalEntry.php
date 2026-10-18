<?php

namespace App\Models;

use App\Exceptions\LedgerException;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A posted journal entry is immutable. Mistakes are corrected with a
 * reversing entry, never by editing or deleting history.
 */
class JournalEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'posted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw LedgerException::immutable());
        static::deleting(fn () => throw LedgerException::immutable());
    }

    public function getReferenceAttribute(): string
    {
        return 'JE-'.str_pad((string) $this->getKey(), 6, '0', STR_PAD_LEFT);
    }

    /** @return BelongsTo<User, $this> */
    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /** @return HasMany<JournalLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /** @return BelongsTo<JournalEntry, $this> */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /** @return HasOne<JournalEntry, $this> */
    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function totalDebits(): Money
    {
        return Money::of((int) $this->lines->sum('debit'));
    }

    public function totalCredits(): Money
    {
        return Money::of((int) $this->lines->sum('credit'));
    }
}
