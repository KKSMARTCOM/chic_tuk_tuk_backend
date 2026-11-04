<?php

namespace App\Domains\Announcement\Application\Actions;

use App\Domains\Announcement\Domain\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\AnnouncementAttachment;
use App\Models\User;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Dupliquer un communiqué envoyé ou retiré en brouillon neuf (spec
 * 2026-11-04-communiques-relance-ajout) : titre, texte, ciblage et pièces jointes repris. Les
 * fichiers sont COPIÉS sous le dossier du brouillon : supprimer l'un n'efface pas l'autre.
 */
final class DuplicateAnnouncement
{
    /** @return array{0: Announcement, 1: int} le brouillon, et le nombre de pièces non reprises */
    public function __invoke(Announcement $original, User $actor): array
    {
        if ($original->isDraft()) {
            throw new ApiException(409, 'ANNOUNCEMENT_IS_DRAFT', 'Un brouillon se modifie, il ne se duplique pas.');
        }

        $disk = Storage::disk('local');
        $copied = [];

        try {
            return DB::transaction(function () use ($original, $actor, $disk, &$copied) {
                $draft = Announcement::create([
                    'title' => $original->title,
                    'body' => $original->body,
                    'status' => AnnouncementStatus::Draft,
                    'target_profiles' => $original->target_profiles ?? [],
                    'target_user_ids' => $original->target_user_ids ?? [],
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);

                $skipped = 0;
                foreach ($original->attachments as $file) {
                    /** @var AnnouncementAttachment $file */
                    if (! $disk->exists($file->path)) {
                        $skipped++;

                        continue;
                    }
                    $extension = pathinfo($file->path, PATHINFO_EXTENSION);
                    $path = "announcements/{$draft->id}/".Str::random(40).($extension !== '' ? ".{$extension}" : '');
                    // Un disque réglé sans exception rend `false` : la pièce n'est pas reprise.
                    if (! $disk->copy($file->path, $path)) {
                        $skipped++;

                        continue;
                    }
                    $copied[] = $path;
                    $draft->attachments()->create([
                        'original_name' => $file->original_name,
                        'mime_type' => $file->mime_type,
                        'size' => $file->size,
                        'path' => $path,
                    ]);
                }

                return [$draft, $skipped];
            });
        } catch (\Throwable $e) {
            // Rien ne reste d'un brouillon annulé : ni ligne, ni fichier copié.
            foreach ($copied as $path) {
                $disk->delete($path);
            }

            throw $e;
        }
    }
}
