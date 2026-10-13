<?php

namespace App\Domains\Announcement\Presentation\Api\V1;

use App\Domains\Announcement\Application\Actions\CountUnreadAnnouncements;
use App\Domains\Announcement\Application\Actions\ListMyAnnouncements;
use App\Domains\Announcement\Application\Actions\ReadAnnouncement;
use App\Domains\Announcement\Application\Data\AnnouncementAttachmentData;
use App\Domains\Announcement\Application\Data\MyAnnouncementData;
use App\Domains\Announcement\Domain\AnnouncementBody;
use App\Domains\Announcement\Presentation\Api\V1\Admin\AnnouncementController as AdminAnnouncementController;
use App\Models\AnnouncementAttachment;
use App\Models\User;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Les communiqués reçus (spec 2026-10-13, §7.2).
 *
 * ⚠️ Comme `/notifications` : ni `abilities:` ni `permission:`, les quatre espaces s'en
 * servent. La portée vient de la ligne destinataire de `Auth::user()`.
 */
final class AnnouncementController
{
    public function index(Request $request, ListMyAnnouncements $list): JsonResponse
    {
        return $this->guard($request, fn () => response()->json($list($this->user($request), $request->query())));
    }

    public function unreadCount(Request $request, CountUnreadAnnouncements $count): JsonResponse
    {
        return $this->guard($request, fn () => response()->json($count($this->user($request))));
    }

    public function show(Request $request, string $announcement, ReadAnnouncement $read): JsonResponse
    {
        return $this->guard($request, function () use ($request, $announcement, $read) {
            $recipient = $read($this->user($request), $announcement);
            $a = $recipient->announcement;

            return response()->json(new MyAnnouncementData(
                id: $a->id,
                title: $a->title,
                bodyHtml: AnnouncementBody::toHtml((string) $a->body),
                sentAt: $a->sent_at->toIso8601String(),
                readAt: $recipient->read_at?->toIso8601String(),
                attachments: $a->attachments->map(fn (AnnouncementAttachment $f) => AnnouncementAttachmentData::fromModel($f))->all(),
            ));
        });
    }

    public function downloadAttachment(Request $request, string $announcement, string $attachment, ReadAnnouncement $read): StreamedResponse
    {
        // Même portée que l'ouverture : un téléchargement vaut lecture.
        $recipient = $read($this->user($request), $announcement);
        $file = $recipient->announcement->attachments->firstWhere('id', $attachment)
            ?? throw (new ModelNotFoundException)->setModel(AnnouncementAttachment::class);

        return AdminAnnouncementController::download($file);
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function guard(Request $request, \Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Erreur sur un communiqué reçu : '.$e->getMessage(), ['exception' => $e, 'user_id' => $request->user()?->id]);

            return response()->json(['message' => 'Vos communiqués n\'ont pas pu être chargés. Réessayez.', 'code' => 'ANNOUNCEMENTS_READ_FAILED'], 500);
        }
    }
}
