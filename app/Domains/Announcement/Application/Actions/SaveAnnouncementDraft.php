<?php

namespace App\Domains\Announcement\Application\Actions;

use App\Domains\Announcement\Application\Data\SaveAnnouncementData;
use App\Domains\Announcement\Domain\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\User;
use App\Shared\Http\ApiException;

/** Créer ou modifier un brouillon (spec 2026-10-13, §4). Envoyé, un communiqué est figé. */
final class SaveAnnouncementDraft
{
    public function __invoke(?Announcement $announcement, SaveAnnouncementData $data, User $by): Announcement
    {
        if ($announcement) {
            self::assertDraft($announcement);
        }

        $announcement ??= new Announcement(['status' => AnnouncementStatus::Draft, 'created_by' => $by->id]);

        $announcement->fill([
            'title' => trim($data->title),
            'body' => (string) $data->body,
            'target_profiles' => array_values(array_unique($data->targetProfiles)),
            'target_user_ids' => array_values(array_unique($data->targetUserIds)),
            'updated_by' => $by->id,
        ])->save();

        return $announcement;
    }

    public static function assertDraft(Announcement $announcement): void
    {
        if (! $announcement->isDraft()) {
            throw new ApiException(409, 'ANNOUNCEMENT_NOT_DRAFT', 'Ce communiqué a été envoyé : il ne se modifie plus.');
        }
    }
}
