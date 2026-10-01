<?php

namespace App\Domains\Finance\Presentation\Api\V1\Admin;

use App\Domains\Audit\Application\ActivityJournal;
use App\Domains\Finance\Application\Actions\BuildStatementFigures;
use App\Domains\Finance\Application\Actions\CancelRemunerationStatement;
use App\Domains\Finance\Application\Actions\GenerateRemunerationStatements;
use App\Domains\Finance\Application\Actions\ListRemunerationStatements;
use App\Domains\Finance\Application\Actions\PurgeCancelledStatements;
use App\Domains\Finance\Application\Actions\UpdateRemunerationStatement;
use App\Domains\Finance\Application\Actions\ValidateRemunerationStatement;
use App\Domains\Finance\Application\Data\CancelRemunerationStatementData;
use App\Domains\Finance\Application\Data\GenerateRemunerationStatementsData;
use App\Domains\Finance\Application\Data\RemunerationStatementDetailData;
use App\Domains\Finance\Application\Data\UpdateRemunerationStatementData;
use App\Domains\Finance\Application\Data\ValidateRemunerationStatementData;
use App\Domains\Finance\Application\RemunerationStatementPdf;
use App\Domains\Finance\Domain\StatementFigures;
use App\Models\RemunerationStatement;
use App\Shared\Http\ApiException;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Les fiches de rémunération, vues de l'administration (spec 2026-09-30, §5.2).
 *
 * Relire et ajuster se délègue ; valider, envoyer et annuler restent à l'administrateur —
 * les permissions sont posées sur les routes.
 */
final class RemunerationStatementController
{
    /** Chaque écriture est tracée APRÈS sa réussite : voir `ActivityJournal`. */
    public function __construct(
        private readonly ActivityJournal $journal,
        private readonly BuildStatementFigures $build,
        private readonly ValidateRemunerationStatement $validate,
    ) {}

    public function index(Request $request, ListRemunerationStatements $list): JsonResponse
    {
        try {
            return response()->json($list($request->query()));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la liste des fiches de rémunération',
                'La liste des fiches de rémunération n\'a pas pu être chargée. Réessayez.', 'ADMIN_REMUNERATION_STATEMENTS_FAILED');
        }
    }

    public function show(Request $request, string $id): JsonResponse
    {
        try {
            return response()->json($this->detail($this->find($id)));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la fiche de rémunération',
                'Cette fiche de rémunération n\'a pas pu être chargée. Réessayez.', 'ADMIN_REMUNERATION_STATEMENT_FAILED');
        }
    }

    public function update(Request $request, string $id, UpdateRemunerationStatementData $data, UpdateRemunerationStatement $update): JsonResponse
    {
        try {
            $statement = $update($this->find($id), $data);

            return response()->json($this->detail($this->find($statement->id)));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'l\'enregistrement de la fiche de rémunération',
                'Cette fiche de rémunération n\'a pas pu être enregistrée.', 'ADMIN_REMUNERATION_STATEMENT_UPDATE_FAILED');
        }
    }

    public function generate(Request $request, GenerateRemunerationStatementsData $data, GenerateRemunerationStatements $generate): JsonResponse
    {
        try {
            return response()->json(['created' => $generate($data->month, $data->vehicleContractId)]);
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la génération des fiches de rémunération',
                'Les brouillons n\'ont pas pu être générés. Réessayez.', 'ADMIN_REMUNERATION_GENERATE_FAILED');
        }
    }

    public function validateStatement(Request $request, string $id, ValidateRemunerationStatementData $data): JsonResponse
    {
        try {
            $statement = ($this->validate)($this->find($id), $request->user(), $data->issuedOn !== null ? Carbon::parse($data->issuedOn) : null, $data->send);
            $statement = $this->find($statement->id);
            $this->journal->remunerationStatementValidated($statement);

            return response()->json($this->detail($statement));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la validation de la fiche de rémunération',
                'Cette fiche de rémunération n\'a pas pu être validée.', 'ADMIN_REMUNERATION_VALIDATE_FAILED');
        }
    }

    public function cancel(Request $request, string $id, CancelRemunerationStatementData $data, CancelRemunerationStatement $cancel): JsonResponse
    {
        try {
            $statement = $this->find($id);
            $replacement = $cancel($statement, $request->user(), $data->reason);
            $this->journal->remunerationStatementCancelled($statement->refresh(), $data->reason);

            return response()->json($this->detail($this->find($replacement->id)));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'l\'annulation de la fiche de rémunération',
                'Cette fiche de rémunération n\'a pas pu être annulée.', 'ADMIN_REMUNERATION_CANCEL_FAILED');
        }
    }

    /** Vider les fiches annulées — `purge-remuneration-statements`, portée par la route (2026-10-01). */
    public function purgeCancelled(Request $request, PurgeCancelledStatements $purge): JsonResponse
    {
        try {
            $numbers = $purge();
            if ($numbers !== []) {
                $this->journal->cancelledStatementsPurged($numbers);
            }

            return response()->json(['deleted' => count($numbers)]);
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la purge des fiches annulées',
                'Les fiches annulées n\'ont pas pu être vidées.', 'ADMIN_REMUNERATION_PURGE_FAILED');
        }
    }

    /**
     * Le PDF, jamais servi directement depuis le disque (spec §5.6). Un brouillon porte le
     * filigrane « BROUILLON » ; une fiche validée sert le PDF rangé, ou le refait depuis ses
     * chiffres figés tant que la tâche en file ne l'a pas encore produit.
     */
    public function pdf(Request $request, string $id, RemunerationStatementPdf $pdf): Response
    {
        $statement = $this->find($id);

        // Effacé au bout d'un an : l'admin le régénère, il n'est pas refait en silence.
        if ($statement->status !== 'draft' && $statement->pdf_purged_at !== null) {
            throw new ApiException(410, 'STATEMENT_PDF_EXPIRED', 'Fichier indisponible : le PDF a été effacé du serveur au bout d\'un an.');
        }

        $bytes = match (true) {
            $statement->status === 'draft' => $pdf->render(($this->build)($statement->contract, $statement->monthKey(), $statement), null, true, null),
            $statement->pdf_path !== null && Storage::disk('local')->exists($statement->pdf_path) => Storage::disk('local')->get($statement->pdf_path),
            default => $pdf->render(StatementFigures::fromArray($statement->figures ?? []), $statement->number, false, $statement->issued_on ?? $statement->validated_at),
        };

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.($statement->number ?? 'brouillon').'.pdf"',
        ]);
    }

    /** Reproduire le PDF effacé depuis les chiffres figés : même numéro, même date (spec 2026-10-01, §7.2). */
    public function regeneratePdf(Request $request, string $id, RemunerationStatementPdf $pdf): JsonResponse
    {
        try {
            $statement = $this->find($id);
            if ($statement->status !== 'validated') {
                throw new ApiException(409, 'STATEMENT_NOT_VALIDATED', 'Seule une fiche validée a un PDF à régénérer.');
            }
            $path = sprintf('statements/%s/%s.pdf', $statement->month->format('Y'), $statement->number);
            Storage::disk('local')->put($path, $pdf->render(
                StatementFigures::fromArray($statement->figures ?? []), $statement->number, false, $statement->issued_on ?? $statement->validated_at,
            ));
            // Le compteur du propriétaire ne bouge pas : la régénération sert l'archive.
            $statement->update(['pdf_path' => $path, 'pdf_generated_at' => now(), 'pdf_purged_at' => null]);

            return response()->json($this->detail($this->find($id)));
        } catch (ValidationException|ApiException|ModelNotFoundException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return $this->failure($e, $request, 'la régénération du PDF', 'Le PDF n\'a pas pu être régénéré.', 'ADMIN_REMUNERATION_PDF_REGENERATE_FAILED');
        }
    }

    private function find(string $id): RemunerationStatement
    {
        return RemunerationStatement::with(['contract.vehicle.owner', 'validator'])->findOrFail($id);
    }

    /** Figés pour une fiche validée ou annulée, recalculés pour un brouillon. */
    private function detail(RemunerationStatement $statement): RemunerationStatementDetailData
    {
        if ($statement->status !== 'draft') {
            return RemunerationStatementDetailData::fromStatement($statement, StatementFigures::fromArray($statement->figures ?? []), []);
        }

        $figures = ($this->build)($statement->contract, $statement->monthKey(), $statement);

        return RemunerationStatementDetailData::fromStatement($statement, $figures, $this->validate->blockers($statement, $figures));
    }

    private function failure(\Throwable $e, Request $request, string $what, string $message, string $code): JsonResponse
    {
        Log::error("Erreur lors de {$what} : ".$e->getMessage(), [
            'exception' => $e,
            'user_id' => $request->user()?->id,
        ]);

        return response()->json(['message' => $message, 'code' => $code], 500);
    }
}
