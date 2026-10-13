<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value'])]
class IntegrationSetting extends Model
{
    public static function getValue(string $key, ?string $default = null): ?string
    {
        $record = static::where('key', $key)->first();

        return $record ? $record->value : $default;
    }

    public static function setValue(string $key, ?string $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }
}
