<?php

namespace Tests\Feature\Booking;

use App\Services\BookingService;
use App\Services\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Booking\Concerns\BuildsSubscriptions;
use Tests\TestCase;

/**
 * Le prix d'une course et sa majoration horaire, à la modification depuis l'administration.
 */
class AdminBookingPriceTest extends TestCase
{
    use BuildsSubscriptions;
    use RefreshDatabase;

    /** La tranche sans majoration va de 6h à 10h : elle commençait à 7h en production. */
    public function test_un_depart_a_6h30_n_est_pas_majore(): void
    {
        $pricing = app(PricingService::class);

        $this->assertSame(1000, $pricing->applyTimeSurcharge(1000, '06:30'));
        $this->assertSame(2000, $pricing->applyTimeSurcharge(1000, '05:59'));
    }

    public function test_le_prix_brut_retire_la_majoration_du_depart(): void
    {
        $this->assertSame(1000, $this->makeParent(['pickup_time' => '18:00:00', 'base_price' => 2000])->raw_price);
        $this->assertSame(1500, $this->makeParent(['pickup_time' => '07:00:00', 'base_price' => 1500])->raw_price);
    }

    /**
     * ⚠️ Le formulaire se remplissait avec le prix enregistré, majoration comprise, que
     * l'enregistrement majorait encore : un départ à 18h gagnait 1 000 F à chaque
     * enregistrement, même sans toucher au prix.
     */
    public function test_enregistrer_sans_toucher_au_prix_ne_change_rien(): void
    {
        $booking = $this->makeParent([
            'pickup_time' => '18:00:00',
            'base_price' => 2000,
            'total_price' => 6000,
        ]);

        app(BookingService::class)->update($booking, [
            'from_location' => $booking->from_location,
            'to_location' => $booking->to_location,
            'from_lng' => $booking->from_lng,
            'from_lat' => $booking->from_lat,
            'to_lng' => $booking->to_lng,
            'to_lat' => $booking->to_lat,
            'phone' => $booking->phone,
            'days' => $booking->days,
            'week_days' => $booking->week_days,
            'pickup_date' => $booking->pickup_date->toDateString(),
            'pickup_time' => '18:00',
            'status' => $booking->status,
            'base_price' => $booking->raw_price,
        ]);

        $this->assertEquals(2000, $booking->refresh()->base_price);
        $this->assertEquals(6000, $booking->total_price);
    }

    public function test_le_formulaire_de_modification_affiche_le_prix_brut(): void
    {
        $this->actingAs(\App\Models\User::create([
            'name' => 'Admin', 'email' => 'admin@example.test', 'phone' => '90000001',
            'profil' => 'admin', 'password' => bcrypt('secret'), 'is_active' => true,
        ]));
        view()->share('errors', new \Illuminate\Support\ViewErrorBag);
        $html = view('pages.admin.bookings.edit', [
            'booking' => $this->makeParent(['pickup_time' => '18:00:00', 'base_price' => 2000]),
            'zones' => collect(),
            'touristCircuits' => collect(),
        ])->render();

        $this->assertMatchesRegularExpression('/id="base_price" value="1000(\.00)?"/', $html);
    }
}
