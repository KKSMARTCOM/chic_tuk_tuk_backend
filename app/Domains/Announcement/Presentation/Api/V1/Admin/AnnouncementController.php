<?php

namespace App\Domains\Announcement\Presentation\Api\V1\Admin;

use App\Domains\Announcement\Application\Actions\AddAnnouncementAttachment;
use App\Domains\Announcement\Application\Actions\DeleteAnnouncementDraft;
use App\Domains\Announcement\Application\Actions\ListAnnouncementRecipients;
use App\Domains\Announcement\Application\Actions\ListAnnouncements;
use App\Domains\Announcement\Application\Actions\PreviewAnnouncementRecipients;
use App\Domains\Announcement\Application\Actions\RemindAnnouncement;
use App\Domains\Announcement\Application\Actions\RemoveAnnouncementAttachment;
use App\Domains\Announcement\Application\Actions\SaveAnnouncementDraft;
use App\Domains\Announcement\Application\Actions\SearchAnnouncementUsers;
use App\Domains\Announcement\Application\Actions\SendAnnouncement;
use App\Domains\Announcement\Application\Actions\ShowAnnouncement;
use App\Domains\Announcement\Application\Actions\WithdrawAnnouncement;
use App\Domains\Announcement\Application\Data\PreviewRecipientsData;
use App\Domains\Announcement\Application\Data\SaveAnnouncementData;
use App\Domains\Audit\Application\ActivityJournal;
use App\Models\Announcement;
use App\Models\AnnouncementAttachment;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Les communiqués, côté administration (spec 2026-10-13, §7.1). */
final class AnnouncementController
{
    public function __construct(
        private readonly ActivityJournal $journal,
        private readonly ShowAnnouncement $show,
    ) {}

    public function index(Request $request, ListAnnouncements $list): JsonResponse|Response
    {
        return $this->guard($request, fn () => response()->json($list($request->query())));
    }

    public function show(Request $request, string $announcement): JsonResponse|Response
    {
        return $this->guard($request, fn () => response()->json(($this->show)($announcement)));
    }

    public function store(Request $request, SaveAnnouncementData $data, SaveAnnouncementDraft $save): JsonResponse|Response
    {
        return $this->guard($request, fn () => response()->json(($this->show)($save(null, $data, $request->user())->id), 201));
    }

    public function update(Request $request, string $announcement, SaveAnnouncementData $data, SaveAnnouncementDraft $save): JsonResponse|Response
    {
        return $this->guard($request, fn () => response()->json(($this->show)($save(Announcement::findOrFail($announcement), $data, $request->user())->id)));
    }

    public function destroy(Request $request, string $announcement, DeleteAnnouncementDraft $delete): JsonResponse|Response
    {
        return $this->guard($request, function () use ($announcement, $delete) {
            $this->journal->announcementDeleted($delete(Announcement::findOrFail($announcement)));

            return response()->noContent();
        });
    }

    public function addAttachment(Request $request, string $announcement, AddAnnouncementAttachment $add): JsonResponse|Response
    {
        $request->validate([
            'file' => ['required', 'file', 'max:'.AddAnnouncementAttachment::MAX_KILOBYTES, 'mimetypes:'.implode(',', AddAnnouncementAttachment::MIME_TYPES)],
        ], [
            // Un fichier trop gros pour PHP arrive VIDE : c'est ce message-là qui s'affiche.
            'file.required' => 'Aucun fichier reçu : choisissez un fichier de 10 Mo au plus.',
            // Un fichier que PHP n'a pas pu recevoir (trop gros pour le serveur, coupure).
            'file.uploaded' => 'Le fichier n\'a pas pu être reçu : 10 Mo au plus.',
            'file.max' => 'Un fichier fait 10 Mo au plus.',
            'file.mimetypes' => 'Seuls les PDF et les images JPEG, PNG ou WebP sont acceptés.',
        ]);

        return $this->guard($request, function () use ($request, $announcement, $add) {
            $add(Announcement::findOrFail($announcement), $request->file('file'));

            return response()->json(($this->show)($announcement), 201);
        });
    }

    public function removeAttachment(Request $request, string $announcement, string $attachment, RemoveAnnouncementAttachment $remove): JsonResponse|Response
    {
        return $this->guard($request, function () use ($announcement, $attachment, $remove) {
            $remove(Announcement::findOrFail($announcement), AnnouncementAttachment::findOrFail($attachment));

            return response()->json(($this->show)($announcement));
        });
    }

    public function downloadAttachment(string $announcement, string $attachment): StreamedResponse
    {
        $file = AnnouncementAttachment::where('announcement_id', $announcement)->findOrFail($attachment);

        return self::download($file);
    }

    public function preview(Request $request, PreviewRecipientsData $data, PreviewAnnouncementRecipients $preview): JsonResponse|Response
    {
        return $this->guard($request, fn () => response()->json($preview($data->targetProfiles, $data->targetUserIds, $request->user())));
    }

    public function searchUsers(Request $request, SearchAnnouncementUsers $search): JsonResponse|Response
    {
        return $this->guard($request, fn () => response()->json($search((string) $request->query('q', ''))));
    }

    public function recipients(Request $request, string $announcement, ListAnnouncementRecipients $list): JsonResponse|Response
    {
        return $this->guard($request, fn () => response()->json($list($announcement, $request->query())));
    }

    public function send(Request $request, string $announcement, SendAnnouncement $send): JsonResponse|Response
    {
        return $this->guard($request, function () use ($request, $announcement, $send) {
            $sent = $send(Announcement::findOrFail($announcement), $request->user());
            $detail = ($this->show)($sent->id);
            $this->journal->announcementSent($sent, $detail->recipientsCount);

            return response()->json($detail);
        });
    }

    public function remind(Request $request, string $announcement, RemindAnnouncement $remind): JsonResponse|Response
    {
        return $this->guard($request, function () use ($announcement, $remind) {
            $model = Announcement::findOrFail($announcement);
            $this->journal->announcementReminded($model, $remind($model));

            return response()->json(($this->show)($announcement));
        });
    }

    public function withdraw(Request $request, string $announcement, WithdrawAnnouncement $withdraw): JsonResponse|Response
    {
        return $this->guard($request, function () use ($request, $announcement, $withdraw) {
            $this->journal->announcementWithdrawn($withdraw(Announcement::findOrFail($announcement), $request->user()));

            return response()->json(($this->show)($announcement));
        });
    }

    /**
     * ⚠️ Toujours en téléchargement, jamais en ligne, et `nosniff` : un fichier déposé ne doit
     * jamais être interprété par le navigateur comme une page.
     */
    public static function download(AnnouncementAttachment $file): StreamedResponse
    {
        return Storage::disk('local')->download($file->path, $file->original_name, [
            'Content-Type' => $file->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function guard(Request $request, \Closure $action): JsonResponse|Response
    {
        try {
            return $action();
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Erreur sur un communiqué : '.$e->getMessage(), ['exception' => $e, 'user_id' => $request->user()?->id]);

            return response()->json(['message' => 'L\'opération sur le communiqué a échoué. Réessayez.', 'code' => 'ANNOUNCEMENT_FAILED'], 500);
        }
    }
}
