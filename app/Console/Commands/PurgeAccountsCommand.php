<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

/**
 * Efface tous les comptes utilisateurs (y compris l'appelant) sans toucher au reste
 * (sites, produits, véhicules, ventes, achats, commissions, dépenses, RH...).
 *
 * Pensé pour repartir d'un état « iso premier déploiement prod » sur préprod : après
 * purge, `php artisan app:install` recrée un premier super_admin sur l'organisation
 * existante (celle-ci n'est jamais supprimée, cf. InstallationService::resolveOrganization
 * qui la retrouve par nom exact et la réutilise puisqu'elle n'a plus de super_admin).
 *
 * ATTENTION FK : 4 tables ont une FK NOT NULL + cascadeOnDelete vers users.created_by
 * (transferts_logistiques, versements_commission_logistique, mouvements_stock,
 * commission_payments) — un DELETE FROM users normal les supprimerait en cascade,
 * détruisant de la donnée métier que cette commande ne doit pas toucher. D'où le
 * FOREIGN_KEY_CHECKS=0 le temps du DELETE : ces colonnes gardent une référence à un
 * user qui n'existe plus (inoffensif — même nature que les références orphelines déjà
 * acceptées ailleurs dans le projet, ex: paiement_fiche_lignes.source_id).
 */
class PurgeAccountsCommand extends Command
{
    protected $signature = 'accounts:purge
        {--organization= : ID ou slug d\'organisation ; toutes si omis}
        {--force : Ne pas demander de confirmation interactive}';

    protected $description = "Efface tous les comptes utilisateurs (invitations, rattachements site, rôles) — ne touche à rien d'autre. Repartir ensuite avec `php artisan app:install`.";

    public function handle(): int
    {
        $organizationId = null;
        if ($value = $this->option('organization')) {
            $organization = Organization::query()->where('id', $value)->orWhere('slug', $value)->first();
            if (! $organization) {
                $this->error("Organisation « {$value} » introuvable.");

                return self::FAILURE;
            }
            $organizationId = $organization->id;
        }

        $usersQuery = fn () => $organizationId ? User::where('organization_id', $organizationId) : User::query();
        $userIds = $usersQuery()->pluck('id');
        $userCount = $userIds->count();

        $invitationsQuery = fn () => $organizationId
            ? DB::table('user_invitations')->where('organization_id', $organizationId)
            : DB::table('user_invitations');

        $this->table(['Table', 'Lignes concernées'], [
            ['users', $userCount],
            ['user_sites', DB::table('user_sites')->whereIn('user_id', $userIds)->count()],
            ['user_invitations', $invitationsQuery()->count()],
        ]);

        if ($userCount === 0) {
            $this->info('Aucun compte à effacer.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn('IRRÉVERSIBLE — y compris votre propre compte si vous êtes concerné. Ne touche à rien d\'autre (sites, produits, véhicules, ventes, achats, commissions, dépenses, RH).');
        $this->line('URL de cette instance : '.config('app.url'));
        $this->line('SENTRY_ENVIRONMENT   : '.(env('SENTRY_ENVIRONMENT') ?: '(non défini)'));
        $this->line('Base de données      : '.config('database.connections.'.config('database.default').'.database'));
        $this->line('Après purge : reconnexion impossible tant que `php artisan app:install` n\'a pas recréé un compte.');

        if (! $this->option('force')) {
            if ($this->ask('Vérifiez bien que c\'est la bonne instance ci-dessus. Tapez SUPPRIMER pour confirmer') !== 'SUPPRIMER') {
                $this->info('Annulé.');

                return self::SUCCESS;
            }
        }

        DB::transaction(function () use ($organizationId, $usersQuery, $invitationsQuery) {
            $userIds = $usersQuery()->pluck('id');

            DB::table('model_has_roles')->where('model_type', User::class)->whereIn('model_id', $userIds)->delete();
            DB::table('model_has_permissions')->where('model_type', User::class)->whereIn('model_id', $userIds)->delete();
            DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $userIds)->delete();
            DB::table('notifications')->where('notifiable_type', User::class)->whereIn('notifiable_id', $userIds)->delete();
            DB::table('user_sites')->whereIn('user_id', $userIds)->delete();
            $invitationsQuery()->delete();

            // Sessions/tokens de réinitialisation : pas de FK, on nettoie large sur une purge
            // globale (aucune session ne peut survivre à la suppression de tous les comptes).
            if (! $organizationId) {
                DB::table('sessions')->delete();
                DB::table('password_reset_tokens')->delete();
            } else {
                DB::table('sessions')->whereIn('user_id', $userIds)->delete();
            }

            // FOREIGN_KEY_CHECKS=0 : voir docblock de la classe — évite que les FK NOT NULL +
            // cascadeOnDelete vers users (transferts_logistiques, versements_commission_logistique,
            // mouvements_stock, commission_payments) suppriment en cascade de la donnée métier.
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            $usersQuery()->delete();
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info('Comptes effacés. Relancez `php artisan app:install` pour recréer le premier compte super_admin.');

        return self::SUCCESS;
    }
}
