<?php

namespace RiseTechApps\ApiKey\Models\Setting;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $key
 * @property mixed $value
 * @property string|null $updated_by
 */
class Setting extends Model
{
    protected $table = 'api_key_settings';

    protected $fillable = ['key', 'value', 'updated_by'];

    // Criptografado sempre: a maior parte das chaves é credencial de gateway, e
    // decidir campo a campo abriria espaço para uma credencial nova ir em claro.
    protected $casts = ['value' => 'encrypted:json'];

    protected $hidden = ['value'];
}
