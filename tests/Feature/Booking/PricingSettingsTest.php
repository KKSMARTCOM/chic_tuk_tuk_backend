<?php

namespace Tests\Feature\Booking;

use App\Domains\Booking\Domain\PriceCalculator;
use App\Domains\Identity\Domain\Enums\Profil;
use App\Models\PricingSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Le prix des courses, réglé par l'administration (2026-09-29) : prix de base, prix au
 * kilomètre, majoration horaire et sa plage.
 *
 * ⚠️ Le prix de base est AUSSI le minimum d'une course. Avant, deux constantes égales
 * portaient ces deux rôles ; réglables séparément, elles auraient permis qu'une course de
 * 2 km coûte moins qu'une course d'1 km.
 */
class PricingSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function login(array $permissions): string
    {
        $user = User::factory()->profil(Profil::Admin)->create(['password' => Hash::make('bon-mot-de-passe')]);

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']));
        }

        return $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'bon-mot-de-passe',
        ])->json('token');
    }

    private function asBearer(string $token): self
    {
        Auth::forgetGuards();

        return $this->withHeader('Authorization', "Bearer {$token}");
    }

    /** @return array<string, int> */
    private function settings(array $overrides = []): array
    {
        return array_merge([
            'base_price' => 1000,
            'price_per_km' => 150,
            'time_surcharge' => 1000,
            'surcharge_free_start_hour' => 6,
            'surcharge_free_end_hour' => 10,
        ], $overrides);
    }

    public function test_the_settings_start_from_the_historical_prices(): void
    {
        $this->asBearer($this->login(['manage-business-settings']))
            ->getJson('/api/v1/admin/settings/pricing')
            ->assertOk()
            ->assertExactJson($this->settings());
    }

    public function test_the_historical_prices_are_unchanged(): void
    {
        $calculator = new PriceCalculator;

        $this->assertSame(1000, $calculator->getPrice(0.8));
        $this->assertSame(1000, $calculator->getPrice(4));      // 600, relevé au minimum
        $this->assertSame(1500, $calculator->getPrice(10));
        $this->assertSame(1500, $calculator->applyTimeSurcharge(1500, '08:30'));
        $this->assertSame(1500, $calculator->applyTimeSurcharge(1500, '10:00'));  // borne incluse
        $this->assertSame(2500, $calculator->applyTimeSurcharge(1500, '18:00'));
    }

    public function test_new_prices_apply_to_the_next_quotes(): void
    {
        $this->asBearer($this->login(['manage-business-settings']))
            ->putJson('/api/v1/admin/settings/pricing', $this->settings([
                'base_price' => 1200,
                'price_per_km' => 200,
                'time_surcharge' => 500,
                'surcharge_free_start_hour' => 7,
                'surcharge_free_end_hour' => 19,
            ]))
            ->assertOk()
            ->assertJsonPath('price_per_km', 200);

        $calculator = new PriceCalculator;

        $this->assertSame(1200, $calculator->getPrice(1));
        $this->assertSame(1200, $calculator->getPrice(5));     // 1 000, relevé au prix de base
        $this->assertSame(2000, $calculator->getPrice(10));
        $this->assertSame(2000, $calculator->applyTimeSurcharge(2000, '18:00'));
        $this->assertSame(2500, $calculator->applyTimeSurcharge(2000, '06:30'));
    }

    public function test_a_zero_surcharge_removes_it(): void
    {
        PricingSettings::current()->update(['time_surcharge' => 0]);

        $this->assertSame(1500, (new PriceCalculator)->applyTimeSurcharge(1500, '22:00'));
    }

    public function test_the_quote_announces_the_configured_surcharge(): void
    {
        PricingSettings::current()->update(['time_surcharge' => 700, 'surcharge_free_start_hour' => 5, 'surcharge_free_end_hour' => 9]);
        Cache::put('pricing:distance:2.40000,6.36000:2.42000,6.37000', 3.0, 60);

        $this->getJson('/api/v1/public/pricing/quote?'.http_build_query([
            'from_lng' => 2.40, 'from_lat' => 6.36, 'to_lng' => 2.42, 'to_lat' => 6.37,
            'pickup_time' => '12:00',
        ]))
            ->assertOk()
            ->assertJsonPath('surcharge_amount', 700)
            ->assertJsonPath('surcharge_free_window', '5h–9h')
            ->assertJsonPath('go_price', 1700);
    }

    public function test_invalid_prices_are_refused(): void
    {
        $this->asBearer($this->login(['manage-business-settings']))
            ->putJson('/api/v1/admin/settings/pricing', $this->settings([
                'base_price' => 0,
                'time_surcharge' => -1,
                'surcharge_free_start_hour' => 10,
                'surcharge_free_end_hour' => 6,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['base_price', 'time_surcharge', 'surcharge_free_end_hour']);

        $this->assertSame(1000, PricingSettings::current()->base_price);
    }

    public function test_only_the_business_settings_permission_opens_them(): void
    {
        $token = $this->login(['manage-settings', 'edit-bookings']);

        $this->asBearer($token)->getJson('/api/v1/admin/settings/pricing')->assertForbidden();
        $this->asBearer($token)->putJson('/api/v1/admin/settings/pricing', $this->settings())->assertForbidden();
    }
}
