<?php

namespace App\Models;

use App\Domains\Announcement\Domain\AnnouncementStatus;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Un communiqué de l'administration (spec 2026-10-13).
 *
 * ⚠️ `target_profiles` et `target_user_ids` sont le CIBLAGE choisi, pas les destinataires :
 * ceux-ci sont calculés une fois, à l'envoi, dans `announcement_recipients`.
 */
class Announcement extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = [
        'title', 'body', 'status', 'target_profiles', 'target_user_ids',
        'created_by', 'updated_by', 'sent_by', 'sent_at', 'withdrawn_by', 'withdrawn_at', 'last_reminded_at',
    ];

    protected $casts = [
        'status' => AnnouncementStatus::class,
        'target_profiles' => 'array',
        'target_user_ids' => 'array',
        'sent_at' => 'datetime',
        'withdrawn_at' => 'datetime',
        'last_reminded_at' => 'datetime',
    ];

    public function attachments()
    {
        return $this->hasMany(AnnouncementAttachment::class)->orderBy('created_at');
    }

    public function recipients()
    {
        return $this->hasMany(AnnouncementRecipient::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function sentBy()
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function isDraft(): bool
    {
        return $this->status === AnnouncementStatus::Draft;
    }
}
