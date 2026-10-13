<?php

namespace App\Domains\Announcement\Application\Actions;

use App\Domains\Announcement\Application\Data\MyAnnouncementListItemData;
use App\Domains\Announcement\Application\Data\MyAnnouncementPageData;
use App\Domains\Announcement\Domain\AnnouncementStatus;
use App\Models\AnnouncementRecipient;
use App\Models\User;
use App\Shared\Data\PaginationData;
use App\Shared\Http\ListQuery;
use Carbon\Carbon;

/** Les communiqués reçus et non retirés, du plus récent au plus ancien. */
final class ListMyAnnouncements
{
    /** @param  array<string, mixed>  $params */
    public function __invoke(User $user, array $params = []): MyAnnouncementPageData
    {
        $query = AnnouncementRecipient::query()
            ->join('announcements', 'announcements.id', '=', 'announcement_recipients.announcement_id')
            ->where('announcement_recipients.user_id', $user->id)
            ->where('announcements.status', AnnouncementStatus::Sent->value)
            ->orderByDesc('announcements.sent_at')
            ->select('announcement_recipients.*', 'announcements.title as a_title', 'announcements.sent_at as a_sent_at');

        $page = ListQuery::paginate($query, $params);

        return new MyAnnouncementPageData(
            announcements: collect($page->items())->map(fn (AnnouncementRecipient $r) => new MyAnnouncementListItemData(
                id: $r->announcement_id,
                title: (string) $r->a_title,
                sentAt: Carbon::parse($r->a_sent_at)->toIso8601String(),
                isRead: $r->read_at !== null,
            ))->all(),
            pagination: PaginationData::fromPaginator($page),
        );
    }
}
