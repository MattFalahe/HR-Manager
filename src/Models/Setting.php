<?php

namespace HrManager\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Global key-value setting store. Per-corp scoping was specced for v1.0.0
 * but never used; dropped from v1.0.0 to keep the API tight.
 */
class Setting extends Model
{
    protected $table = 'hr_manager_settings';

    protected $fillable = [
        'key',
        'value',
        'type',
        'description',
    ];

    public static function getValue(string $key, $default = null)
    {
        $setting = static::where('key', $key)->first();
        if (!$setting) {
            return $default;
        }
        return self::castValue($setting->value, $setting->type);
    }

    public static function setValue(string $key, $value, ?string $type = null, ?string $description = null): void
    {
        if (in_array($type, ['array', 'json'], true) || is_array($value)) {
            $storedValue = json_encode($value);
        } else {
            $storedValue = (string) $value;
        }

        $data = ['value' => $storedValue];
        if ($type) {
            $data['type'] = $type;
        }
        if ($description) {
            $data['description'] = $description;
        }

        static::updateOrCreate(['key' => $key], $data);
    }

    private static function castValue($value, string $type)
    {
        return match ($type) {
            'integer', 'int'  => (int) $value,
            'float', 'double' => (float) $value,
            'boolean', 'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json', 'array'   => json_decode($value, true),
            default           => $value,
        };
    }
}
