<?php

namespace App\Support\OpenApi;

use App\Support\Money;
use Dedoc\Scramble\Extensions\TypeToSchemaExtension;
use Dedoc\Scramble\Support\Generator\Types\ObjectType as OpenApiObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;

/**
 * Documents Money as {"amount": "118.00", "currency": "AZN"} in the OpenAPI spec,
 * matching Money::jsonSerialize().
 */
class MoneyToSchema extends TypeToSchemaExtension
{
    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType && $type->isInstanceOf(Money::class);
    }

    public function toSchema(Type $type): OpenApiObjectType
    {
        return (new OpenApiObjectType)
            ->addProperty('amount', (new StringType)->setDescription('Decimal string, never a float')->example('118.00'))
            ->addProperty('currency', (new StringType)->example('AZN'))
            ->setRequired(['amount', 'currency']);
    }
}
