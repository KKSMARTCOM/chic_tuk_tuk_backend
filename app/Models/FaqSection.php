<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** Une rubrique de la FAQ des propriétaires (spec 2026-10-22-faq-proprietaire). */
class FaqSection extends Model
{
    use HasUuid;

    protected $fillable = ['title', 'position'];

    protected $casts = ['position' => 'integer'];

    public function entries()
    {
        return $this->hasMany(FaqEntry::class)->orderBy('position');
    }
}
