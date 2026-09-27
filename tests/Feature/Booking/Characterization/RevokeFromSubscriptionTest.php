<?php

namespace Tests\Feature\Booking\Characterization;

use App\Domains\Booking\Application\Actions\RevokeFromSubscription;
use App\Models\Booking;
use App\Models\Driver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RevokeFromSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_course_redevient_libre_et_visible_de_tous(): void
    {
        $titulaire = Driver::factory()->create();
        $autre = Driver::factory()->create();
        $parent = Booking::factory()->subscriptionParent()->create();
        $enfant = Booking::factory()
            ->subscriptionChild($parent)
            ->linkedToSubscriptionDriver($titulaire)
            ->create(['driver_id' => $titulaire->id, 'status' => 'confirmed']);

        app(RevokeFromSubscription::class)($enfant->id, $titulaire->id);

        $enfant->refresh();
        $this->assertNull($enfant->driver_id);
        $this->assertNull($enfant->subscription_driver_id);
        $this->assertSame('pending', $enfant->status);
        $this->assertTrue($enfant->is_revoked);
        $this->assertSame($titulaire->id, $enfant->revoked_by);
        $this->assertNotNull($enfant->revoked_at);
        // Et elle redevient visible d'un autre agent.
        $this->assertTrue($enfant->isVisibleToDriver($autre->id));
    }

    public function test_seul_le_titulaire_peut_revoquer(): void
    {
        $titulaire = Driver::factory()->create();
        $autre = Driver::factory()->create();
        $parent = Booking::factory()->subscriptionParent()->create();
        $enfant = Booking::factory()
            ->subscriptionChild($parent)
            ->linkedToSubscriptionDriver($titulaire)
            ->create();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Vous n\'êtes pas autorisé à révoquer cette course.');

        app(RevokeFromSubscription::class)($enfant->id, $autre->id);
    }

    public function test_une_course_terminee_ne_peut_plus_etre_revoquee(): void
    {
        $titulaire = Driver::factory()->create();
        $parent = Booking::factory()->subscriptionParent()->create();
        $enfant = Booking::factory()
            ->subscriptionChild($parent)
            ->linkedToSubscriptionDriver($titulaire)
            ->completed($titulaire)
            ->create();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cette course ne peut plus être révoquée.');

        app(RevokeFromSubscription::class)($enfant->id, $titulaire->id);
    }
}
