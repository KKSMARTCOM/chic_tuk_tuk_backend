<?php

namespace App\Domains\Announcement\Application\Data;

use App\Domains\Announcement\Application\Actions\ResolveAnnouncementRecipients;
use App\Shared\Data\BaseData;
use Illuminate\Validation\Rule;

/** POST /admin/announcements, PUT /admin/announcements/{id} — un brouillon. */
final class SaveAnnouncementData extends BaseData
{
    public function __construct(
        public string $title,
        public ?string $body,
        /** @var list<string> */
        public array $targetProfiles = [],
        /** @var list<string> */
        public array $targetUserIds = [],
    ) {}

    public static function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'body' => ['nullable', 'string', 'max:20000'],
            'target_profiles' => ['present', 'array'],
            'target_profiles.*' => ['string', Rule::in(ResolveAnnouncementRecipients::PROFILES)],
            'target_user_ids' => ['present', 'array', 'max:500'],
            'target_user_ids.*' => ['uuid', 'distinct', 'exists:users,id'],
        ];
    }

    public static function messages(): array
    {
        return [
            'title.required' => 'Le titre est obligatoire.',
            'title.max' => 'Le titre fait 150 caractères au plus.',
            'body.max' => 'Le texte fait 20 000 caractères au plus.',
            'target_user_ids.*.exists' => 'Un des destinataires choisis n\'existe plus.',
        ];
    }
}
