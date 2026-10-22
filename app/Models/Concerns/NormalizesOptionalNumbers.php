<?php

namespace App\Models\Concerns;

trait NormalizesOptionalNumbers
{
    public static function bootNormalizesOptionalNumbers(): void
    {
        static::saving(function ($model): void {
            $attributes = $model->getAttributes();

            foreach ($model->optionalNumbers as $attribute) {
                if (($attributes[$attribute] ?? null) === null || $attributes[$attribute] === '') {
                    $model->setAttribute($attribute, 0);
                }
            }
        });
    }
}
