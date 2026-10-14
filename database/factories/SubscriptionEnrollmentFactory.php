<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\SubscriptionEnrollment;
use App\Models\SubscriptionEnrollmentPerson;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SubscriptionEnrollment> */
class SubscriptionEnrollmentFactory extends Factory
{
    protected $model = SubscriptionEnrollment::class;

    public function definition(): array
    {
        return [
            'booking_id' => Booking::factory()->subscriptionParent(),
            'token' => SubscriptionEnrollment::newToken(),
            'token_expires_at' => now()->addDays(SubscriptionEnrollment::LINK_DAYS),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn () => ['token_expires_at' => now()->subMinute()]);
    }

    public function submittedSchool(int $children = 1): static
    {
        return $this->state(fn () => [
            'client_name' => 'Awa Dossou', 'whatsapp' => '+22997000000', 'home_address' => 'Cadjehoun',
            'kind' => 'school', 'school_name' => 'Collège Père Aupiais',
            'commitments_accepted_at' => now(), 'submitted_at' => now(),
        ])->afterCreating(function (SubscriptionEnrollment $enrollment) use ($children) {
            foreach (range(1, $children) as $position) {
                SubscriptionEnrollmentPerson::create([
                    'enrollment_id' => $enrollment->id, 'position' => $position,
                    'full_name' => "Enfant {$position}", 'age' => 8 + $position, 'school_class' => 'CM1', 'health_notes' => 'RAS',
                ]);
            }
        });
    }

    public function submittedWork(int $adults = 1): static
    {
        return $this->state(fn () => [
            'client_name' => 'Koffi Agbo', 'whatsapp' => '+22997000001', 'home_address' => 'Akpakpa',
            'kind' => 'work', 'workplace' => 'Ganhi', 'work_hours' => '8h00 - 17h00',
            'commitments_accepted_at' => now(), 'submitted_at' => now(),
        ])->afterCreating(function (SubscriptionEnrollment $enrollment) use ($adults) {
            foreach (range(1, $adults) as $position) {
                SubscriptionEnrollmentPerson::create([
                    'enrollment_id' => $enrollment->id, 'position' => $position,
                    'full_name' => "Passager {$position}", 'profession' => 'Comptable',
                ]);
            }
        });
    }
}
