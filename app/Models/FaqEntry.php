<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** Une question de la FAQ et sa réponse en Markdown ; masquée tant que `is_visible` est faux. */
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
