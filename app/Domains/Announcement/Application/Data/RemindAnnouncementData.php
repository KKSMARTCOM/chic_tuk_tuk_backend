<?php

namespace App\Domains\Announcement\Application\Data;

use App\Shared\Data\BaseData;

/**
 * POST /admin/announcements/{id}/remind (spec 2026-11-04-communiques-relance-ajout) : sans
 * `recipient_ids`, les non-lus ; avec, les lignes cochées, lues ou non. Le mot est facultatif.
 */
final class RemindAnnouncementData extends BaseData
{
    public function __construct(
        /** @var string[]|null Les lignes `announcement_recipients` choisies. */
        public ?array $recipientIds = null,
        public ?string $note = null,
    ) {}

    public static function rules(): array
    {
        return [
            'recipient_ids' => ['sometimes', 'array', 'min:1', 'max:500'],
            'recipient_ids.*' => ['uuid'],
            'note' => ['nullable', 'string', 'max:200'],
        ];
    }

    public static function messages(): array
    {
        return ['recipient_ids.max' => 'Une relance vise 500 destinataires au plus : relancez en plusieurs fois.'];
    }
}
