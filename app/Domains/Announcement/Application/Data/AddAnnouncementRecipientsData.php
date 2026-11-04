<?php

namespace App\Domains\Announcement\Application\Data;

use App\Shared\Data\BaseData;

/** POST /admin/announcements/{id}/recipients — des personnes, ou les nouveaux du ciblage. */
final class AddAnnouncementRecipientsData extends BaseData
{
    public function __construct(
        /** @var string[] */
        public array $userIds = [],
        public bool $newFromTargeting = false,
    ) {}

    public static function rules(): array
    {
        return [
            'user_ids' => ['required_unless:new_from_targeting,true', 'array', 'max:100'],
            'user_ids.*' => ['uuid'],
            'new_from_targeting' => ['sometimes', 'boolean'],
        ];
    }
}
