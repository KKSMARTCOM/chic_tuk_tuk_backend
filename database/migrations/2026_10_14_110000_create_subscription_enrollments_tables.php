<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * L'inscription d'un abonnement (spec 2026-10-14, §3) : une ligne par course parente
 * d'abonnement, avec son jeton, et une ligne par enfant ou adulte transporté.
 *
 * Les abonnements en cours au déploiement reçoivent une inscription vide (§3.3), pour que
 * l'admin puisse envoyer le lien aux abonnés actuels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_enrollments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('booking_id')->unique()->constrained('bookings')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('token_expires_at');
            $table->string('client_name')->nullable();
            $table->string('whatsapp', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('home_address')->nullable();
            $table->string('kind', 10)->nullable(); // school | work
            $table->string('school_name')->nullable();
            $table->string('school_contact')->nullable();
            $table->string('workplace')->nullable();
            $table->string('work_hours')->nullable();
            $table->string('pickup_landmark')->nullable();
            $table->string('dropoff_landmark')->nullable();
            $table->timestamp('commitments_accepted_at')->nullable();
            $table->string('id_document_type', 10)->nullable(); // cni | passport | other
            $table->string('id_document_other')->nullable();
            $table->string('id_document_path')->nullable();
            $table->timestamp('id_document_uploaded_at')->nullable();
            $table->timestamp('id_document_purged_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('corrected_at')->nullable();
            $table->timestamps();
        });

        DB::statement("ALTER TABLE subscription_enrollments ADD CONSTRAINT subscription_enrollments_kind_check CHECK (kind IS NULL OR kind IN ('school', 'work'))");
        DB::statement("ALTER TABLE subscription_enrollments ADD CONSTRAINT subscription_enrollments_id_type_check CHECK (id_document_type IS NULL OR id_document_type IN ('cni', 'passport', 'other'))");

        Schema::create('subscription_enrollment_people', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('enrollment_id')->constrained('subscription_enrollments')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('full_name');
            $table->unsignedSmallInteger('age')->nullable();
            $table->string('school_class')->nullable();
            $table->text('health_notes')->nullable();
            $table->string('profession')->nullable();
            $table->timestamps();
            $table->unique(['enrollment_id', 'position']);
        });

        $this->backfill();
    }

    /** Public : le test de schéma la rejoue. Idempotente. */
    public function backfill(): void
    {
        $now = now();
        // ⚠️ Le parent EST la course du premier jour : un abonnement en cours a souvent un
        // parent `completed`. En cours = la règle de `BookingLifecycle::isRunningSubscription`,
        // ou un parent encore à venir.
        DB::table('bookings')
            ->where('is_recurring', true)
            ->whereNull('parent_booking_id')
            ->whereNotIn('status', ['cancelled', 'expired'])
            ->where(fn ($q) => $q->whereIn('status', ['pending', 'confirmed', 'in_progress'])
                ->orWhere('remaining_days', '>', 1)
                ->orWhere('makeup_go_count', '>', 0)
                ->orWhere('makeup_return_count', '>', 0))
            ->whereNotExists(fn ($q) => $q->from('subscription_enrollments')->whereColumn('subscription_enrollments.booking_id', 'bookings.id'))
            ->orderBy('id')
            ->pluck('id')
            ->each(fn (string $bookingId) => DB::table('subscription_enrollments')->insert([
                'id' => (string) Str::uuid(),
                'booking_id' => $bookingId,
                'token' => Str::random(64),
                'token_expires_at' => $now->copy()->addDays(30),
                'created_at' => $now,
                'updated_at' => $now,
            ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_enrollment_people');
        Schema::dropIfExists('subscription_enrollments');
    }
};
