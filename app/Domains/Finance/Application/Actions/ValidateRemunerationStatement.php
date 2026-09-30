<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Finance\Application\Jobs\IssueRemunerationStatement;
use App\Domains\Finance\Domain\ChargeLedger;
use App\Domains\Finance\Domain\RemunerationBranding;
use App\Domains\Finance\Domain\StatementFigures;
use App\Models\Payment;
use App\Models\RemunerationStatement;
use App\Models\User;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Valider une fiche : la figer, la numéroter, rattacher ses paiements (spec §5.4).
 *
 * Une transaction, puis — APRÈS elle — la tâche qui produit le PDF, notifie et envoie
 * l'e-mail : un e-mail parti pour une transaction annulée annoncerait une fiche qui
 * n'existe pas.
 */
final class ValidateRemunerationStatement
{
    public function __construct(private readonly BuildStatementFigures $figures) {}

    public function __invoke(RemunerationStatement $statement, User $by): RemunerationStatement
    {
        if (! RemunerationBranding::isComplete()) {
            throw new ApiException(409, 'STATEMENT_BRANDING_MISSING', 'Le cachet ou la signature sont introuvables sur le serveur : la fiche partirait non signée.');
        }

        DB::transaction(function () use (&$statement, $by) {
            // ⚠️ Un verrou par mois : deux validations simultanées prendraient sinon le même
            // numéro. Verrou de TRANSACTION, relâché au commit.
            DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('remuneration-'.$statement->monthKey())]);

            $statement = RemunerationStatement::query()->lockForUpdate()->findOrFail($statement->id);
            if ($statement->status !== 'draft') {
                throw new ApiException(409, 'STATEMENT_NOT_DRAFT', 'Cette fiche n\'est plus un brouillon.');
            }
            if ($this->previousIsPending($statement)) {
                throw new ApiException(409, 'STATEMENT_PREVIOUS_NOT_VALIDATED', 'La fiche du mois précédent doit être validée d\'abord.');
            }

            $figures = ($this->figures)($statement->contract, $statement->monthKey(), $statement);
            $violations = $this->violations($figures);
            if ($violations !== []) {
                throw ValidationException::withMessages($violations);
            }

            $ids = [...$figures->revenuePaymentIds, ...$figures->recoveredPaymentIds];
            $attached = Payment::query()->whereIn('id', $ids)->whereNull('remuneration_statement_id')
                ->update(['remuneration_statement_id' => $statement->id]);
            if ($attached !== count($ids)) {
                throw new ApiException(409, 'STATEMENT_PAYMENTS_CHANGED', 'Des paiements ont changé pendant la validation. Rechargez la fiche.');
            }

            // Le rang compte aussi les fiches annulées, qui gardent leur numéro : une fiche
            // de remplacement en reçoit un neuf.
            $rank = RemunerationStatement::query()->whereDate('month', $statement->month)->whereNotNull('number')->count() + 1;
            $charges = collect($figures->charges)->keyBy('key');

            $statement->update([
                'status' => 'validated',
                'number' => sprintf('FR-%s-%03d', $statement->month->format('Y-m'), $rank),
                'figures' => $figures->toArray(),
                'balance_due' => $figures->balanceDue,
                'deducted_internet' => $charges['internet']['deducted'],
                'deducted_spotify' => $charges['spotify']['deducted'],
                'deducted_manager' => $charges['manager']['deducted'],
                'validated_by' => $by->id,
                'validated_at' => now(),
            ]);
        });

        IssueRemunerationStatement::dispatch($statement->id);

        return $statement->refresh();
    }

    /**
     * Les refus prévisibles, en phrases, pour que l'écran les annonce AVANT le clic. Mêmes
     * règles que la validation : l'écran ne doit jamais promettre ce que l'API refusera.
     *
     * @return list<string>
     */
    public function blockers(RemunerationStatement $statement, StatementFigures $figures): array
    {
        $blockers = [];

        if ($this->previousIsPending($statement)) {
            $blockers[] = 'La fiche du mois précédent doit être validée d\'abord.';
        }
        if (! RemunerationBranding::isComplete()) {
            $blockers[] = 'Le cachet ou la signature sont introuvables sur le serveur.';
        }

        return [...$blockers, ...array_values($this->violations($figures))];
    }

    private function previousIsPending(RemunerationStatement $statement): bool
    {
        $previous = RemunerationStatement::query()
            ->where('vehicle_contract_id', $statement->vehicle_contract_id)
            ->where('status', '!=', 'cancelled')
            ->whereDate('month', $statement->month->copy()->subMonthNoOverflow())
            ->first();

        return $previous !== null && $previous->status !== 'validated';
    }

    /** @return array<string, string> */
    private function violations(StatementFigures $figures): array
    {
        return ChargeLedger::violations(
            collect($figures->charges)->pluck('deducted', 'key')->all(),
            collect($figures->charges)->pluck('outstanding', 'key')->all(),
            $figures->revenue + $figures->recovered,
        );
    }
}
