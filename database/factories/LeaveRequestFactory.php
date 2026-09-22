<?php

namespace Database\Factories;

use App\Models\Driver;
use App\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeaveRequest>
 */
class LeaveRequestFactory extends Factory
{
    protected $model = LeaveRequest::class;

    public function definition(): array
    {
        return [
            'driver_id' => Driver::factory(),
            'driver_contract_id' => null,
            'start_date' => now()->subDays(5),
            'end_date' => now()->subDays(1),
            'requested_days' => 5,
            // ⚠️ Cohérent avec l'état par défaut, qui est `completed` : une pause TERMINÉE
            // a des jours effectifs. Les états `pending()` et `ongoing()` les remettent à
            // null, parce qu'une pause qui n'est pas finie n'en a pas — et les calculs de
            // solde lisent `effective_days ?? requested_days`, donc un résidu ici fausse
            // silencieusement le solde. Piège payé le 2026-09-22.
            'effective_days' => 5,
            // Contrainte CHECK `leave_requests_status_check` : pending, rejected,
            // ongoing, completed. `approved` n'existe plus depuis la migration du
            // 12 août — l'y mettre ferait échouer l'insertion.
            'status' => 'completed',
            'source' => 'admin_historical',
        ];
    }

    /** Congé toujours en cours : ni fin, ni jours effectifs — il n'est pas terminé. */
    public function ongoing(): static
    {
        return $this->state(fn () => [
            'status' => 'ongoing',
            'end_date' => null,
            'effective_days' => null,
        ]);
    }

    /** Demande en attente : rien n'a encore été pris. */
    public function pending(): static
    {
        return $this->state(fn () => [
            'status' => 'pending',
            'start_date' => now()->addDays(3),
            'end_date' => null,
            'effective_days' => null,
            'source' => 'driver_request',
        ]);
    }
}
