<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Rattache chaque utilisateur staff à son site de travail.
 *
 * Règle :
 *  - super_admin, admin_entreprise, comptable → Siège (Matoto)
 *  - manager                                  → Usine (Lansanaya)
 *  - commerciale                              → Agence (Lambagny)
 *
 * Doit être exécuté APRÈS SitesSeeder (les sites doivent exister).
 */
class UserSitesSeeder extends Seeder
{
    public function run(): void
    {
        $org = Organization::where('slug', 'elm')->firstOrFail();

        // ── Récupération des sites ────────────────────────────────────────────
        $siege = Site::where('organization_id', $org->id)->where('nom', 'Matoto')->firstOrFail();
        $usine = Site::where('organization_id', $org->id)->where('nom', 'Lansanaya')->firstOrFail();
        $agence = Site::where('organization_id', $org->id)->where('nom', 'Lambagny')->firstOrFail();

        // ── Matrice : téléphone/identifiant → site ────────────────────────────
        // On identifie les users par téléphone (unique) ou par prénom+nom.
        $affectations = [
            // super_admin → Siège
            ['lookup' => ['telephone' => '+33758855039'],               'site' => $siege],  // Issa BARRY
            ['lookup' => ['telephone' => '+33605751596'],               'site' => $siege],  // Elhadj Oumar TALL

            // admin_entreprise → Siège
            ['lookup' => ['telephone' => '+33769442565'],               'site' => $siege],  // Abdoulaye DIALLO
            ['lookup' => ['telephone' => '+224656555520'],              'site' => $siege],  // Moussa SIDIBÉ
            ['lookup' => ['telephone' => '+33754158797'],               'site' => $siege],  // Amadou DIALLO

            // manager → Usine
            ['lookup' => ['telephone' => '+224622176056'],              'site' => $usine],  // Thierno Oumar DIALLO

            // comptable → Siège
            ['lookup' => ['prenom' => 'Aminata', 'nom' => 'DIALLO'],   'site' => $siege],  // Aminata DIALLO

            // commerciale → Agence
            ['lookup' => ['prenom' => 'Alpha Oumar', 'nom' => 'CAMARA'], 'site' => $agence], // Alpha Oumar CAMARA
        ];

        foreach ($affectations as $item) {
            $user = User::where($item['lookup'])
                ->where('organization_id', $org->id)
                ->first();

            if (! $user) {
                $this->command->warn('Utilisateur introuvable : '.json_encode($item['lookup']));

                continue;
            }

            // syncWithoutDetaching : ne supprime pas les autres sites déjà attribués
            $user->sites()->syncWithoutDetaching([
                $item['site']->id => ['role' => 'employe', 'is_default' => true],
            ]);

            // S'assure que ce site est bien le default (remet is_default=false sur les autres)
            $user->sites()->where('sites.id', '!=', $item['site']->id)->updateExistingPivot(
                $user->sites()->where('sites.id', '!=', $item['site']->id)->pluck('sites.id')->toArray(),
                ['is_default' => false]
            );

            $this->command->line(
                "  <fg=green>✓</> {$user->name} → {$item['site']->nom}"
            );
        }

        $this->command->newLine();
        $this->command->info('✓ Utilisateurs rattachés à leurs sites.');

        // ── Résumé ────────────────────────────────────────────────────────────
        $this->command->newLine();
        $this->command->table(
            ['Utilisateur', 'Rôle', 'Site'],
            User::with(['sites', 'roles'])
                ->where('organization_id', $org->id)
                ->whereHas('roles', fn ($q) => $q->whereNotIn('name', ['client']))
                ->get()
                ->map(fn ($u) => [
                    $u->name,
                    $u->roles->pluck('name')->implode(', '),
                    $u->sites->map->nom->implode(', ') ?: '— aucun —',
                ])
                ->toArray()
        );
    }
}
