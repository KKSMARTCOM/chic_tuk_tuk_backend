<?php

namespace App\Domains\Finance\Application\Mail;

use App\Models\RemunerationStatement;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** La fiche de rémunération, en pièce jointe, au propriétaire. */
class RemunerationStatementMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public RemunerationStatement $statement) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Votre fiche de rémunération — '.$this->monthLabel());
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.remuneration-statement',
            text: 'emails.remuneration-statement-text',
            with: ['monthPhrase' => $this->monthPhrase(), 'monthLabel' => $this->monthLabel(), 'number' => $this->statement->number],
        );
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromStorageDisk('local', (string) $this->statement->pdf_path)
                ->as("fiche-de-remuneration-{$this->statement->number}.pdf")
                ->withMime('application/pdf'),
        ];
    }

    private function monthLabel(): string
    {
        return $this->statement->month->locale('fr')->translatedFormat('F Y');
    }

    /** « d'octobre 2026 », « d'août 2026 », mais « de juillet 2026 ». */
    private function monthPhrase(): string
    {
        $label = $this->monthLabel();

        return (in_array(mb_substr($label, 0, 1), ['a', 'e', 'i', 'o', 'u', 'é'], true) ? 'd\'' : 'de ').$label;
    }
}
