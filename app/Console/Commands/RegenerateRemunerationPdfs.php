<?php

namespace App\Console\Commands;

use App\Domains\Finance\Application\RemunerationStatementPdf;
use App\Domains\Finance\Domain\StatementFigures;
use App\Models\RemunerationStatement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Refait le PDF de chaque fiche validée dont le PDF est encore rangé, depuis ses chiffres
 * figés — même numéro, même date d'établissement, même chemin (2026-10-05 : la fiche a
 * perdu cachet, signature et monogramme, et les anciens PDF les portent encore).
 *
 * ⚠️ `pdf_generated_at` ne bouge pas : c'est lui qui décide de l'effacement au bout d'un
 * an, et refaire un PDF ne doit pas prolonger sa vie. Le compteur de téléchargements du
 * propriétaire ne bouge pas non plus, et rien ne lui est envoyé. Les fiches annulées et
 * les PDF déjà effacés sont laissés tels quels.
 */
class RegenerateRemunerationPdfs extends Command
{
    protected $signature = 'app:regenerate-remuneration-pdfs {--dry-run : Lister les fiches sans rien écrire}';

    protected $description = 'Refait les PDF des fiches de rémunération validées, depuis leurs chiffres figés';

    public function handle(RemunerationStatementPdf $pdf): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $count = 0;

        RemunerationStatement::query()
            ->where('status', 'validated')
            ->whereNotNull('pdf_path')
            ->orderBy('number')
            ->each(function (RemunerationStatement $statement) use ($pdf, $dryRun, &$count) {
                $this->line("{$statement->number} → {$statement->pdf_path}");
                if (! $dryRun) {
                    Storage::disk('local')->put($statement->pdf_path, $pdf->render(
                        StatementFigures::fromArray($statement->figures ?? []), $statement->number, false, $statement->issued_on ?? $statement->validated_at,
                    ));
                }
                $count++;
            });

        $this->info($dryRun ? "{$count} PDF à refaire (aucun écrit)." : "{$count} PDF refait(s).");

        return self::SUCCESS;
    }
}
