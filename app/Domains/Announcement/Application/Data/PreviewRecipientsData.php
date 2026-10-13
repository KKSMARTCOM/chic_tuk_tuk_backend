<?php

namespace App\Domains\Announcement\Application\Data;

use App\Domains\Announcement\Application\Actions\ResolveAnnouncementRecipients;
use App\Shared\Data\BaseData;
use Illuminate\Validation\Rule;

/** POST /admin/announcements/recipients/preview */
final class PreviewRecipientsData extends BaseData
{
    public function __construct(
        /** @var string[] */
        public array $targetProfiles = [],
        /** @var string[] */
        public array $targetUserIds = [],
    ) {}

    public static function rules(): array
    {
        return [
            'target_profiles' => ['present', 'array'],
            'target_profiles.*' => ['string', Rule::in(ResolveAnnouncementRecipients::PROFILES)],
            'target_user_ids' => ['present', 'array', 'max:500'],
            'target_user_ids.*' => ['uuid', 'exists:users,id'],
        ];
    }
}
