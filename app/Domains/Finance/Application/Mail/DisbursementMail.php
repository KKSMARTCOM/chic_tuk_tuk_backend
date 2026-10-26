<?php

namespace App\Domains\Finance\Application\Mail;

use App\Models\OwnerDisbursement;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/**
 * Un versement au propriétaire, avec sa preuve en pièce jointe quand le comptable en a joint
 * une (2026-10-26). Le ton est celui du Service Comptabilité. Il part avec la notification,
 * dans les mêmes conditions (`Notifier::disbursementRecorded`).
 */
class DisbursementMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public OwnerDisbursement $disbursement) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Versement de votre revenu — '.$this->vehicleNumber());
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.disbursement',
            text: 'emails.disbursement-text',
            with: [
                'ownerName' => (string) $this->disbursement->contract?->vehicle?->owner?->name,
                'amount' => number_format((float) $this->disbursement->amount, 0, ',', ' ').' F',
                'paidOn' => $this->disbursement->paid_on->format('d/m/Y'),
                'vehicleNumber' => $this->vehicleNumber(),
                'withProof' => $this->hasProof(),
            ],
        );
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        if (! $this->hasProof()) {
            return [];
        }

        return [
            Attachment::fromStorageDisk('local', (string) $this->disbursement->proof_path)
                ->as($this->disbursement->proof_original_name ?: 'preuve-versement')
                ->withMime((string) ($this->disbursement->proof_mime_type ?: 'application/octet-stream')),
        ];
    }

    /** La preuve est lue au moment de l'envoi : retirée entre-temps, l'e-mail part sans elle. */
    private function hasProof(): bool
    {
        $path = $this->disbursement->proof_path;

        return $path !== null && Storage::disk('local')->exists($path);
    }

    private function vehicleNumber(): string
    {
        return (string) $this->disbursement->contract?->vehicle?->vehicle_number;
    }
}
