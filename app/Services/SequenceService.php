<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Hands out gap-free sequential numbers.
 *
 * An auto-increment id can skip values (rolled back inserts), and
 * MAX(number) + 1 races under concurrency. Locking a counter row inside
 * the caller's transaction gives each caller a unique, consecutive value,
 * and a rollback also rolls back the counter.
 */
class SequenceService
{
    public function next(string $name): int
    {
        return DB::transaction(function () use ($name) {
            DB::table('sequences')->insertOrIgnore([
                'name' => $name,
                'next_value' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $value = (int) DB::table('sequences')
                ->where('name', $name)
                ->lockForUpdate()
                ->value('next_value');

            DB::table('sequences')
                ->where('name', $name)
                ->update(['next_value' => $value + 1, 'updated_at' => now()]);

            return $value;
        });
    }
}
