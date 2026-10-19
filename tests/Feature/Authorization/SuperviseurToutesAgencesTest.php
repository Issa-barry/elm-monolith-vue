<?php

namespace Tests\Feature\Authorization;

use App\Enums\StatutCommandeVente;
use App\Enums\StatutTransfert;
use App\Models\CommandeVente;
use App\Models\CompteTresorerie;
use App\Models\Depense;
use App\Models\FactureVente;
use App\Models\MouvementFonds;
use App\Models\Organization;
use App\Models\PaiementFiche;
use App\Models\PaiementPeriode;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\TypeVehicule;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\SiteScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Superviseur (ADR 0034) : le rôle de nom technique `superviseur` agit et consulte sur toutes les
 * agences de l'organisation, comme un administrateur, quelles que soient ses permissions — sans case
 * « vision 360° ». Il n'obtient aucune permission pour autant, ni les fonctions réservées aux
 * administrateurs, et les réglages propres des modules (dépenses, achats…) s'appliquent à lui.
 */
class SuperviseurToutesAgencesTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private Site $agenceA;

    private Site $agenceB;

    private Role $roleSuperviseur;

    private User $superviseur;

    private User $temoin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser([]);

        $this->agenceA = $this->user->sites()->firstOrFail();
        $this->agenceB = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Agence Kindia',
            'type' => 'depot',
            'localisation' => 'Kindia',
        ]);

        $this->roleSuperviseur = $this->creerRole('superviseur', 'SUPERVISEUR', 'SUP', ['ventes.read']);
        $this->superviseur = $this->creerUtilisateur($this->roleSuperviseur, $this->agenceA);
        $this->temoin = $this->creerUtilisateur($this->creerRole('chef_agence', 'Chef d’agence', 'CA', ['ventes.read']), $this->agenceA);
    }

    private function creerRole(string $name, string $label, string $code, array $permissions): Role
    {
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $role = Role::create([
            'name' => $name,
            'guard_name' => 'web',
            'label' => $label,
            'code' => $code,
            'organization_id' => $this->org->id,
        ]);
        $role->givePermissionTo($permissions);

        return $role;
    }

    private function creerUtilisateur(Role $role, Site $site): User
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole($role);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function accorder(Role $role, array $permissions): User
    {
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $role->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $this->superviseur->fresh();
    }

    private function commande(Site $site, array $overrides = []): CommandeVente
    {
        static $seq = 0;
        $seq++;

        return CommandeVente::create(array_merge([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'statut' => StatutCommandeVente::LIVREE,
            'total_commande' => 5000,
            'reference' => 'SUP-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT).'-'.uniqid(),
            'numero' => $seq,
        ], $overrides));
    }

    private function facture(Site $site, float $montant): FactureVente
    {
        return FactureVente::create([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'commande_vente_id' => $this->commande($site, ['total_commande' => $montant])->id,
            'montant_brut' => $montant,
            'montant_net' => $montant,
            'statut_facture' => 'impayee',
        ]);
    }

    private function pageVentes(User $user, array $params = []): array
    {
        return $this->actingAs($user)
            ->get(route('ventes.index', array_merge(['periode' => 'all'], $params)))
            ->assertOk()
            ->viewData('page')['props'];
    }

    // ── Consultation : toutes les agences, sans case « vision 360° » ──────────

    public function test_le_superviseur_consulte_les_ventes_et_totaux_de_toutes_les_agences(): void
    {
        $this->facture($this->agenceA, 1000);
        $this->facture($this->agenceB, 4000);

        $this->assertFalse($this->superviseur->can(User::PERMISSION_LECTURE_TOUTES_AGENCES));

        $props = $this->pageVentes($this->superviseur);
        $this->assertCount(2, $props['commandes']);
        $this->assertEquals(5000, $props['totaux']['total_montant']);
        $this->assertEquals(5000, $props['totaux']['total_a_encaisser']);
        $this->assertTrue($props['auth']['voit_toutes_agences']);
        $this->assertEqualsCanonicalizing([$this->agenceA->nom, $this->agenceB->nom], array_column($props['sites'], 'nom'));

        // Filtre Agence : une seule agence, ou toutes.
        $filtre = $this->pageVentes($this->superviseur, ['site_ids' => [$this->agenceB->id]]);
        $this->assertCount(1, $filtre['commandes']);
        $this->assertEquals(4000, $filtre['totaux']['total_montant']);

        // Témoin : même permission, autre rôle → son agence seulement.
        $temoin = $this->pageVentes($this->temoin);
        $this->assertCount(1, $temoin['commandes']);
        $this->assertEquals(1000, $temoin['totaux']['total_montant']);
        $this->assertFalse($temoin['auth']['voit_toutes_agences']);
    }

    public function test_sans_la_permission_lire_le_superviseur_n_accede_pas_au_module(): void
    {
        $this->roleSuperviseur->syncPermissions([]);
        $superviseur = $this->accorder($this->roleSuperviseur, ['produits.read']);

        $this->actingAs($superviseur)->get(route('ventes.index'))->assertForbidden();
        $this->actingAs($superviseur)->get(route('ventes.show', $this->commande($this->agenceB)))->assertForbidden();
        $this->actingAs($superviseur)->get(route('ventes.export'))->assertForbidden();
    }

    public function test_les_autres_organisations_restent_invisibles(): void
    {
        $autreOrg = Organization::factory()->create();
        $etrangere = $this->commande(Site::create([
            'organization_id' => $autreOrg->id, 'nom' => 'Ailleurs', 'type' => 'depot', 'localisation' => 'Ailleurs',
        ]));
        $this->commande($this->agenceA);

        $this->assertCount(1, $this->pageVentes($this->superviseur)['commandes']);
        $this->actingAs($this->superviseur)->get(route('ventes.show', $etrangere))->assertForbidden();
    }

    // ── Écriture : toutes les agences, mais seulement avec la permission de l'action ──

    public function test_sans_permission_d_ecriture_le_superviseur_ne_modifie_aucune_vente(): void
    {
        foreach ([$this->agenceA, $this->agenceB] as $agence) {
            $brouillon = $this->commande($agence, ['statut' => StatutCommandeVente::BROUILLON]);
            $annulee = $this->commande($agence, ['statut' => StatutCommandeVente::ANNULEE]);
            $facture = $this->facture($agence, 3000);

            $this->actingAs($this->superviseur)->get(route('ventes.show', $brouillon))->assertOk();
            $this->actingAs($this->superviseur)->patch(route('ventes.valider', $brouillon))->assertForbidden();
            $this->actingAs($this->superviseur)->put(route('ventes.update', $brouillon), [])->assertForbidden();
            $this->actingAs($this->superviseur)->delete(route('ventes.destroy', $annulee))->assertForbidden();
            $this->actingAs($this->superviseur)->post(route('encaissements.store', $facture), [])->assertForbidden();

            $this->assertSame(StatutCommandeVente::BROUILLON, $brouillon->fresh()->statut);
            $this->assertNotNull($annulee->fresh());
        }

        $this->actingAs($this->superviseur)->post(route('ventes.store'), [])->assertForbidden();
    }

    public function test_avec_les_permissions_le_superviseur_agit_dans_une_autre_agence(): void
    {
        $superviseur = $this->accorder($this->roleSuperviseur, [
            'tresorerie.create', 'tresorerie.envoyer', 'tresorerie.valider_supports',
            'comptabilite.payer', 'logistique.update',
        ]);

        $this->assertSame([], app(SiteScopeService::class)->assignedSiteIds($superviseur)->all());

        $this->assertTrue($superviseur->can('regler', [MouvementFonds::class, $this->agenceB]));

        $support = (new CompteTresorerie)->forceFill(['organization_id' => $this->org->id, 'site_id' => $this->agenceB->id]);
        $this->assertTrue($superviseur->can('valider', $support));

        $fiche = (new PaiementFiche)->forceFill(['organization_id' => $this->org->id, 'site_id' => $this->agenceB->id]);
        $this->assertTrue($superviseur->can('payer', $fiche));

        $transfert = (new TransfertLogistique)->forceFill([
            'organization_id' => $this->org->id,
            'site_source_id' => $this->agenceA->id,
            'site_destination_id' => $this->agenceB->id,
            'statut' => StatutTransfert::TRANSIT,
        ]);
        $this->assertTrue($superviseur->can('validerReception', $transfert));

        // Témoin avec les mêmes permissions : son agence seulement.
        $temoin = $this->temoin;
        $temoin->roles->first()->givePermissionTo(['tresorerie.create', 'tresorerie.envoyer', 'tresorerie.valider_supports', 'comptabilite.payer', 'logistique.update']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $temoin = $temoin->fresh();
        $this->assertFalse($temoin->can('regler', [MouvementFonds::class, $this->agenceB]));
        $this->assertFalse($temoin->can('valider', $support));
        $this->assertFalse($temoin->can('payer', $fiche));
        $this->assertFalse($temoin->can('validerReception', $transfert));
    }

    public function test_le_superviseur_cree_un_vehicule_dans_une_autre_agence_avec_la_permission(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $payload = fn (string $immatriculation) => [
            'nom_vehicule' => 'Camion superviseur',
            'immatriculation' => $immatriculation,
            'type_vehicule_id' => TypeVehicule::where('organization_id', $this->org->id)->value('id'),
            'proprietaire_id' => $proprietaire->id,
            'categorie' => 'partenaire',
            'site_id' => $this->agenceB->id,
            'livraison_vente' => true,
            'livraison_logistique' => false,
        ];

        // Sans « Créer » : refusé, même dans toutes les agences.
        $this->actingAs($this->superviseur)->post(route('vehicules.store'), $payload('SUP-1'))->assertForbidden();

        $superviseur = $this->accorder($this->roleSuperviseur, ['vehicules.read', 'vehicules.create']);
        $this->actingAs($superviseur)->post(route('vehicules.store'), $payload('SUP-2'))->assertRedirect();
        $this->assertSame($this->agenceB->id, Vehicule::where('immatriculation', 'SUP-2')->value('site_id'));
    }

    // ── Ce que le rôle ne donne pas ───────────────────────────────────────────

    public function test_le_superviseur_n_obtient_pas_les_fonctions_reservees_aux_administrateurs(): void
    {
        $superviseur = $this->accorder($this->roleSuperviseur, ['ventes.update', 'comptabilite.read', 'comptabilite.payer']);

        $this->assertFalse($superviseur->isAdmin());
        $this->assertFalse($superviseur->can('create', PaiementPeriode::class));
        $this->assertFalse($superviseur->can('annuler', $this->commande($this->agenceB, ['statut' => StatutCommandeVente::BROUILLON])));
    }

    public function test_les_reglages_propres_aux_depenses_s_appliquent_au_superviseur(): void
    {
        $superviseur = $this->accorder($this->roleSuperviseur, ['depenses.update']);
        $depense = (new Depense)->forceFill(['organization_id' => $this->org->id, 'site_id' => $this->agenceB->id]);

        // Aucun droit de validation configuré pour son rôle dans Paramètres → pas de validation.
        $this->assertFalse($superviseur->can('valider', $depense));
    }

    // ── Le nom technique porte la règle ───────────────────────────────────────

    public function test_renommer_le_libelle_conserve_le_perimetre_et_un_autre_nom_ne_l_a_pas(): void
    {
        $this->commande($this->agenceA);
        $this->commande($this->agenceB);

        $this->roleSuperviseur->update(['label' => 'Superviseur général', 'code' => 'SG']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertCount(2, $this->pageVentes($this->superviseur->fresh())['commandes']);

        $adjoint = $this->creerUtilisateur($this->creerRole('superviseur_adjoint', 'Superviseur adjoint', 'SA', ['ventes.read']), $this->agenceA);
        $this->assertFalse($adjoint->agitSurToutesLesAgences());
        $this->assertCount(1, $this->pageVentes($adjoint)['commandes']);
    }
}
