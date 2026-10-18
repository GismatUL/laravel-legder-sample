<?php

namespace App\Models;

use App\Enums\OutboxStatus;
use Illuminate\Database\Eloquent\Model;

class OutboxMessage extends Model
{
    protected $guarded = ['id'];

    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'status' => OutboxStatus::class,
            'attempts' => 'integer',
            'claimed_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }
}
