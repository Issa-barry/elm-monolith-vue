<?php

namespace Tests\Feature;

use App\Enums\ClientType;
use App\Enums\CommissionMode;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionUniteCalcul;
use App\Http\Controllers\Settings\CommissionRegleController;
use App\Models\Categorie;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\TypeVehicule;
use App\Models\Vehicule;
use App\Services\Commission\CommissionProcessusDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Usage « Grossiste » du véhicule (ADR 0023) : troisième usage, indépendant de Vente et de
 * Logistique, qui décide seul si un véhicule peut livrer un client grossiste (processus de
 * commission transfert_grossiste).
 */
class VehiculeUsageGrossisteTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Categorie $categorie;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initOrgAndUser([
            'vehicules.read', 'vehicules.create', 'vehicules.update',
            'ventes.read', 'ventes.create', 'ventes.update',
        ]);
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);

        $this->categorie = Categorie::create([
            'organization_id' => $this->org->id,
            'nom' => 'Bouteille d\'eau',
            'statut' => 'actif',
        ]);

        // Barème Site seul : suffit à lever le blocage « aucun barème Transfert grossiste » sans
        // exiger d'équipe — ces tests portent sur l'usage du véhicule, pas sur les partages.
        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_TRANSFERT_GROSSISTE)->id,
            'libelle' => 'Site — Global',
            'scope_type' => CommissionScopeType::GLOBAL->value,
            'cible_type' => CommissionCibleType::CODE_SITE,
            'mode' => CommissionMode::DIRECT->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 100,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);
    }

    private function payloadVehicule(array $overrides = []): array
    {
        return array_merge([
            'nom_vehicule' => 'Minibus Grossiste',
            'immatriculation' => 'GR-001-GN',
            'type_vehicule_id' => TypeVehicule::where('organization_id', $this->org->id)->value('id'),
            'proprietaire_id' => Proprietaire::factory()->create(['organization_id' => $this->org->id])->id,
            'categorie' => 'partenaire',
            'site_id' => $this->user->sites()->first()->id,
            'is_active' => true,
            'livraison_vente' => false,
            'livraison_logistique' => false,
            'livraison_grossiste' => true,
        ], $overrides);
    }

    private function makeVehicule(array $usages, ?string $organizationId = null): Vehicule
    {
        $organizationId ??= $this->org->id;

        return Vehicule::factory()->create([
            'organization_id' => $organizationId,
            'proprietaire_id' => Proprietaire::factory()->create(['organization_id' => $organizationId])->id,
            'is_active' => true,
            ...$usages,
        ]);
    }

    private function makeGrossiste(): Client
    {
        return Client::factory()->create([
            'organization_id' => $this->org->id,
            'type' => ClientType::GROSSISTE->value,
        ]);
    }

    private function makeProduit(): Produit
    {
        return $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack Bouteille', 'type' => 'fabricable', 'categorie_id' => $this->categorie->id],
            ['prix_usine' => 15000, 'prix_vente' => 20000],
        );
    }

    private function posterVenteGrossiste(Client $client, Vehicule $vehicule)
    {
        return $this->actingAs($this->user)->post(route('ventes.store'), [
            'client_id' => $client->id,
            'vehicule_id' => $vehicule->id,
            'lignes' => [['produit_id' => $this->makeProduit()->id, 'qte' => 2, 'prix_vente' => 20000]],
        ]);
    }

    // ── Fiche véhicule ───────────────────────────────────────────────────────────

    public function test_un_vehicule_peut_etre_cree_avec_le_seul_usage_grossiste(): void
    {
        $this->actingAs($this->user)
            ->post(route('vehicules.store'), $this->payloadVehicule())
            ->assertSessionHasNoErrors();

        $vehicule = Vehicule::where('immatriculation', 'GR-001-GN')->firstOrFail();
        $this->assertTrue($vehicule->livraison_grossiste);
        $this->assertFalse($vehicule->livraison_vente);
        $this->assertFalse($vehicule->livraison_logistique);
        $this->assertSame('Grossiste', $vehicule->usage_label);
    }

    public function test_un_vehicule_sans_aucun_des_trois_usages_est_refuse(): void
    {
        $this->actingAs($this->user)
            ->post(route('vehicules.store'), $this->payloadVehicule(['livraison_grossiste' => false]))
            ->assertSessionHasErrors('livraison_vente');

        $this->assertDatabaseMissing('vehicules', ['immatriculation' => 'GR-001-GN']);
    }

    /** Appels antérieurs à l'usage Grossiste (champ absent) : création inchangée, usage non coché. */
    public function test_creation_sans_le_champ_grossiste_laisse_lusage_decoche(): void
    {
        $payload = $this->payloadVehicule(['livraison_vente' => true]);
        unset($payload['livraison_grossiste']);

        $this->actingAs($this->user)
            ->post(route('vehicules.store'), $payload)
            ->assertSessionHasNoErrors();

        $this->assertFalse(Vehicule::where('immatriculation', 'GR-001-GN')->firstOrFail()->livraison_grossiste);
    }

    public function test_modification_sans_le_champ_grossiste_conserve_lusage_enregistre(): void
    {
        $vehicule = $this->makeVehicule(['livraison_vente' => false, 'livraison_logistique' => false, 'livraison_grossiste' => true]);
        $payload = $this->payloadVehicule([
            'immatriculation' => $vehicule->immatriculation,
            'proprietaire_id' => $vehicule->proprietaire_id,
        ]);
        unset($payload['livraison_grossiste']);

        $this->actingAs($this->user)
            ->put(route('vehicules.update', $vehicule), $payload)
            ->assertSessionHasNoErrors();

        $this->assertTrue($vehicule->fresh()->livraison_grossiste);
    }

    public function test_lusage_grossiste_se_decoche_depuis_la_fiche(): void
    {
        $vehicule = $this->makeVehicule(['livraison_vente' => true, 'livraison_logistique' => true, 'livraison_grossiste' => true]);

        $this->actingAs($this->user)
            ->put(route('vehicules.update', $vehicule), $this->payloadVehicule([
                'immatriculation' => $vehicule->immatriculation,
                'proprietaire_id' => $vehicule->proprietaire_id,
                'livraison_vente' => true,
                'livraison_logistique' => true,
                'livraison_grossiste' => false,
            ]))
            ->assertSessionHasNoErrors();

        $vehicule->refresh();
        $this->assertFalse($vehicule->livraison_grossiste);
        $this->assertSame('Vente + Logistique', $vehicule->usage_label);
    }

    public function test_la_page_modifier_coche_lusage_grossiste_enregistre(): void
    {
        $vehicule = $this->makeVehicule(['livraison_vente' => true, 'livraison_logistique' => true, 'livraison_grossiste' => true]);

        $this->actingAs($this->user)
            ->get(route('vehicules.edit', $vehicule))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Vehicules/Edit')
                ->where('vehicule.livraison_grossiste', true)
            );
    }

    /** @return array<string, array{0: array<string, bool>, 1: list<string>}> */
    public static function combinaisonsUsages(): array
    {
        $v = CommissionProcessus::CODE_VENTE;
        $l = CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT;
        $g = CommissionProcessus::CODE_TRANSFERT_GROSSISTE;

        return [
            'vente seule' => [['livraison_vente' => true, 'livraison_logistique' => false, 'livraison_grossiste' => false], [$v]],
            'logistique seule' => [['livraison_vente' => false, 'livraison_logistique' => true, 'livraison_grossiste' => false], [$l]],
            'grossiste seul' => [['livraison_vente' => false, 'livraison_logistique' => false, 'livraison_grossiste' => true], [$g]],
            'vente + logistique' => [['livraison_vente' => true, 'livraison_logistique' => true, 'livraison_grossiste' => false], [$v, $l]],
            'vente + grossiste' => [['livraison_vente' => true, 'livraison_logistique' => false, 'livraison_grossiste' => true], [$v, $g]],
            'logistique + grossiste' => [['livraison_vente' => false, 'livraison_logistique' => true, 'livraison_grossiste' => true], [$l, $g]],
            'les trois' => [['livraison_vente' => true, 'livraison_logistique' => true, 'livraison_grossiste' => true], [$v, $l, $g]],
        ];
    }

    /**
     * Chaque usage ouvre son seul processus, indépendamment des deux autres (ADR 0023) — mêmes
     * processus pour les onglets de la fiche que pour la validation des partages d'équipe.
     *
     * @param  array<string, bool>  $usages
     * @param  list<string>  $processusAttendus
     */
    #[DataProvider('combinaisonsUsages')]
    public function test_chaque_combinaison_dusages_ouvre_exactement_ses_processus(array $usages, array $processusAttendus): void
    {
        $vehicule = $this->makeVehicule($usages);

        $this->assertSame(
            $processusAttendus,
            CommissionProcessusDefaults::codesApplicablesPourVehicule($vehicule, [
                CommissionProcessus::CODE_VENTE,
                CommissionProcessus::CODE_LOGISTIQUE_TRANSFERT,
                CommissionProcessus::CODE_TRANSFERT_GROSSISTE,
            ]),
        );

        $this->actingAs($this->user)
            ->get(route('vehicules.show', $vehicule))
            ->assertInertia(fn (Assert $page) => $page
                ->where('processus_options', array_map(
                    fn (string $code) => ['value' => $code, 'label' => CommissionRegleController::processusLabel($code)],
                    $processusAttendus,
                ))
            );
    }

    public function test_la_liste_filtre_les_vehicules_grossiste(): void
    {
        $grossiste = $this->makeVehicule(['livraison_vente' => false, 'livraison_grossiste' => true]);
        $venteSeule = $this->makeVehicule(['livraison_vente' => true, 'livraison_grossiste' => false]);

        $this->actingAs($this->user)
            ->get(route('vehicules.index', ['usage' => 'grossiste']))
            ->assertInertia(function (Assert $page) use ($grossiste, $venteSeule) {
                $ids = collect($page->toArray()['props']['vehicules'])->pluck('id');
                $this->assertTrue($ids->contains($grossiste->id));
                $this->assertFalse($ids->contains($venteSeule->id));
            });
    }

    // ── Saisie d'une vente ───────────────────────────────────────────────────────

    public function test_la_saisie_propose_une_liste_dediee_aux_vehicules_grossiste(): void
    {
        $grossiste = $this->makeVehicule(['livraison_vente' => false, 'livraison_logistique' => false, 'livraison_grossiste' => true]);
        $logistique = $this->makeVehicule(['livraison_vente' => false, 'livraison_logistique' => true, 'livraison_grossiste' => false]);
        $vente = $this->makeVehicule(['livraison_vente' => true, 'livraison_logistique' => false, 'livraison_grossiste' => false]);

        $this->actingAs($this->user)
            ->get(route('ventes.create'))
            ->assertInertia(function (Assert $page) use ($grossiste, $logistique, $vente) {
                $ids = collect($page->toArray()['props']['vehicules_grossiste'])->pluck('id');
                $this->assertSame([$grossiste->id], $ids->all());
                $this->assertNotContains($logistique->id, $ids);
                $this->assertNotContains($vente->id, $ids);
            });
    }

    public function test_un_grossiste_est_livre_par_un_vehicule_grossiste_sans_usage_vente_ni_logistique(): void
    {
        $client = $this->makeGrossiste();
        $vehicule = $this->makeVehicule(['livraison_vente' => false, 'livraison_logistique' => false, 'livraison_grossiste' => true]);

        $this->posterVenteGrossiste($client, $vehicule)
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $commande = CommandeVente::where('client_id', $client->id)->firstOrFail();
        $this->assertSame($vehicule->id, $commande->vehicule_id);
        // Éligibilité figée d'après l'usage Grossiste, jamais d'après l'usage Vente (absent ici).
        $this->assertTrue((bool) $commande->commission_eligible_snapshot);
    }

    public function test_un_vehicule_logistique_sans_usage_grossiste_ne_peut_pas_livrer_un_grossiste(): void
    {
        $client = $this->makeGrossiste();
        $vehicule = $this->makeVehicule(['livraison_vente' => true, 'livraison_logistique' => true, 'livraison_grossiste' => false]);

        $this->posterVenteGrossiste($client, $vehicule)->assertSessionHasErrors('vehicule_id');

        $this->assertDatabaseMissing('commandes_ventes', ['client_id' => $client->id]);
    }

    public function test_affecter_un_vehicule_sans_usage_grossiste_en_modification_est_refuse(): void
    {
        $client = $this->makeGrossiste();
        $produit = $this->makeProduit();
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->user->sites()->first()->id,
            'vehicule_id' => null,
            'client_id' => $client->id,
            'mode_remise_grossiste' => 'enlevement',
            'statut' => 'brouillon',
        ]);
        $vehicule = $this->makeVehicule(['livraison_vente' => true, 'livraison_grossiste' => false]);

        $this->actingAs($this->user)
            ->put(route('ventes.update', $commande), [
                'client_id' => $client->id,
                'vehicule_id' => $vehicule->id,
                'lignes' => [['produit_id' => $produit->id, 'qte' => 2, 'prix_vente' => 20000]],
            ])
            ->assertSessionHasErrors('vehicule_id');

        $this->assertNull($commande->fresh()->vehicule_id);
    }

    public function test_un_vehicule_grossiste_dune_autre_organisation_est_refuse(): void
    {
        $client = $this->makeGrossiste();
        $autreOrganisation = Organization::factory()->create();
        $vehicule = $this->makeVehicule(['livraison_grossiste' => true], $autreOrganisation->id);

        $this->posterVenteGrossiste($client, $vehicule)->assertSessionHasErrors('vehicule_id');

        $this->assertDatabaseMissing('commandes_ventes', ['client_id' => $client->id]);
    }

    // ── Reprise des véhicules existants ─────────────────────────────────────────

    private function ajouterPartageGrossiste(Vehicule $vehicule, ?string $effectiveTo = null): void
    {
        $equipe = EquipeLivraison::create(['organization_id' => $this->org->id, 'vehicule_id' => $vehicule->id, 'is_active' => true]);
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $livreur->id, 'role' => 'chauffeur', 'ordre' => 0]);
        EquipeLivraisonPartageCategorie::create([
            'equipe_id' => $equipe->id,
            'processus_id' => CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_TRANSFERT_GROSSISTE)->id,
            'categorie_id' => $this->categorie->id,
            'livreur_id' => $livreur->id,
            'part_pourcentage' => 0,
            'montant_unitaire' => 250,
            'effective_from' => now()->subMonth()->toDateString(),
            'effective_to' => $effectiveTo,
        ]);
    }

    /**
     * Décision du 09/10/2026 : reçoivent l'usage les véhicules déjà Vente ET Logistique, ceux dont
     * l'équipe a un partage Transfert grossiste en vigueur, et ceux qui ont déjà livré un grossiste
     * (la reprise ne retire jamais une pratique existante).
     */
    public function test_la_migration_coche_grossiste_sur_les_vehicules_vente_et_logistique_partages_ou_ayant_livre(): void
    {
        $mixte = $this->makeVehicule(['livraison_vente' => true, 'livraison_logistique' => true]);
        $venteSeule = $this->makeVehicule(['livraison_vente' => true, 'livraison_logistique' => false]);
        $logistiqueSeule = $this->makeVehicule(['livraison_vente' => false, 'livraison_logistique' => true]);
        $logistiqueAvecPartage = $this->makeVehicule(['livraison_vente' => false, 'livraison_logistique' => true]);
        $this->ajouterPartageGrossiste($logistiqueAvecPartage);
        $partageClos = $this->makeVehicule(['livraison_vente' => true, 'livraison_logistique' => false]);
        $this->ajouterPartageGrossiste($partageClos, now()->subDay()->toDateString());
        // Tricycle Vente seule qui livrait déjà des grossistes : jamais retiré de la liste.
        $tricycleGrossiste = $this->makeVehicule(['livraison_vente' => true, 'livraison_logistique' => false]);
        CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->user->sites()->first()->id,
            'vehicule_id' => $tricycleGrossiste->id,
            'client_id' => $this->makeGrossiste()->id,
            'mode_remise_grossiste' => 'livraison',
        ]);

        $migration = require database_path('migrations/2026_10_09_100000_add_livraison_grossiste_to_vehicules_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('vehicules', 'livraison_grossiste'));
        $migration->up();

        $usages = DB::table('vehicules')->pluck('livraison_grossiste', 'id')->map(fn ($v) => (bool) $v);
        $this->assertTrue($usages[$mixte->id]);
        $this->assertTrue($usages[$logistiqueAvecPartage->id]);
        $this->assertTrue($usages[$tricycleGrossiste->id]);
        $this->assertFalse($usages[$venteSeule->id]);
        $this->assertFalse($usages[$logistiqueSeule->id]);
        $this->assertFalse($usages[$partageClos->id]);
    }
}
