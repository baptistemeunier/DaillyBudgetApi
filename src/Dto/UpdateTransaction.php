<?php

namespace App\Dto;

use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\TypeInfo\Type\BuiltinType;
use Symfony\Component\TypeInfo\Type\CollectionType;
use Symfony\Component\TypeInfo\Type\GenericType;
use Symfony\Component\TypeInfo\Type\NullableType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\TypeIdentifier;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * PATCH /transactions/{id}: the categorization of a transaction.
 */
class UpdateTransaction
{
    /**
     * Replaces all the splits (each one becomes "manual"; to confirm the splits set by a rule, use confirm instead).
     * null: splits unchanged (e.g. only rememberCategory); []: uncategorized.
     *
     * Explicit nativeType: without phpdocumentor/type-resolver, Symfony does not read the @var below
     * (the items would stay arrays).
     *
     * @var list<UpdateTransactionSplit>|null
     */
    #[ApiProperty(nativeType: new NullableType(new CollectionType(
        new GenericType(new BuiltinType(TypeIdentifier::ARRAY), new BuiltinType(TypeIdentifier::INT), new ObjectType(UpdateTransactionSplit::class)),
        isList: true,
    )))]
    #[Assert\Valid]
    public ?array $splits = null;

    /**
     * "Always categorize like this": with a single split of the whole amount, creates (or updates) the rule
     * "merchant key + direction → category" and applies it to the similar transactions.
     */
    public bool $rememberCategory = false;

    /**
     * Confirms the splits set by a categorization rule: they become "confirmed" (the rule link is kept, for the stats).
     * Cannot be combined with splits.
     */
    public bool $confirm = false;

    /**
     * Hides (true) or shows again (false) the transaction: a hidden transaction stays listed but is left out
     * of the budget and of the "to categorize" counters. null: unchanged.
     */
    public ?bool $hidden = null;
}
