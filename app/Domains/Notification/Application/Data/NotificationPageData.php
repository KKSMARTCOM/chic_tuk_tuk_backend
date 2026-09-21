<?php

namespace App\Domains\Notification\Application\Data;

use App\Models\Notification;
use App\Shared\Data\BaseData;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Une page de la cloche, et le nombre de non lues.
 *
 * `unreadCount` voyage AVEC la liste plutôt que par un endpoint séparé — le Blade en
 * avait un (`/notifications/unread-count`), appelé en boucle par la cloche. Une API n'a
 * pas besoin d'une requête pour compter ce que la précédente vient de renvoyer.
 *
 * ⚠️ Le compte porte sur TOUTES les non lues, pas sur celles de la page : la pastille
 * doit afficher 23 même si la page n'en montre que 15.
 */
final class NotificationPageData extends BaseData
{
    public function __construct(
        /** @var array<int, NotificationData> */
        public array $data,
        public int $unreadCount,
        public int $currentPage,
        public int $lastPage,
        public int $perPage,
        public int $total,
    ) {}

    public static function fromPaginator(LengthAwarePaginator $page, int $unreadCount): self
    {
        return new self(
            data: collect($page->items())
                ->map(fn (Notification $n) => NotificationData::fromModel($n))
                ->all(),
            unreadCount: $unreadCount,
            currentPage: $page->currentPage(),
            lastPage: $page->lastPage(),
            perPage: $page->perPage(),
            total: $page->total(),
        );
    }
}
