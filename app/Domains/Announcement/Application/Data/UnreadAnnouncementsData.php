<?php

namespace App\Domains\Announcement\Application\Data;

use App\Shared\Data\BaseData;

/** Le badge du menu et le bandeau. `only_id` : le seul non-lu, pour y mener directement. */
final class UnreadAnnouncementsData extends BaseData
{
    public function __construct(
        public int $count,
        public ?string $onlyId,
    ) {}
}
