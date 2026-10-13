<?php

namespace App\Domains\Announcement\Application\Actions;

use App\Models\Announcement;
use Illuminate\Support\Facades\Storage;

/** Supprimer un brouillon et ses fichiers. Un communiqué envoyé ne se supprime pas : il se retire. */
final class DeleteAnnouncementDraft
{
    public function __invoke(Announcement $announcement): string
    {
        SaveAnnouncementDraft::assertDraft($announcement);

        $title = $announcement->title;
        $announcement->delete();
        Storage::disk('local')->deleteDirectory("announcements/{$announcement->id}");

        return $title;
    }
}
