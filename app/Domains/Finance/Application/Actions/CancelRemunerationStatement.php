<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Notification\Application\Notifier;
use App\Models\Payment;
use App\Models\RemunerationStatement;
use App\Models\User;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Annuler une fiche validée (spec §5.5) : elle reste archivée avec son numéro et son PDF,
 * ses paiements sont détachés, et un brouillon la remplace — qui les recompte, et part des
 * saisies de la fiche annulée.
 */
final class CancelRemunerationStatement
{
    public function __construct(private readonly Notifier $notifier) {}

    public function __invoke(RemunerationStatement $statement, User $by, string $reason): RemunerationStatement
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Le motif de l\'annulation est obligatoire.']);
        }
        // Relue : le statut à jour, et les valeurs par défaut de la base (reste d'ouverture)
        // que le brouillon de remplacement reprend.
        $statement->refresh();
        if ($statement->status !== 'validated') {
            throw new ApiException(409, 'STATEMENT_NOT_VALIDATED', 'Seule une fiche validée s\'annule.');
        }
        // Le miroir de la règle d'ordre de la validation : une fiche suivante validée a bâti
        // ses cumuls et son compte de charges sur celle-ci, et elle est déjà chez le
        // propriétaire. On annule donc de la plus récente à la plus ancienne.
        $laterValidated = RemunerationStatement::query()
            ->where('vehicle_contract_id', $statement->vehicle_contract_id)
            ->where('status', 'validated')
            ->where('month', '>', $statement->month->toDateString())
            ->exists();
        if ($laterValidated) {
            throw new ApiException(409, 'STATEMENT_LATER_VALIDATED', 'Une fiche plus récente de ce contrat est validée : annulez-la d\'abord.');
        }

        $replacement = DB::transaction(function () use ($statement, $by, $reason) {
            Payment::query()->where('remuneration_statement_id', $statement->id)->update(['remuneration_statement_id' => null]);

            $statement->update([
                'status' => 'cancelled',
                'cancelled_by' => $by->id,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);

            return RemunerationStatement::create([
                'vehicle_contract_id' => $statement->vehicle_contract_id,
                'month' => $statement->month->toDateString(),
                'status' => 'draft',
                'deducted_internet' => $statement->deducted_internet,
                'deducted_spotify' => $statement->deducted_spotify,
                'deducted_manager' => $statement->deducted_manager,
                'opening_internet' => $statement->opening_internet,
                'opening_spotify' => $statement->opening_spotify,
                'opening_manager' => $statement->opening_manager,
                'note' => $statement->note,
                'issued_on' => $statement->issued_on,
                'replaces_id' => $statement->id,
            ]);
        });

        // Une fiche validée sans envoi n'a jamais été reçue : rien à annoncer (2026-10-01).
        if ($statement->delivery === 'email') {
            $this->notifier->remunerationStatementCancelled($statement->fresh('contract.vehicle.owner'));
        }

        return $replacement;
    }
}
