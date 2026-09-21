<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Renomme le rôle `lecteur` en `utilisateur`, en emportant ses comptes.
 *
 * ## Pourquoi une migration, et pas seulement le seeder
 *
 * Le seeder de référence a été modifié le 2026-09-21 pour nommer ce rôle `utilisateur`.
 * Mais Spatie cherche les rôles par leur NOM : le seeder a donc créé un second rôle au
 * lieu de déplacer le premier. Résultat observé en local — deux rôles portant le même
 * libellé « Utilisateur », l'ancien gardant ses deux comptes et le nouveau n'en ayant
 * aucun.
 *
 * C'est plus grave qu'un doublon cosmétique : `lecteur` ne figurant plus dans la
 * référence, `syncPermissions` ne le touche plus jamais. Ses droits dérivent alors sans
 * filet, ce qui est exactement ce que le seeder de référence existe pour empêcher.
 *
 * ## Ce que fait la migration
 *
 * Trois cas, dans cet ordre :
 *
 *  1. les deux rôles existent — les comptes de `lecteur` sont rattachés à `utilisateur`,
 *     puis `lecteur` est supprimé ;
 *  2. seul `lecteur` existe — il est renommé sur place, ce qui préserve son `id` et donc
 *     toutes ses affectations sans rien déplacer ;
 *  3. seul `utilisateur` existe — il n'y a rien à faire.
 *
 * ⚠️ Rejouable sans dommage : `docker/start.sh` lance `migrate --force` à CHAQUE
 * démarrage du conteneur, et une migration qui suppose un état initial casserait le
 * redémarrage suivant.
 *
 * ⚠️ On ne touche pas aux permissions de `utilisateur` : le seeder de référence en est la
 * source de vérité et les a déjà posées. Les recopier depuis `lecteur` réintroduirait
 * précisément la dérive qu'on corrige.
 */
return new class extends Migration
{
    private const ANCIEN = 'lecteur';

    private const NOUVEAU = 'utilisateur';

    public function up(): void
    {
        $tables = config('permission.table_names');

        $ancien = DB::table($tables['roles'])->where('name', self::ANCIEN)->first();

        if (! $ancien) {
            return; // Déjà renommé, ou jamais existé : rien à faire.
        }

        $nouveau = DB::table($tables['roles'])
            ->where('name', self::NOUVEAU)
            ->where('guard_name', $ancien->guard_name)
            ->first();

        if (! $nouveau) {
            // Renommage sur place : l'`id` ne bouge pas, donc `model_has_roles` et
            // `role_has_permissions` suivent sans qu'on y touche.
            DB::table($tables['roles'])->where('id', $ancien->id)->update([
                'name' => self::NOUVEAU,
                'updated_at' => now(),
            ]);

            $this->oublierLeCache();

            return;
        }

        DB::transaction(function () use ($tables, $ancien, $nouveau) {
            // ⚠️ Les comptes qui portent DÉJÀ le nouveau rôle sont écartés : la clé
            // primaire de `model_has_roles` est composite, et réinsérer le même triplet
            // ferait échouer la migration — donc le démarrage du conteneur.
            $dejaRattaches = DB::table($tables['model_has_roles'])
                ->where('role_id', $nouveau->id)
                ->pluck('model_id');

            DB::table($tables['model_has_roles'])
                ->where('role_id', $ancien->id)
                ->whereNotIn('model_id', $dejaRattaches)
                ->update(['role_id' => $nouveau->id]);

            // Les doublons restants n'ont plus lieu d'être : ces comptes ont déjà le rôle.
            DB::table($tables['model_has_roles'])->where('role_id', $ancien->id)->delete();
            DB::table($tables['role_has_permissions'])->where('role_id', $ancien->id)->delete();
            DB::table($tables['roles'])->where('id', $ancien->id)->delete();
        });

        $this->oublierLeCache();
    }

    /**
     * ⚠️ Volontairement sans effet.
     *
     * Quand les deux rôles coexistaient, la migration les a FUSIONNÉS : on ne sait plus
     * quels comptes venaient de `lecteur` et quels comptes portaient déjà `utilisateur`.
     * Recréer `lecteur` rendrait un rôle vide, et y rattacher tout le monde donnerait à
     * des comptes un rôle qu'ils n'ont jamais eu. Mieux vaut ne rien faire que défaire de
     * travers.
     */
    public function down(): void
    {
        // Sans effet, voir le commentaire ci-dessus.
    }

    /** Spatie met les rôles en cache : sans cet oubli, l'ancien nom survit en mémoire. */
    private function oublierLeCache(): void
    {
        app()['cache']->forget('spatie.permission.cache');
    }
};
