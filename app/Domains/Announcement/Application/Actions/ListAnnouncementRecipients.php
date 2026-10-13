<?php

namespace App\Domains\Announcement\Application\Actions;

use App\Domains\Announcement\Application\Data\AnnouncementRecipientData;
use App\Domains\Announcement\Application\Data\AnnouncementRecipientPageData;
use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\User;
use App\Shared\Data\PaginationData;
use App\Shared\Http\ListQuery;

/** GET /admin/announcements/{id}/recipients — `filter[read]` = `read` | `unread`. Non-lus d'abord. */
final class ListAnnouncementRecipients
{
    /** @param  array<string, mixed>  $params */
    public function __invoke(string $id, array $params = []): AnnouncementRecipientPageData
    {
        $announcement = Announcement::findOrFail($id);
        $read = $params['filter']['read'] ?? null;

        $query = AnnouncementRecipient::query()
            ->with('user')
            ->where('announcement_id', $announcement->id)
            ->when($read === 'read', fn ($q) => $q->whereNotNull('read_at'))
            ->when($read === 'unread', fn ($q) => $q->whereNull('read_at'))
            ->orderByRaw('read_at IS NOT NULL')
            ->orderBy(User::select('name')->whereColumn('users.id', 'announcement_recipients.user_id'));

        $page = ListQuery::paginate($query, $params);

        return new AnnouncementRecipientPageData(
            recipients: collect($page->items())->map(fn (AnnouncementRecipient $r) => new AnnouncementRecipientData(
                userId: $r->user_id,
                name: $r->user?->name,
                profil: $r->profil,
                readAt: $r->read_at?->toIso8601String(),
                emailedAt: $r->emailed_at?->toIso8601String(),
                emailSkipped: $r->email_skipped,
                remindedCount: $r->reminded_count,
            ))->all(),
            pagination: PaginationData::fromPaginator($page),
        );
    }
}
