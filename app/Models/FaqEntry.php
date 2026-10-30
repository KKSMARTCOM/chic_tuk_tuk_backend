<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Une question de l'ancienne FAQ, remplacée par `GlossaryTerm` le 2026-10-30. Reste pour les lignes
 * du journal qui la désignent, et le temps qu'une migration retire la table.
 */
class FaqEntry extends Model
{
    use HasUuid;

    protected $fillable = ['faq_section_id', 'question', 'answer', 'position', 'is_visible'];

    protected $casts = ['position' => 'integer', 'is_visible' => 'boolean'];

    public function section()
    {
        return $this->belongsTo(FaqSection::class, 'faq_section_id');
    }
}
