<?php

namespace Tests\Feature;

use App\Enums\ClientType;
use App\Models\Categorie;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\Site;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Chantier du 31/08/2026 : une distribution client (nature_operation = distribution_client) doit
 * désormais utiliser un véhicule réellement autorisé pour la logistique
 * (Vehicule::livraison_logistique = true), actif, appartenant à la même organisation, avec un
 * livreur (chauffeur) actif assigné — jamais seulement "un véhicule quelconque", comme c'était le
 * cas avant ce durcissement. Contrôle dépendant de nature_operation, jamais du seul type de
 * client (un distributeur peut toujours faire une vente_standard, ex: retrait sur site).
 */
class DistributionClientVehiculeLogistiqueTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $defaultSite;

    private ?Categorie $categorieDefaut = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.create', 'ventes.update']);
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);
        $this->defaultSite = Site::where('organization_id', $this->org->id)->firstOrFail();
    }

    private function defaultCategorie(): Categorie
    {
        return $this->categorieDefaut ??= Categorie::create([
            'organization_id' => $this->org->id,
            'nom' => 'Défaut',
        ]);
    }

    private function makeProduit(): Produit
    {
        return $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Sachet Distribution', 'categorie_id' => $this->defaultCategorie()->id],
            ['prix_vente' => 2000, 'prix_usine' => 1500],
        );
    }

    private function makeDistributeur(?Organization $org = null): Client
    {
        return Client::factory()->create([
            'organization_id' => ($org ?? $this->org)->id,
            'type' => ClientType::DISTRIBUTEUR->value,
        ]);
    }

    /**
     * @param  array{is_active?: bool, livraison_logistique?: bool, livraison_vente?: bool}  $overrides
     */
    private function makeVehicule(?Organization $org = null, array $overrides = []): Vehicule
    {
        return Vehicule::factory()->create(array_merge([
            'organization_id' => ($org ?? $this->org)->id,
            'livraison_vente' => false,
            'livraison_logistique' => true,
            'is_active' => true,
        ], $overrides));
    }

    private function assignChauffeurActif(Vehicule $vehicule, bool $livreurActif = true, bool $equipeActive = true): void
    {
        $equipe = EquipeLivraison::create([
            'organization_id' => $vehicule->organization_id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Équipe Distribution Test',
            'is_active' => $equipeActive,
        ]);
        $chauffeur = Livreur::factory()->create([
            'organization_id' => $vehicule->organization_id,
            'is_active' => $livreurActif,
        ]);
        EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $chauffeur->id, 'role' => 'chauffeur', 'ordre' => 0]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'nature_operation' => 'distribution_client',
            'lignes' => [
                ['produit_id' => $this->makeProduit()->id, 'qte' => 2, 'prix_vente' => 2000],
            ],
        ], $overrides);
    }

    // ── Refus 422 ────────────────────────────────────────────────────────────

    public function test_distribution_sans_vehicule_est_refusee(): void
    {
        $distributeur = $this->makeDistributeur();

        $this->actingAs($this->user)
            ->post(route('ventes.store'), $this->payload(['client_id' => $distributeur->id]))
            ->assertSessionHasErrors('nature_operation');

        $this->assertSame(0, CommandeVente::where('organization_id', $this->org->id)->count());
    }

    public function test_distribution_avec_vehicule_non_logistique_est_refusee(): void
    {
        $distributeur = $this->makeDistributeur();
        $vehicule = $this->makeVehicule(overrides: ['livraison_vente' => true, 'livraison_logistique' => false]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), $this->payload([
                'client_id' => $distributeur->id,
                'vehicule_id' => $vehicule->id,
            ]))
            ->assertSessionHasErrors('vehicule_id');

        $this->assertSame(0, CommandeVente::where('organization_id', $this->org->id)->count());
    }

    public function test_distribution_avec_vehicule_logistique_sans_aucune_equipe_est_refusee(): void
    {
        $distributeur = $this->makeDistributeur();
        $vehicule = $this->makeVehicule(); // logistique=true, mais aucune équipe assignée

        $this->actingAs($this->user)
            ->post(route('ventes.store'), $this->payload([
                'client_id' => $distributeur->id,
                'vehicule_id' => $vehicule->id,
            ]))
            ->assertSessionHasErrors('vehicule_id');

        $this->assertSame(0, CommandeVente::where('organization_id', $this->org->id)->count());
    }

    public function test_distribution_avec_chauffeur_inactif_est_refusee(): void
    {
        $distributeur = $this->makeDistributeur();
        $vehicule = $this->makeVehicule();
        $this->assignChauffeurActif($vehicule, livreurActif: false);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), $this->payload([
                'client_id' => $distributeur->id,
                'vehicule_id' => $vehicule->id,
            ]))
            ->assertSessionHasErrors('vehicule_id');
    }

    public function test_distribution_avec_equipe_inactive_est_refusee(): void
    {
        $distributeur = $this->makeDistributeur();
        $vehicule = $this->makeVehicule();
        $this->assignChauffeurActif($vehicule, livreurActif: true, equipeActive: false);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), $this->payload([
                'client_id' => $distributeur->id,
                'vehicule_id' => $vehicule->id,
            ]))
            ->assertSessionHasErrors('vehicule_id');
    }

    public function test_distribution_avec_vehicule_inactif_est_refusee(): void
    {
        $distributeur = $this->makeDistributeur();
        $vehicule = $this->makeVehicule(overrides: ['is_active' => false]);
        $this->assignChauffeurActif($vehicule);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), $this->payload([
                'client_id' => $distributeur->id,
                'vehicule_id' => $vehicule->id,
            ]))
            ->assertSessionHasErrors('vehicule_id');
    }

    /**
     * Une requête forgée ne doit jamais pouvoir utiliser le véhicule logistique d'une AUTRE
     * organisation, même parfaitement éligible dans la sienne — jamais une fuite d'existence
     * cross-organisation (même message que "véhicule introuvable").
     */
    public function test_distribution_avec_vehicule_dune_autre_organisation_est_refusee(): void
    {
        $distributeur = $this->makeDistributeur();
        $autreOrg = Organization::factory()->create();
        $vehiculeAutreOrg = $this->makeVehicule($autreOrg);
        $this->assignChauffeurActif($vehiculeAutreOrg);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), $this->payload([
                'client_id' => $distributeur->id,
                'vehicule_id' => $vehiculeAutreOrg->id,
            ]))
            ->assertSessionHasErrors('vehicule_id');

        $this->assertSame(0, CommandeVente::where('organization_id', $this->org->id)->count());
    }

    // ── Cas nominal ──────────────────────────────────────────────────────────

    public function test_distribution_avec_vehicule_logistique_et_chauffeur_actif_est_acceptee(): void
    {
        $distributeur = $this->makeDistributeur();
        $vehicule = $this->makeVehicule();
        $this->assignChauffeurActif($vehicule);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), $this->payload([
                'client_id' => $distributeur->id,
                'vehicule_id' => $vehicule->id,
            ]))
            ->assertSessionDoesntHaveErrors()
            ->assertRedirect();

        $commande = CommandeVente::where('organization_id', $this->org->id)->latest()->first();
        $this->assertNotNull($commande);
        $this->assertSame('distribution_client', $commande->nature_operation->value);
        // Éligibilité aux commissions dérivée de livraison_logistique pour une distribution —
        // jamais de livraison_vente (toujours false ici) : cf. VehiculeCommandeContextResolver.
        $this->assertTrue((bool) $commande->commission_eligible_snapshot);
    }

    /**
     * La dérivation automatique (aucune nature_operation explicitement soumise) doit elle aussi
     * exiger un véhicule logistique — jamais retomber sur DISTRIBUTION_CLIENT pour un simple
     * véhicule de vente sélectionné par un distributeur.
     */
    public function test_derivation_automatique_reste_vente_standard_si_vehicule_non_logistique(): void
    {
        $distributeur = $this->makeDistributeur();
        $vehiculeVenteOnly = $this->makeVehicule(overrides: ['livraison_vente' => true, 'livraison_logistique' => false]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'client_id' => $distributeur->id,
                'vehicule_id' => $vehiculeVenteOnly->id,
                'lignes' => [
                    ['produit_id' => $this->makeProduit()->id, 'qte' => 2, 'prix_vente' => 2000],
                ],
            ])
            ->assertSessionDoesntHaveErrors()
            ->assertRedirect();

        $commande = CommandeVente::where('organization_id', $this->org->id)->latest()->first();
        $this->assertNotNull($commande);
        $this->assertSame('vente_standard', $commande->nature_operation->value);
    }

    // ── Édition d'un brouillon : même garde-fou qu'à la création ─────────────

    /**
     * Une distribution avec véhicule passe immédiatement en A_CHARGER à la création
     * (CommandeVenteController::store() confirme dès qu'un véhicule est renseigné) — elle n'est
     * donc jamais éditable en pratique via update() (réservé aux BROUILLON). Ce test construit
     * directement un brouillon (contournant store()) pour vérifier que le garde-fou de update()
     * protège quand même correctement CE point d'entrée indépendamment — défense en profondeur,
     * jamais une confiance dans le fait qu'un autre chemin empêcherait déjà d'y arriver.
     */
    public function test_update_dune_distribution_vers_un_vehicule_incompatible_est_refuse(): void
    {
        $distributeur = $this->makeDistributeur();
        $vehicule = $this->makeVehicule();
        $this->assignChauffeurActif($vehicule);
        $produit = $this->makeProduit();

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'client_id' => $distributeur->id,
            'vehicule_id' => $vehicule->id,
            'nature_operation' => 'distribution_client',
            'statut' => 'brouillon',
        ]);
        $commande->lignes()->create([
            'variante_id' => $produit->variantePrincipale()->first()->id,
            'quantite_demandee' => 2,
            'prix_usine_snapshot' => 1500,
            'prix_vente_snapshot' => 2000,
            'total_ligne' => 4000,
        ]);

        $vehiculeIncompatible = $this->makeVehicule(overrides: ['livraison_vente' => true, 'livraison_logistique' => false]);

        $this->actingAs($this->user)
            ->put(route('ventes.update', $commande), [
                'client_id' => $distributeur->id,
                'vehicule_id' => $vehiculeIncompatible->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'qte' => 2, 'prix_vente' => 2000],
                ],
            ])
            ->assertSessionHasErrors('vehicule_id');

        // Le véhicule d'origine, valide, doit rester inchangé — l'update refusé ne doit jamais
        // avoir partiellement appliqué la modification.
        $this->assertSame($vehicule->id, $commande->fresh()->vehicule_id);
    }

    // ── Vente standard : comportement inchangé ───────────────────────────────

    public function test_vente_standard_nest_jamais_soumise_aux_exigences_de_la_distribution(): void
    {
        $vehiculeVenteOnly = $this->makeVehicule(overrides: ['livraison_vente' => true, 'livraison_logistique' => false]);
        // Ni équipe ni chauffeur assignés — une vente standard n'en a jamais eu besoin.

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'vehicule_id' => $vehiculeVenteOnly->id,
                'lignes' => [
                    ['produit_id' => $this->makeProduit()->id, 'qte' => 2, 'prix_vente' => 2000],
                ],
            ])
            ->assertSessionDoesntHaveErrors()
            ->assertRedirect();

        $commande = CommandeVente::where('organization_id', $this->org->id)->latest()->first();
        $this->assertNotNull($commande);
        $this->assertSame('vente_standard', $commande->nature_operation->value);
    }
}
