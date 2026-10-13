<?php

namespace App\Domains\Announcement\Application\Actions;

use App\Models\Announcement;
use App\Models\AnnouncementAttachment;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;

/** Retirer une pièce jointe d'un brouillon. */
final class RemoveAnnouncementAttachment
{
    public function __invoke(Announcement $announcement, AnnouncementAttachment $attachment): void
    {
        SaveAnnouncementDraft::assertDraft($announcement);

        if ($attachment->announcement_id !== $announcement->id) {
            throw (new ModelNotFoundException)->setModel(AnnouncementAttachment::class);
        }

        Storage::disk('local')->delete($attachment->path);
        $attachment->delete();
    }
}
