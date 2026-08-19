<?php

namespace Tests\Feature;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionMode;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\CommissionUniteCalcul;
use App\Enums\DeclencheurCommissionVente;
use App\Enums\StatutCommandeVente;
use App\Models\Categorie;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionPart;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\CommissionVente;
use App\Models\EquipeLivraison;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Round-trip complet équipe (popup/contrôleur) → vente réelle → commission,
 * pour chacun des deux moteurs — cf. correction post-Phase 2 (régression
 * signalée : la bascule LEGACY/V2 doit rester sûre de bout en bout, pas
 * seulement au niveau du formulaire équipe).
 *
 * Le second cas (V2) exerce aussi le branchement CommissionTriggerService, qui
 * ne savait auparavant appeler QUE l'ancien moteur, quel que soit le
 * commission_processus de l'organisation (gap découvert et corrigé dans le
 * même correctif : cf. CommissionTriggerService::genererCommissionVente()).
 */
class EquipeLivraisonMoteurRoundTripTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $defaultSite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser([
            'equipes-livraison.read', 'equipes-livraison.create', 'equipes-livraison.update',
            'ventes.read', 'ventes.create', 'ventes.update',
        ]);

        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::CHARGEMENT_VALIDE);

        $this->defaultSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->defaultSite->id, ['role' => 'employe', 'is_default' => true]);
    }

    private function makeProduit(?string $categorieId = null): Produit
    {
        return $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Produit Test', 'categorie_id' => $categorieId],
            ['prix_vente' => 2000, 'prix_usine' => 1500],
        );
    }

    private function validerVente(Vehicule $vehicule, Produit $produit, int $quantite): CommandeVente
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $vehicule->organization_id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 4000,
        ]);
        $variante = $produit->variantePrincipale()->first();
        $ligne = $commande->lignes()->create([
            'variante_id' => $variante->id,
            'quantite_demandee' => $quantite,
            'prix_usine_snapshot' => (float) $variante->prix_usine,
            'prix_vente_snapshot' => (float) $variante->prix_vente,
            'total_ligne' => $quantite * (float) $variante->prix_vente,
        ]);

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande);
        CommandeVenteService::validerChargement($commande, [[
            'id' => $ligne->id, 'quantite_chargee' => $quantite, 'type_ecart' => 'conforme',
        ]]);

        return $commande->fresh();
    }

    // ── LEGACY : modifier l'équipe via le contrôleur, puis vendre ───────────

    public function test_organisation_legacy_modifier_equipe_puis_nouvelle_vente_genere_la_commission_legacy_correcte(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'capacite_packs' => 10,
        ]);

        // Équipe initiale : 950 = proprio 650 + chauffeur 300.
        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), [
                'vehicule_id' => $vehicule->id,
                'is_active' => true,
                'commission_unitaire_par_pack' => 950,
                'montant_par_pack_proprietaire' => 650,
                'membres' => [[
                    'livreur_id' => null, 'nom_complet' => 'Mamadou Diallo',
                    'telephone' => '+224620000001', 'role' => 'chauffeur',
                    'montant_par_pack' => 300, 'ordre' => 0,
                ]],
            ])
            ->assertRedirectContains('/backoffice/vehicules/');

        $equipe = EquipeLivraison::where('vehicule_id', $vehicule->id)->firstOrFail();

        // Un admin modifie l'équipe AVANT la vente : nouvelle répartition
        // 950 = proprio 570 (60 %) + chauffeur 380 (40 %).
        $this->actingAs($this->user)
            ->patch(route('equipes-livraison.update', $equipe), [
                'vehicule_id' => $vehicule->id,
                'is_active' => true,
                'commission_unitaire_par_pack' => 950,
                'montant_par_pack_proprietaire' => 570,
                'membres' => [[
                    'livreur_id' => null, 'nom_complet' => 'Mamadou Diallo',
                    'telephone' => '+224620000001', 'role' => 'chauffeur',
                    'montant_par_pack' => 380, 'ordre' => 0,
                ]],
            ])
            ->assertRedirectContains('/backoffice/vehicules/');

        $equipe->refresh();
        $this->assertEquals(60.0, (float) $equipe->taux_commission_proprietaire);

        $produit = $this->makeProduit();
        $commande = $this->validerVente($vehicule, $produit, quantite: 2);

        // Marge = (2000 - 1500) × 2 = 1000.
        $commission = CommissionVente::where('commande_vente_id', $commande->id)->firstOrFail();
        $this->assertEquals(1000.0, (float) $commission->montant_commission_totale);

        $partProp = CommissionPart::where('commission_vente_id', $commission->id)
            ->where('type_beneficiaire', 'proprietaire')->firstOrFail();
        $partChauffeur = CommissionPart::where('commission_vente_id', $commission->id)
            ->where('type_beneficiaire', 'livreur')->firstOrFail();

        // 60 % / 40 % de 1000 : la répartition APRÈS modification, pas celle d'origine.
        $this->assertEqualsWithDelta(600.0, (float) $partProp->montant_brut, 0.01);
        $this->assertEqualsWithDelta(400.0, (float) $partChauffeur->montant_brut, 0.01);
    }

    // ── V2 : configurer l'équipe via le contrôleur, puis vendre ─────────────

    public function test_organisation_v2_store_equipe_via_le_controleur_puis_nouvelle_vente_genere_lenveloppe_v2_sans_toucher_lancien_moteur(): void
    {
        $processus = CommissionProcessus::create([
            'organization_id' => $this->org->id,
            'code' => CommissionProcessus::CODE_VENTE,
            'libelle' => 'Vente',
            'declencheur' => 'chargement_valide',
            'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);

        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $processus->id,
            'libelle' => 'Propriétaire global',
            'scope_type' => CommissionScopeType::GLOBAL->value,
            'scope_id' => null,
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
            'mode' => CommissionMode::DIRECT->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 600,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);
        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $processus->id,
            'libelle' => 'Livraison global',
            'scope_type' => CommissionScopeType::GLOBAL->value,
            'scope_id' => null,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'mode' => CommissionMode::A_REPARTIR->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 300,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);

        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);

        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'capacite_packs' => 10,
        ]);

        // Popup V2 : le propriétaire n'est jamais dans le payload ; le partage
        // Livraison est défini PAR CATÉGORIE (décision AMOA post-Phase 2), un
        // seul livreur à 100 % pour l'unique catégorie vendue ici.
        $this->actingAs($this->user)
            ->post(route('equipes-livraison.store'), [
                'vehicule_id' => $vehicule->id,
                'is_active' => true,
                'membres' => [[
                    'livreur_id' => null, 'nom_complet' => 'Mamadou Diallo',
                    'telephone' => '+224620000001', 'role' => 'chauffeur', 'ordre' => 0,
                ]],
                'partages_categorie' => [[
                    'categorie_id' => $categorie->id,
                    'parts' => [
                        ['membre_ordre' => 0, 'part_pourcentage' => 100],
                    ],
                ]],
            ])
            ->assertRedirectContains('/backoffice/vehicules/');

        $equipe = EquipeLivraison::where('vehicule_id', $vehicule->id)->firstOrFail();
        // Jamais maintenus pour une organisation V2 : aucun besoin, restent à leur valeur par défaut.
        $this->assertSame(0.0, (float) $equipe->commission_unitaire_par_pack);
        $this->assertNull($equipe->montant_par_pack_proprietaire);
        $this->assertNull($equipe->taux_commission_proprietaire);

        $produit = $this->makeProduit($categorie->id);
        $commande = $this->validerVente($vehicule, $produit, quantite: 5);

        // L'ancien moteur ne doit JAMAIS tourner pour cette organisation.
        $this->assertDatabaseMissing('commissions_ventes', ['commande_vente_id' => $commande->id]);

        $enveloppeProp = CommissionEnveloppe::where('source_id', $commande->id)
            ->where('cible_type', 'proprietaire')->firstOrFail();
        $enveloppeLiv = CommissionEnveloppe::where('source_id', $commande->id)
            ->where('cible_type', 'equipe_livraison')->firstOrFail();

        $this->assertSame(3000.0, (float) $enveloppeProp->montant_total); // 600 × 5
        $this->assertSame(1500.0, (float) $enveloppeLiv->montant_total); // 300 × 5

        $partLivreur = CommissionEnveloppePart::where('enveloppe_id', $enveloppeLiv->id)->firstOrFail();
        $this->assertEqualsWithDelta(1500.0, (float) $partLivreur->montant_brut, 0.01); // 100 % du seul livreur
    }
}
