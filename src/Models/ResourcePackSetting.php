<?php

namespace ItZRealMoritz\ResourcePackHosting\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property ?string $value
 */
class ResourcePackSetting extends Model
{
    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['key', 'value'];
}
