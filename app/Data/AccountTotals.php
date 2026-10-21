<?php

namespace App\Data;

use App\Enums\AccountType;

final readonly class AccountTotals
{
    public function __construct(
        public string $code,
        public string $name,
        public AccountType $type,
        public int $debits,
        public int $credits,
    ) {}

    /**
     * @param  array{code: string, name: string, type: string, debits: int, credits: int}  $row
     */
    public static function fromArray(array $row): self
    {
        return new self($row['code'], $row['name'], AccountType::from($row['type']), $row['debits'], $row['credits']);
    }

    /**
     * @return array{code: string, name: string, type: string, debits: int, credits: int}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type->value,
            'debits' => $this->debits,
            'credits' => $this->credits,
        ];
    }
}
