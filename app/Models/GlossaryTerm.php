<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Un terme du glossaire des propriétaires : définition en Markdown, formule facultative ; masqué tant que `is_visible` est faux. */
class GlossaryTerm extends Model
{
    use HasUuid;

    protected $fillable = ['term', 'definition', 'formula', 'is_visible'];

    protected $casts = ['is_visible' => 'boolean'];

    protected static function booted(): void
    {
        static::saving(fn (self $term) => $term->term_key = self::keyOf($term->term));
    }

    /** Le terme sans casse ni accents : l'unicité et l'ordre alphabétique. */
    public static function keyOf(string $term): string
    {
        return mb_strtolower(trim(Str::ascii(str_replace(['’', '‘'], "'", $term))));
    }
}
