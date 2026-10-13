<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/** Une pièce jointe d'un communiqué, sur le disque `local` (non public). */
class AnnouncementAttachment extends Model
{
    use HasUuid;

    protected $fillable = ['announcement_id', 'original_name', 'mime_type', 'size', 'path'];

    protected $casts = ['size' => 'integer'];

    public function announcement()
    {
        return $this->belongsTo(Announcement::class);
    }
}
