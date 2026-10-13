<?php

namespace Database\Factories;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Announcement> */
class AnnouncementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'title' => 'Jours fériés de novembre',
            'body' => "Les versements du **1er novembre** sont reportés au lundi.\n\n- Merci de votre compréhension.",
            'status' => 'draft',
            'target_profiles' => ['driver'],
            'target_user_ids' => [],
            'created_by' => User::factory(),
        ];
    }

    public function sent(): static
    {
        return $this->state(fn () => ['status' => 'sent', 'sent_at' => now(), 'sent_by' => User::factory()]);
    }

    public function withdrawn(): static
    {
        return $this->state(fn () => ['status' => 'withdrawn', 'sent_at' => now()->subDay(), 'withdrawn_at' => now()]);
    }
}
