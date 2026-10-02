<?php

namespace App\Domains\Finance\Application\Actions;

use App\Domains\Finance\Application\Jobs\IssueRemunerationStatement;
use App\Domains\Finance\Domain\ChargeLedger;
use App\Domains\Finance\Domain\RemunerationBranding;
use App\Domains\Finance\Domain\StatementFigures;
use App\Models\Payment;
use App\Models\RemunerationStatement;
use App\Models\User;
use App\Models\VehicleContract;
use App\Shared\Http\ApiException;
use Carbon\Carbon;
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

    public function __invoke(RemunerationStatement $statement, User $by, ?Carbon $issuedOn = null, bool $send = true): RemunerationStatement
    {
        if ($send && ! RemunerationStatement::visibleToOwners()) {
            throw new ApiException(409, 'STATEMENTS_HIDDEN_FROM_OWNERS', 'Les fiches sont cachées aux propriétaires le temps de la reconstitution : validez sans envoyer.');
        }
        if (! RemunerationBranding::isComplete()) {
            throw new ApiException(409, 'STATEMENT_BRANDING_MISSING', 'Le cachet ou la signature sont introuvables sur le serveur : la fiche partirait non signée.');
        }

        DB::transaction(function () use (&$statement, $by, $issuedOn, $send) {
            // ⚠️ Un verrou par mois : deux validations simultanées prendraient sinon le même
            // numéro. Verrou de TRANSACTION, relâché au commit.
            DB::select('SELECT pg_advisory_xact_lock(?)', [crc32('remuneration-'.$statement->monthKey())]);

            $statement = RemunerationStatement::query()->lockForUpdate()->findOrFail($statement->id);
            // Le contrat véhicule, que verrouillent aussi la validation et la correction d'un
            // paiement : un encaissement ne se glisse pas pendant qu'on fige la fiche (2026-10-01).
            VehicleContract::query()->lockForUpdate()->find($statement->vehicle_contract_id);
            if ($statement->status !== 'draft') {
                throw new ApiException(409, 'STATEMENT_NOT_DRAFT', 'Cette fiche n\'est plus un brouillon.');
            }
            if ($this->previousIsPending($statement)) {
                throw new ApiException(409, 'STATEMENT_PREVIOUS_NOT_VALIDATED', 'La fiche du mois précédent doit être validée d\'abord.');
            }
            if ($this->monthIsNotOver($statement)) {
                throw new ApiException(409, 'STATEMENT_MONTH_NOT_OVER', 'Le mois n\'est pas terminé : ses chiffres ne sont que partiels.');
            }

            $issuedOn ??= $statement->issued_on ?? Carbon::today();
            $issueViolations = $this->issueDateViolations($statement, $issuedOn);
            if ($issueViolations !== []) {
                throw ValidationException::withMessages($issueViolations);
            }
            // Les chiffres se lisent à la date d'établissement (spec 2026-10-01, §4.3).
            $statement->issued_on = $issuedOn;

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

            // Le dernier rang attribué du mois, jamais un compte des fiches : un numéro ne
            // désigne qu'une fiche, même après la purge des fiches annulées (2026-10-01).
            $rank = DB::selectOne(
                'INSERT INTO remuneration_statement_numbers (month, last_rank) VALUES (?, 1)
                 ON CONFLICT (month) DO UPDATE SET last_rank = remuneration_statement_numbers.last_rank + 1
                 RETURNING last_rank',
                [$statement->month->toDateString()],
            )->last_rank;
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
                'issued_on' => $issuedOn->toDateString(),
                'delivery' => $send ? 'email' : 'none',
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
        if ($this->monthIsNotOver($statement)) {
            $blockers[] = 'Le mois n\'est pas terminé : ses chiffres ne sont que partiels.';
        }
        if (! RemunerationBranding::isComplete()) {
            $blockers[] = 'Le cachet ou la signature sont introuvables sur le serveur.';
        }
        // Une date d'établissement saisie peut être devenue invalide depuis (la fiche du mois
        // précédent revalidée plus tard) : l'écran le dit avant le clic (2026-10-01).
        if ($statement->issued_on !== null) {
            $blockers = [...$blockers, ...array_values($this->issueDateViolations($statement, $statement->issued_on))];
        }

        return [...$blockers, ...array_values($this->violations($figures))];
    }

    /**
     * Un brouillon du mois en cours peut se générer et se relire, pas se valider : il
     * partirait chez le propriétaire avec des chiffres partiels.
     */
    private function monthIsNotOver(RemunerationStatement $statement): bool
    {
        return $statement->month->copy()->endOfMonth()->gte(now());
    }

    /**
     * La date d'établissement : après la fin du mois, pas dans le futur, et jamais avant
     * celle de la fiche validée du mois précédent (spec 2026-10-01, §4.4).
     *
     * @return array<string, string>
     */
    public function issueDateViolations(RemunerationStatement $statement, Carbon $issuedOn): array
    {
        $issuedOn = $issuedOn->copy()->startOfDay();
        if ($issuedOn->lte($statement->month->copy()->endOfMonth())) {
            return ['issued_on' => 'La date d\'établissement doit suivre la fin du mois de la fiche.'];
        }
        if ($issuedOn->gt(Carbon::today())) {
            return ['issued_on' => 'La date d\'établissement ne peut pas être dans le futur.'];
        }
        $previous = RemunerationStatement::query()
            ->where('vehicle_contract_id', $statement->vehicle_contract_id)
            ->where('status', 'validated')
            ->whereDate('month', $statement->month->copy()->subMonthNoOverflow())
            ->first();
        if ($previous?->issued_on !== null && $issuedOn->lt($previous->issued_on)) {
            return ['issued_on' => 'La date d\'établissement ne peut pas précéder celle de la fiche du mois précédent ('.$previous->issued_on->format('d/m/Y').').'];
        }

        return [];
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
