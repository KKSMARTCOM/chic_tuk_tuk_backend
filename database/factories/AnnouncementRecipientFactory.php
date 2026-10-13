<?php

namespace Database\Factories;

use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AnnouncementRecipient> */
class AnnouncementRecipientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'announcement_id' => Announcement::factory()->sent(),
            'user_id' => User::factory(),
            'profil' => 'driver',
            'reminded_count' => 0,
        ];
    }

    public function read(): static
    {
        return $this->state(fn () => ['read_at' => now()->subHour()]);
    }
}
