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
            $value = $this->lockCounter($name);

            if ($value === null) {
                // First use of this sequence. Known sequences are created by a
                // migration, so this path doesn't run under load. It must not
                // run on every call: INSERT IGNORE on an existing row takes a
                // shared lock, and two callers upgrading it to the FOR UPDATE
                // lock deadlock each other (found by Tests\Feature\MySql\ConcurrencyTest).
                DB::table('sequences')->insertOrIgnore([
                    'name' => $name,
                    'next_value' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $value = (int) $this->lockCounter($name);
            }

            DB::table('sequences')
                ->where('name', $name)
                ->update(['next_value' => $value + 1, 'updated_at' => now()]);

            return $value;
        });
    }

    private function lockCounter(string $name): ?int
    {
        $value = DB::table('sequences')->where('name', $name)->lockForUpdate()->value('next_value');

        return $value === null ? null : (int) $value;
    }
}
