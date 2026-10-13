<?php

namespace App\Domains\Announcement\Application\Actions;

use App\Domains\Announcement\Application\Data\AdminAnnouncementData;
use App\Domains\Announcement\Application\Data\AnnouncementAttachmentData;
use App\Domains\Announcement\Application\Data\AnnouncementUserData;
use App\Domains\Announcement\Domain\AnnouncementBody;
use App\Models\Announcement;
use App\Models\AnnouncementAttachment;
use App\Models\User;

/** GET /admin/announcements/{id} */
final class ShowAnnouncement
{
    public function __invoke(string $id): AdminAnnouncementData
    {
        $a = Announcement::query()
            ->with(['attachments', 'createdBy', 'sentBy'])
            ->withCount(['recipients', 'recipients as read_count' => fn ($q) => $q->whereNotNull('read_at')])
            ->findOrFail($id);

        $users = User::query()->whereIn('id', $a->target_user_ids ?? [])->orderBy('name')->get();

        return new AdminAnnouncementData(
            id: $a->id,
            title: $a->title,
            body: (string) $a->body,
            bodyHtml: AnnouncementBody::toHtml((string) $a->body),
            status: $a->status->value,
            statusLabel: $a->status->label(),
            targetProfiles: $a->target_profiles ?? [],
            targetUsers: $users->map(fn (User $u) => AnnouncementUserData::fromUser($u))->all(),
            attachments: $a->attachments->map(fn (AnnouncementAttachment $f) => AnnouncementAttachmentData::fromModel($f))->all(),
            createdByName: $a->createdBy?->name,
            sentByName: $a->sentBy?->name,
            sentAt: $a->sent_at?->toIso8601String(),
            withdrawnAt: $a->withdrawn_at?->toIso8601String(),
            lastRemindedAt: $a->last_reminded_at?->toIso8601String(),
            recipientsCount: (int) $a->recipients_count,
            readCount: (int) $a->read_count,
        );
    }
}
