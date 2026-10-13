<?php

namespace App\Domains\Announcement\Application\Actions;

use App\Models\Announcement;
use App\Models\AnnouncementAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Joindre un fichier à un brouillon (spec 2026-10-13, §8).
 *
 * ⚠️ Le type et la taille sont validés par le contrôleur (`mimetypes:` lit le CONTENU, pas
 * l'extension). Le nom stocké est aléatoire (`store`) : le nom d'origine n'est qu'affiché.
 */
final class AddAnnouncementAttachment
{
    public const MAX_FILES = 5;

    public const MAX_KILOBYTES = 10240;

    public const MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    public function __invoke(Announcement $announcement, UploadedFile $file): AnnouncementAttachment
    {
        SaveAnnouncementDraft::assertDraft($announcement);

        if ($announcement->attachments()->count() >= self::MAX_FILES) {
            throw ValidationException::withMessages(['file' => 'Cinq pièces jointes au plus par communiqué.']);
        }

        $path = $file->store("announcements/{$announcement->id}", 'local');

        return $announcement->attachments()->create([
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime_type' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'path' => $path,
        ]);
    }
}
