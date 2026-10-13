<?php

namespace App\Domains\Announcement\Application\Actions;

use App\Domains\Announcement\Application\Data\AdminAnnouncementListItemData;
use App\Domains\Announcement\Application\Data\AdminAnnouncementPageData;
use App\Models\Announcement;
use App\Shared\Data\PaginationData;
use App\Shared\Http\ListQuery;
use Spatie\QueryBuilder\QueryBuilder;

/** GET /admin/announcements — `filter[status]`, `sort` (created_at, sent_at), `page`, `per_page`. */
final class ListAnnouncements
{
    /** @param  array<string, mixed>  $params */
    public function __invoke(array $params = []): AdminAnnouncementPageData
    {
        $query = ListQuery::build(
            Announcement::query()
                ->with('createdBy')
                ->withCount(['recipients', 'recipients as read_count' => fn ($q) => $q->whereNotNull('read_at')]),
            $params,
            fn (QueryBuilder $q) => $q
                ->allowedFilters([ListQuery::exact('status')])
                ->allowedSorts(['created_at', 'sent_at'])
                ->defaultSort('-created_at'),
        );
        $page = ListQuery::paginate($query, $params);

        return new AdminAnnouncementPageData(
            announcements: collect($page->items())->map(fn (Announcement $a) => new AdminAnnouncementListItemData(
                id: $a->id,
                title: $a->title,
                status: $a->status->value,
                statusLabel: $a->status->label(),
                createdByName: $a->createdBy?->name,
                sentAt: $a->sent_at?->toIso8601String(),
                recipientsCount: (int) $a->recipients_count,
                readCount: (int) $a->read_count,
            ))->all(),
            pagination: PaginationData::fromPaginator($page),
        );
    }
}
