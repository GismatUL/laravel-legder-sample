<?php

namespace App\Support;

final class TokenAbility
{
    public const READ = 'ledger:read';

    public const WRITE = 'ledger:write';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::READ, self::WRITE];
    }
}
