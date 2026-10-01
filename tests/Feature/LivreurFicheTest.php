<?php

namespace Tests\Feature;

use App\Enums\StatutCommission;
use App\Enums\StatutFactureVente;
use App\Models\CommandeVente;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\FactureVente;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Fiche livreur du backoffice (onglets Informations / Véhicule & équipe / Commissions /
 * Factures) — cf. App\Support\Livreurs\FicheLivreurStaffData.
 */
class LivreurFicheTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['livreurs.read']);
    }

    private function donnerPermissions(array $permissions): void
    {
        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $this->user->givePermissionTo($permissions);
    }

    /** @return array{0: Livreur, 1: Livreur, 2: Vehicule} */
    private function makeEquipe(): array
    {
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'nom_vehicule' => 'TRICYCLE-001',
            'immatriculation' => 'RC-1234-AB',
        ]);
        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'is_active' => true,
        ]);
        $chauffeur = Livreur::factory()->create(['organization_id' => $this->org->id, 'nom_complet' => 'Chauffeur A']);
        $convoyeur = Livreur::factory()->create(['organization_id' => $this->org->id, 'nom_complet' => 'Convoyeur B']);
        EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $chauffeur->id, 'role' => 'chauffeur', 'ordre' => 1]);
        EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $convoyeur->id, 'role' => 'convoyeur', 'ordre' => 2]);

        return [$chauffeur, $convoyeur, $vehicule];
    }

    private function makePart(string $orgId, string $livreurId, float $montant, StatutCommission $statut, float $verse = 0): void
    {
        $processus = CommissionProcessus::firstOrCreate(
            ['organization_id' => $orgId, 'code' => CommissionProcessus::CODE_VENTE],
            ['libelle' => 'Vente', 'declencheur' => 'chargement_valide', 'strategie_ancrage_site' => 'operation', 'statut' => 'actif'],
        );
        $commande = CommandeVente::factory()->create(['organization_id' => $orgId]);
        $enveloppe = CommissionEnveloppe::create([
            'organization_id' => $orgId,
            'source_type' => CommandeVente::class,
            'source_id' => $commande->id,
            'processus_id' => $processus->id,
            'cible_type' => 'equipe_livraison',
            'cible_id' => (string) Str::ulid(),
            'montant_total' => $montant,
            'earned_at' => now(),
            'statut' => $statut->value,
        ]);
        $enveloppe->parts()->create([
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_LIVREUR,
            'beneficiaire_id' => $livreurId,
            'montant_brut' => $montant,
            'montant_net' => $montant,
            'montant_verse' => $verse,
            'statut' => $statut->value,
        ]);
    }

    public function test_fiche_staff_expose_vehicule_et_coequipiers(): void
    {
        [$chauffeur, $convoyeur] = $this->makeEquipe();

        $this->actingAs($this->user)
            ->get(route('livreurs.show', $chauffeur))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Livreurs/Show')
                ->where('is_staff', true)
                ->has('fiche.equipes', 1)
                ->where('fiche.equipes.0.role', 'chauffeur')
                ->where('fiche.equipes.0.vehicule.nom', 'TRICYCLE-001')
                ->where('fiche.equipes.0.vehicule.immatriculation', 'RC-1234-AB')
                ->has('fiche.equipes.0.coequipiers', 1)
                ->where('fiche.equipes.0.coequipiers.0.id', $convoyeur->id)
                ->where('fiche.equipes.0.coequipiers.0.role', 'convoyeur')
            );
    }

    public function test_onglets_commissions_et_factures_absents_sans_permission(): void
    {
        [$chauffeur] = $this->makeEquipe();

        $this->actingAs($this->user)
            ->get(route('livreurs.show', $chauffeur))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('fiche.commissions', null)
                ->where('fiche.factures', null)
                // Pas de lien vers la fiche véhicule sans vehicules.read.
                ->where('fiche.equipes.0.vehicule.url', null)
            );
    }

    public function test_synthese_commissions_reprend_la_partition_des_kpis(): void
    {
        $this->donnerPermissions(['comptabilite.read']);
        [$chauffeur] = $this->makeEquipe();
        $this->makePart($this->org->id, $chauffeur->id, 100000, StatutCommission::CREEE);
        $this->makePart($this->org->id, $chauffeur->id, 50000, StatutCommission::IMPAYE);
        $this->makePart($this->org->id, $chauffeur->id, 30000, StatutCommission::PAYE, 30000);
        $this->makePart($this->org->id, $chauffeur->id, 999000, StatutCommission::ANNULEE);

        $autreOrg = Organization::factory()->create();
        $this->makePart($autreOrg->id, $chauffeur->id, 777000, StatutCommission::IMPAYE);

        $this->actingAs($this->user)
            ->get(route('livreurs.show', $chauffeur))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('fiche.commissions.kpis.total_genere', 180000)
                ->where('fiche.commissions.kpis.en_attente_periode', 100000)
                ->where('fiche.commissions.kpis.payable', 50000)
                ->where('fiche.commissions.kpis.deja_paye', 30000)
                ->has('fiche.commissions.recentes', 4)
                ->where('fiche.commissions.detail_url', route('comptabilite.commissions.vente.livreur', $chauffeur->id))
            );
    }

    public function test_factures_du_vehicule_du_livreur(): void
    {
        $this->donnerPermissions(['ventes.read']);
        [$chauffeur, , $vehicule] = $this->makeEquipe();

        $commande = CommandeVente::factory()->create(['organization_id' => $this->org->id, 'vehicule_id' => $vehicule->id]);
        FactureVente::factory()->create([
            'organization_id' => $this->org->id,
            'commande_vente_id' => $commande->id,
            'montant_net' => 400000,
            'statut_facture' => StatutFactureVente::IMPAYEE,
        ]);
        $commandeAnnulee = CommandeVente::factory()->create(['organization_id' => $this->org->id, 'vehicule_id' => $vehicule->id]);
        FactureVente::factory()->create([
            'organization_id' => $this->org->id,
            'commande_vente_id' => $commandeAnnulee->id,
            'montant_net' => 900000,
            'statut_facture' => StatutFactureVente::ANNULEE,
        ]);
        // Facture d'un autre véhicule : hors périmètre du livreur.
        FactureVente::factory()->create([
            'organization_id' => $this->org->id,
            'commande_vente_id' => CommandeVente::factory()->create(['organization_id' => $this->org->id])->id,
            'montant_net' => 123000,
        ]);

        $this->actingAs($this->user)
            ->get(route('livreurs.show', $chauffeur))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('fiche.factures.totaux.nb', 1)
                ->where('fiche.factures.totaux.montant', 400000)
                ->where('fiche.factures.totaux.a_encaisser', 400000)
                ->has('fiche.factures.recentes', 2)
                ->where('fiche.factures.liste_url', route('factures.index', ['livreur_id' => $chauffeur->id, 'periode' => 'tout']))
            );
    }

    public function test_livreur_sur_sa_propre_fiche_garde_la_vue_espace_livreur(): void
    {
        Role::firstOrCreate(['name' => 'livreur', 'guard_name' => 'web']);
        $compte = User::factory()->create(['organization_id' => $this->org->id]);
        $compte->assignRole('livreur');
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id, 'user_id' => $compte->id]);

        $this->actingAs($compte)
            ->get(route('livreurs.show', $livreur))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('is_staff', false)
                ->where('fiche', null)
                ->where('commissions_url', route('client.earnings'))
            );
    }
}
