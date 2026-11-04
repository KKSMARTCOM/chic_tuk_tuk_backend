<?php

namespace App\Domains\Announcement\Application\Actions;

use App\Domains\Announcement\Application\Data\RecipientPreviewData;
use App\Models\User;

/** Le décompte de l'éditeur — la MÊME règle que l'envoi (`ResolveAnnouncementRecipients`). */
final class PreviewAnnouncementRecipients
{
    public function __construct(private readonly ResolveAnnouncementRecipients $resolve) {}

    /**
     * @param  list<string>  $profiles
     * @param  list<string>  $userIds
     */
    public function __invoke(array $profiles, array $userIds, User $sender): RecipientPreviewData
    {
        return RecipientPreviewData::fromUsers(($this->resolve)($profiles, $userIds, $sender));
    }
}
