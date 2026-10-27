<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** Un lieu populaire de la recherche de lieux (spec 2026-10-27-recherche-de-lieux, §3.1). */
class PopularPlace extends Model
{
    use HasUuid;

    protected $fillable = ['name', 'landmark', 'aliases', 'lat', 'lng', 'is_active', 'created_by', 'updated_by'];

    protected $casts = [
        'aliases' => 'array',
        'lat' => 'float',
        'lng' => 'float',
        'is_active' => 'boolean',
    ];

    protected $attributes = ['aliases' => '[]', 'is_active' => true];
}
