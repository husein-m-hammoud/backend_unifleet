<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $primaryKey = 'key';
    public $incrementing  = false;
    protected $keyType    = 'string';

    protected $fillable = ['key', 'value', 'type', 'label', 'description', 'group'];

    /** Cast the stored string value to its declared type. */
    public function getCastedValueAttribute(): mixed
    {
        return match ($this->type) {
            'integer' => (int)   $this->value,
            'float'   => (float) $this->value,
            'boolean' => filter_var($this->value, FILTER_VALIDATE_BOOLEAN),
            default   => $this->value,
        };
    }

    /** Get a setting value by key, with an optional default. */
    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = static::find($key);
        return $setting ? $setting->casted_value : $default;
    }
}
