<?php

namespace App\Domains\Finance\Application\Jobs;

use App\Domains\Audit\Application\ActivityJournal;
use App\Domains\Finance\Application\Mail\RemunerationStatementMail;
use App\Domains\Finance\Application\RemunerationStatementPdf;
use App\Domains\Finance\Domain\StatementFigures;
use App\Domains\Notification\Application\Notifier;
use App\Models\RemunerationStatement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Après la validation : le PDF, la notification, l'e-mail (spec §5.4).
 *
 * Relançable : un PDF déjà rangé n'est pas refait, une fiche déjà envoyée ne repart pas.
 * Tant que le PDF n'est pas rangé, le propriétaire voit la fiche « en préparation ». Un
 * e-mail qui échoue n'annule jamais la validation : il part en file, et ses échecs vont au
 * journal applicatif.
 */
final class IssueRemunerationStatement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly string $statementId) {}

    public function handle(RemunerationStatementPdf $pdf, Notifier $notifier, ActivityJournal $journal): void
    {
        $statement = RemunerationStatement::with('contract.vehicle.owner')->find($this->statementId);
        if (! $statement || $statement->status !== 'validated' || $statement->sent_at !== null) {
            return;
        }

        if ($statement->pdf_path === null) {
            $path = sprintf('statements/%s/%s.pdf', $statement->month->format('Y'), $statement->number);
            Storage::disk('local')->put($path, $pdf->render(
                StatementFigures::fromArray($statement->figures), $statement->number, false, $statement->validated_at,
            ));
            $statement->update(['pdf_path' => $path]);
        }

        $notifier->remunerationStatementIssued($statement);

        $owner = $statement->contract->vehicle?->owner;
        if ($owner?->email) {
            Mail::to($owner->email)->queue(new RemunerationStatementMail($statement));
        }

        $statement->update(['sent_at' => now()]);
        $journal->remunerationStatementSent($statement);
    }
}
