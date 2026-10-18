<?php

namespace App\Models\Concerns;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Writes an ActivityLog row whenever the model is created or updated, from
 * any write path (API, admin panel, seeders, tinker). Raw values are stored,
 * not casts, so the log reads the same whatever the model code becomes.
 */
trait RecordsActivity
{
    protected static function bootRecordsActivity(): void
    {
        static::created(function (Model $model) {
            ActivityLog::record('created', $model, array_diff_key($model->getAttributes(), self::untracked()));
        });

        // Runs after the UPDATE, before the original values are synced, so
        // getRawOriginal() still returns the old values.
        static::updated(function (Model $model) {
            $changes = [];

            foreach (array_diff_key($model->getChanges(), self::untracked()) as $field => $new) {
                $changes[$field] = ['old' => $model->getRawOriginal($field), 'new' => $new];
            }

            if ($changes !== []) {
                ActivityLog::record('updated', $model, $changes);
            }
        });
    }

    /**
     * @return array<string, true>
     */
    private static function untracked(): array
    {
        return ['id' => true, 'created_at' => true, 'updated_at' => true];
    }
}
