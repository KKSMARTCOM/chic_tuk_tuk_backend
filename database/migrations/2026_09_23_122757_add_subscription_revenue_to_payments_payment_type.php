<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Postgres ne permet pas d'ALTER le contenu d'une contrainte CHECK : il faut la
     * supprimer puis la recréer avec la valeur ajoutée.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_payment_type_check');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_payment_type_check CHECK (payment_type::text = ANY (ARRAY['commission'::character varying, 'contract'::character varying, 'bonus'::character varying, 'other'::character varying, 'subscription_revenue'::character varying]::text[]))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE payments DROP CONSTRAINT payments_payment_type_check');
        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_payment_type_check CHECK (payment_type::text = ANY (ARRAY['commission'::character varying, 'contract'::character varying, 'bonus'::character varying, 'other'::character varying]::text[]))");
    }
};
