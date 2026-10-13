<?php

namespace App\Domains\Announcement\Application\Mail;

use App\Domains\Announcement\Domain\AnnouncementBody;
use App\Models\Announcement;
use App\Models\AnnouncementAttachment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Un communiqué, à UN destinataire (spec 2026-10-13, §5.2) — jamais de copie : aucune
 * adresse ne doit fuiter d'un destinataire à l'autre.
 *
 * ⚠️ Au-delà de 10 Mo de pièces jointes, la plupart des serveurs refusent le message : on
 * les nomme alors et on renvoie à la plateforme.
 */
class AnnouncementMail extends Mailable
{
    use Queueable, SerializesModels;

    public const MAX_ATTACHED_BYTES = 10_485_760;

    public function __construct(public Announcement $announcement, public bool $reminder = false) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->reminder ? 'Rappel : ' : '').$this->announcement->title);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.announcement',
            text: 'emails.announcement-text',
            with: [
                'title' => $this->announcement->title,
                'bodyHtml' => AnnouncementBody::toHtml((string) $this->announcement->body),
                'bodyText' => (string) $this->announcement->body,
                'reminder' => $this->reminder,
                'url' => rtrim((string) config('app.front_app_url'), '/')."/announcements/{$this->announcement->id}",
                'attached' => $this->attachesFiles(),
                'fileNames' => $this->announcement->attachments->pluck('original_name')->all(),
            ],
        );
    }

    public function attachesFiles(): bool
    {
        $files = $this->announcement->attachments;

        return $files->isNotEmpty() && $files->sum('size') <= self::MAX_ATTACHED_BYTES;
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        if (! $this->attachesFiles()) {
            return [];
        }

        return $this->announcement->attachments
            ->map(fn (AnnouncementAttachment $file) => Attachment::fromStorageDisk('local', $file->path)
                ->as($file->original_name)
                ->withMime($file->mime_type))
            ->values()
            ->all();
    }
}
