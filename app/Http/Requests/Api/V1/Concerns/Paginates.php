<?php

namespace App\Http\Requests\Api\V1\Concerns;

trait Paginates
{
    /**
     * @return array<string, list<string>>
     */
    protected function paginationRules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function perPage(): int
    {
        return $this->integer('per_page', 25);
    }
}
