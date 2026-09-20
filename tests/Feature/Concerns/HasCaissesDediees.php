<?php

namespace Tests\Feature\Concerns;

use App\Enums\EvenementComptable;
use App\Models\CompteTresorerie;
use App\Models\PieceComptable;
use App\Models\Site;
use App\Models\User;
use App\Services\Comptabilite\EcritureComptableService;
use App\Services\Tresorerie\CaisseAgentService;
use App\Services\Tresorerie\SupportTresorerieValidationService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Aides des tests de caisses dédiées à un agent. Suppose `$this->org` et
 * `$this->user` (cf. HasOrgAndUser).
 */
trait HasCaissesDediees
{
    private function creerAgent(Site $site, string $prenom = 'Moussa', string $nom = 'Sidibé'): User
    {
        $agent = User::factory()->create([
            'organization_id' => $this->org->id,
            'prenom' => $prenom,
            'nom' => $nom,
        ]);
        $agent->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $agent;
    }

    /**
     * Caisse dédiée créée PUIS validée : c'est le parcours complet d'un support (création en
     * brouillon, validation), qui donne une caisse active et utilisable.
     */
    private function creerCaisseActive(string $siteId, string $agentId, ?string $libelle = null): CompteTresorerie
    {
        $brouillon = app(CaisseAgentService::class)->creer($this->org->id, $siteId, $agentId, $libelle);

        return app(SupportTresorerieValidationService::class)->valider($brouillon, $this->user);
    }

    /**
     * Utilisateur NON admin (rôle « manager », sans bypass de site) avec exactement ces
     * permissions, rattaché au site — pour tester les portées par site et par permission.
     *
     * @param  list<string>  $permissions
     */
    private function creerUtilisateurNonAdmin(Site $site, array $permissions, string $prenom = 'Aminata', string $nom = 'Responsable'): User
    {
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);

        $utilisateur = User::factory()->create([
            'organization_id' => $this->org->id,
            'prenom' => $prenom,
            'nom' => $nom,
        ]);
        $utilisateur->assignRole('manager');
        $utilisateur->givePermissionTo($permissions);
        $utilisateur->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $utilisateur;
    }

    /**
     * Pose au grand livre une pièce qui débite le compte propre de la caisse (le
     * système n'a pas encore de versement vers une caisse dédiée : c'est la manière
     * la plus directe d'y mettre un solde). Une seule alimentation par caisse — la
     * pièce est idempotente par (source, événement).
     */
    private function alimenterCaisse(CompteTresorerie $caisse, float $montant): PieceComptable
    {
        return app(EcritureComptableService::class)->comptabiliser(
            evenement: EvenementComptable::SOLDE_OUVERTURE_TRESORERIE,
            source: $caisse,
            organizationId: $caisse->organization_id,
            dateComptable: Carbon::now(),
            libelle: "Alimentation test — {$caisse->libelle}",
            lignes: [
                ['compte_comptable_id' => $caisse->compte_comptable_id, 'sens' => 'debit', 'montant' => $montant],
                ['role' => 'contrepartie_ouverture', 'sens' => 'credit', 'montant' => $montant],
            ],
            siteId: $caisse->site_id,
            createdBy: $this->user->id,
        );
    }

    /** Vide la caisse en contrepassant sa pièce d'alimentation. */
    private function viderCaisse(PieceComptable $pieceAlimentation): void
    {
        app(EcritureComptableService::class)->contrepasser($pieceAlimentation, 'Vidage test', $this->user->id);
    }

    /** @param  callable():mixed  $action */
    private function assertErreurValidationSur(string $champ, callable $action): void
    {
        try {
            $action();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($champ, $e->errors(), "Erreur attendue sur « {$champ} », obtenu : ".implode(', ', array_keys($e->errors())));

            return;
        }

        $this->fail("Une ValidationException sur « {$champ} » était attendue.");
    }
}
