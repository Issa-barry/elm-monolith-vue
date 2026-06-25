<?php

namespace Tests\Feature;

use App\Enums\StatutCommandeVente;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\FactureVente;
use App\Models\Livreur;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Tests du workflow de statut des commandes vente.
 *
 * Workflow : BROUILLON → A_CHARGER → CHARGEMENT_EN_COURS → LIVRAISON_EN_COURS → LIVREE → CLOTUREE
 *                       ↘ ANNULEE (depuis BROUILLON ou A_CHARGER seulement)
 *
 * Routes testées :
 *   POST  /ventes/{id}/statut/avancer  (CommandeVenteStatutController::avancer)
 *   POST  /ventes/{id}/statut/annuler  (CommandeVenteStatutController::annuler)
 */
class CommandeVenteStatutTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private Site $defaultSite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.create', 'ventes.update', 'ventes.delete']);

        $this->defaultSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->defaultSite->id, ['role' => 'employe', 'is_default' => true]);
    }

    /**
     * Crée une commande avec un produit, un véhicule et une ligne, et la fait
     * réellement progresser (confirmer / demarrerChargement / validerChargement)
     * jusqu'au statut cible — pour que facture/commissions reflètent fidèlement
     * ce que produirait le workflow réel à ce stade.
     *
     * @param  array<string, mixed>  $attrs  Surcharges pour CommandeVente::factory()
     * @return array{commande: CommandeVente, ligne: CommandeVenteLigne, produit: Produit, vehicule: Vehicule}
     */
    private function makeCommandeWithLigne(array $attrs = [], ?Vehicule $vehicule = null): array
    {
        $cible = $attrs['statut'] ?? StatutCommandeVente::BROUILLON;
        unset($attrs['statut']);

        $produit = Produit::create([
            'organization_id' => $this->org->id,
            'nom' => 'Produit Test',
            'type' => 'materiel',
            'statut' => 'actif',
            'prix_vente' => 2000,
            'prix_usine' => 1500,
        ]);

        if (! $vehicule) {
            $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
            $vehicule = Vehicule::factory()->create([
                'organization_id' => $this->org->id,
                'proprietaire_id' => $proprietaire->id,
                'capacite_packs' => 10,
            ]);
        }

        $commande = CommandeVente::factory()->create(array_merge([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 4000,
        ], $attrs));

        $ligne = $commande->lignes()->create([
            'produit_id' => $produit->id,
            'quantite_demandee' => 2,
            'prix_usine_snapshot' => 1500.0,
            'prix_vente_snapshot' => 2000.0,
            'total_ligne' => 4000.0,
        ]);

        $commande = $this->avancerJusqua($commande, $ligne, $cible);

        return compact('commande', 'ligne', 'produit', 'vehicule');
    }

    /**
     * Fait progresser une commande BROUILLON jusqu'au statut cible en passant
     * réellement par CommandeVenteService (confirmer / demarrerChargement /
     * validerChargement), pour que facture et commissions soient créées comme
     * le ferait l'application.
     */
    private function avancerJusqua(CommandeVente $commande, CommandeVenteLigne $ligne, StatutCommandeVente $cible): CommandeVente
    {
        if ($cible === StatutCommandeVente::BROUILLON) {
            return $commande;
        }

        CommandeVenteService::confirmer($commande);
        if ($cible === StatutCommandeVente::A_CHARGER) {
            return $commande->fresh();
        }

        CommandeVenteService::demarrerChargement($commande);
        if ($cible === StatutCommandeVente::CHARGEMENT_EN_COURS) {
            return $commande->fresh();
        }

        CommandeVenteService::validerChargement($commande, [[
            'id' => $ligne->id,
            'quantite_chargee' => $ligne->quantite_demandee,
            'type_ecart' => 'conforme',
        ]]);

        return $commande->fresh();
    }

    /**
     * Crée un véhicule avec une équipe à 2 membres (chauffeur + convoyeur).
     */
    private function makeVehiculeAvecEquipe(float $tauxChauffeur = 18.42, float $tauxConvoyeur = 13.16): Vehicule
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'capacite_packs' => 10,
        ]);

        $chauffeur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $convoyeur = Livreur::factory()->create(['organization_id' => $this->org->id]);

        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Équipe Test',
            'is_active' => true,
            'taux_commission_proprietaire' => round(100 - $tauxChauffeur - $tauxConvoyeur, 2),
        ]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $chauffeur->id,
            'taux_commission' => $tauxChauffeur,
            'role' => 'chauffeur',
            'ordre' => 0,
        ]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => $convoyeur->id,
            'taux_commission' => $tauxConvoyeur,
            'role' => 'convoyeur',
            'ordre' => 1,
        ]);

        return $vehicule->fresh();
    }

    // ── BROUILLON → A_CHARGER ─────────────────────────────────────────────────

    public function test_avancer_confirme_brouillon_en_a_charger(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne();

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande))
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::A_CHARGER, $commande->fresh()->statut);
    }

    public function test_confirmer_exige_vehicule(): void
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => null,
            'statut' => StatutCommandeVente::BROUILLON,
        ]);

        $produit = Produit::create([
            'organization_id' => $this->org->id,
            'nom' => 'Produit', 'type' => 'materiel', 'statut' => 'actif',
            'prix_vente' => 2000, 'prix_usine' => 1500,
        ]);
        $commande->lignes()->create([
            'produit_id' => $produit->id, 'quantite_demandee' => 1,
            'prix_usine_snapshot' => 1500.0, 'prix_vente_snapshot' => 2000.0, 'total_ligne' => 2000.0,
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande))
            ->assertSessionHasErrors('statut');
    }

    public function test_confirmer_exige_au_moins_une_ligne(): void
    {
        ['vehicule' => $vehicule] = $this->makeCommandeWithLigne();

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande))
            ->assertSessionHasErrors('statut');
    }

    // ── A_CHARGER → CHARGEMENT_EN_COURS ──────────────────────────────────────

    public function test_avancer_demarre_chargement_depuis_a_charger(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::A_CHARGER,
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande))
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::CHARGEMENT_EN_COURS, $commande->fresh()->statut);
    }

    public function test_confirmer_cree_la_facture_en_statut_creee(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'total_commande' => 4000,
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande))
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::A_CHARGER, $commande->fresh()->statut);

        $this->assertDatabaseHas('factures_ventes', [
            'commande_vente_id' => $commande->id,
            'montant_brut' => 4000,
            'statut_facture' => 'creee',
        ]);
    }

    public function test_confirmer_cree_facture_avec_meme_reference(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne();

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande))
            ->assertRedirect();

        $fresh = $commande->fresh();
        $facture = $fresh->facture;

        $this->assertNotNull($facture);
        $this->assertEquals($fresh->reference, $facture->reference);
    }

    public function test_confirmer_cree_les_commissions_en_statut_creee_pour_chauffeur_et_convoyeur(): void
    {
        $vehicule = $this->makeVehiculeAvecEquipe();
        ['commande' => $commande] = $this->makeCommandeWithLigne([], $vehicule);

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande))
            ->assertRedirect();

        $this->assertDatabaseHas('commissions_ventes', [
            'commande_vente_id' => $commande->id,
            'statut' => 'creee',
        ]);

        $commission = $commande->fresh()->commissions()->first();
        $this->assertNotNull($commission);
        // 2 parts livreur (chauffeur + convoyeur) + 1 part propriétaire, toutes en Créée.
        $this->assertEquals(3, $commission->parts()->where('statut', 'creee')->count());
        $this->assertEqualsCanonicalizing(
            ['chauffeur', 'convoyeur'],
            $commission->parts()->where('type_beneficiaire', 'livreur')->pluck('role')->all()
        );
    }

    public function test_demarrer_chargement_ne_recree_pas_la_facture_ni_les_commissions(): void
    {
        $vehicule = $this->makeVehiculeAvecEquipe();
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::A_CHARGER,
        ], $vehicule);

        $factureId = $commande->fresh()->facture->id;
        $commissionAvant = $commande->fresh()->commissions()->first();
        $commissionId = $commissionAvant->id;
        $nbPartsAvant = $commissionAvant->parts()->count();

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande))
            ->assertRedirect();

        $fresh = $commande->fresh();
        $this->assertEquals(StatutCommandeVente::CHARGEMENT_EN_COURS, $fresh->statut);
        $this->assertEquals($factureId, $fresh->facture->id);
        $this->assertEquals($commissionId, $fresh->commissions()->first()->id);
        $this->assertEquals(1, FactureVente::where('commande_vente_id', $commande->id)->count());
        $this->assertEquals(1, $fresh->commissions()->count());
        $this->assertEquals($nbPartsAvant, $fresh->commissions()->first()->parts()->count());
    }

    // ── CHARGEMENT_EN_COURS → LIVRAISON_EN_COURS ──────────────────────────────

    public function test_avancer_valide_chargement_avec_quantites(): void
    {
        ['commande' => $commande, 'ligne' => $ligne] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::CHARGEMENT_EN_COURS,
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande), [
                'lignes' => [[
                    'id' => $ligne->id,
                    'quantite_chargee' => 2,
                    'type_ecart' => 'conforme',
                    'commentaire_ecart' => null,
                ]],
            ])
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::LIVRAISON_EN_COURS, $commande->fresh()->statut);
        $this->assertEquals(2, $ligne->fresh()->quantite_chargee);
    }

    public function test_avancer_valide_chargement_enregistre_ecart(): void
    {
        ['commande' => $commande, 'ligne' => $ligne] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::CHARGEMENT_EN_COURS,
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande), [
                'lignes' => [[
                    'id' => $ligne->id,
                    'quantite_chargee' => 1,
                    'type_ecart' => 'manquant',
                    'commentaire_ecart' => 'Un pack endommagé',
                ]],
            ])
            ->assertRedirect();

        $freshLigne = $ligne->fresh();
        $this->assertEquals(1, $freshLigne->quantite_chargee);
        $this->assertEquals('manquant', $freshLigne->type_ecart->value);
        $this->assertEquals('Un pack endommagé', $freshLigne->commentaire_ecart);
    }

    public function test_avancer_valide_chargement_recalcule_totaux_sur_ecart(): void
    {
        ['commande' => $commande, 'ligne' => $ligne] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::A_CHARGER,
            'total_commande' => 4000,
        ]);

        // La facture existe déjà (créée à 4000 dès la confirmation, sur la quantité demandée).
        // Démarre le chargement.
        $this->actingAs($this->user)->post(route('ventes.statut.avancer', $commande))->assertRedirect();

        // Valide le chargement avec une quantité chargée inférieure à la demandée (40 au lieu de 60 dans le cas réel ; ici 1 au lieu de 2)
        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande), [
                'lignes' => [[
                    'id' => $ligne->id,
                    'quantite_chargee' => 1,
                    'type_ecart' => 'manquant',
                    'commentaire_ecart' => 'Casse',
                ]],
            ])
            ->assertRedirect();

        $freshCommande = $commande->fresh();
        $freshLigne = $ligne->fresh();

        // 1 × prix_vente_snapshot (2000) = 2000, pas 4000 (basé sur la quantité demandée)
        $this->assertEquals(2000, (float) $freshLigne->total_ligne);
        $this->assertEquals(2000, (float) $freshCommande->total_commande);

        $this->assertDatabaseHas('factures_ventes', [
            'commande_vente_id' => $commande->id,
            'montant_brut' => 2000,
            'montant_net' => 2000,
        ]);
    }

    public function test_valider_chargement_recalcule_commissions_chauffeur_et_convoyeur_selon_qte_chargee(): void
    {
        $vehicule = $this->makeVehiculeAvecEquipe(tauxChauffeur: 18.42, tauxConvoyeur: 13.16);
        ['commande' => $commande, 'ligne' => $ligne] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::CHARGEMENT_EN_COURS,
        ], $vehicule);

        $commission = $commande->fresh()->commissions()->first();
        $avant = $commission->parts()->orderBy('role')->get()->keyBy('role');
        // Sur quantité demandée (2 × (2000-1500) = 1000 de marge) :
        $this->assertEquals(184.2, round((float) $avant['chauffeur']->montant_brut, 2));
        $this->assertEquals(131.6, round((float) $avant['convoyeur']->montant_brut, 2));

        // Valide le chargement avec seulement 1 pack chargé au lieu de 2.
        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande), [
                'lignes' => [[
                    'id' => $ligne->id,
                    'quantite_chargee' => 1,
                    'type_ecart' => 'manquant',
                ]],
            ])
            ->assertRedirect();

        $apres = $commission->fresh()->parts()->orderBy('role')->get()->keyBy('role');

        // Marge sur 1 pack = 500 → chauffeur 18.42% = 92.10, convoyeur 13.16% = 65.80
        $this->assertEquals(92.1, round((float) $apres['chauffeur']->montant_brut, 2));
        $this->assertEquals(65.8, round((float) $apres['convoyeur']->montant_brut, 2));
        $this->assertEquals('impaye', $apres['chauffeur']->statut->value);
        $this->assertEquals('impaye', $apres['convoyeur']->statut->value);
    }

    public function test_relancer_validation_chargement_ne_cree_pas_de_doublons(): void
    {
        $vehicule = $this->makeVehiculeAvecEquipe();
        ['commande' => $commande, 'ligne' => $ligne] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::CHARGEMENT_EN_COURS,
        ], $vehicule);

        $nbPartsAvant = $commande->fresh()->commissions()->first()->parts()->count();

        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande), [
                'lignes' => [['id' => $ligne->id, 'quantite_chargee' => 2, 'type_ecart' => 'conforme']],
            ])
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::LIVRAISON_EN_COURS, $commande->fresh()->statut);

        // Toute nouvelle tentative d'avancer depuis LIVRAISON_EN_COURS est refusée par la policy.
        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande), [
                'lignes' => [['id' => $ligne->id, 'quantite_chargee' => 2, 'type_ecart' => 'conforme']],
            ])
            ->assertStatus(403);

        $this->assertEquals(1, FactureVente::where('commande_vente_id', $commande->id)->count());
        $this->assertEquals(1, $commande->fresh()->commissions()->count());
        $this->assertEquals($nbPartsAvant, $commande->fresh()->commissions()->first()->parts()->count());
    }

    public function test_encaissement_interdit_tant_que_chargement_non_valide(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::CHARGEMENT_EN_COURS,
        ]);

        $facture = $commande->fresh()->facture;
        $this->assertNotNull($facture);
        $this->assertEquals('creee', $facture->statut_facture->value);

        $this->actingAs($this->user)
            ->post(route('encaissements.store', $facture), [
                'montant' => 1000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ])
            ->assertStatus(422);
    }

    public function test_paiement_commission_interdit_tant_que_chargement_non_valide(): void
    {
        $vehicule = $this->makeVehiculeAvecEquipe();
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::CHARGEMENT_EN_COURS,
        ], $vehicule);

        $chauffeurPart = $commande->fresh()->commissions()->first()->parts()->where('role', 'chauffeur')->first();
        $this->assertEquals('creee', $chauffeurPart->statut->value);

        Permission::firstOrCreate(['name' => 'comptabilite.payer', 'guard_name' => 'web']);
        $this->user->givePermissionTo('comptabilite.payer');

        $this->actingAs($this->user)
            ->post(route('comptabilite.commissions.vente.livreur.paiements', $chauffeurPart->livreur_id), [
                'montant' => 50,
                'mode_paiement' => 'especes',
            ])
            ->assertSessionHasErrors();
    }

    public function test_valider_chargement_sans_quantite_chargee_retourne_erreur(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::CHARGEMENT_EN_COURS,
        ]);

        // Pas de lignes envoyées → quantite_chargee reste null sur la ligne
        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande))
            ->assertSessionHasErrors('statut');
    }

    // ── Transition invalide depuis LIVRAISON_EN_COURS ─────────────────────────

    public function test_avancer_depuis_livraison_en_cours_retourne_403(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::LIVRAISON_EN_COURS,
        ]);

        // La policy avancerStatut retourne false pour LIVRAISON_EN_COURS
        $this->actingAs($this->user)
            ->post(route('ventes.statut.avancer', $commande))
            ->assertStatus(403);
    }

    // ── Auto-transition LIVRAISON_EN_COURS → LIVREE via encaissement ──────────

    public function test_encaissement_auto_passe_livraison_en_cours_a_livree(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::LIVRAISON_EN_COURS,
            'total_commande' => 4000,
        ]);

        // La facture (4000, statut IMPAYEE) a été créée et activée par le workflow réel.
        $facture = $commande->fresh()->facture;

        $this->actingAs($this->user)
            ->post(route('encaissements.store', $facture), [
                'montant' => 2000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ])
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::LIVREE, $commande->fresh()->statut);
    }

    public function test_encaissement_partiel_ne_cloture_pas(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::LIVRAISON_EN_COURS,
            'total_commande' => 4000,
        ]);

        $facture = $commande->fresh()->facture;

        // Encaissement partiel
        $this->actingAs($this->user)
            ->post(route('encaissements.store', $facture), [
                'montant' => 2000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ])
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::LIVREE, $commande->fresh()->statut);
    }

    // ── Auto-clôture LIVREE → CLOTUREE ────────────────────────────────────────

    public function test_encaissement_complet_depuis_livraison_cloture_la_commande(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::LIVRAISON_EN_COURS,
            'total_commande' => 4000,
        ]);

        $facture = $commande->fresh()->facture;

        $this->actingAs($this->user)
            ->post(route('encaissements.store', $facture), [
                'montant' => 4000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ])
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::CLOTUREE, $commande->fresh()->statut);
    }

    // ── Annulation via statut.annuler ──────────────────────────────────────────

    public function test_annuler_statut_depuis_brouillon(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne();

        $this->actingAs($this->user)
            ->post(route('ventes.statut.annuler', $commande), [
                'motif_annulation_code' => 'erreur_saisie',
            ])
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::ANNULEE, $commande->fresh()->statut);
    }

    public function test_annuler_statut_depuis_a_charger(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::A_CHARGER,
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.statut.annuler', $commande), [
                'motif_annulation_code' => 'doublon',
            ])
            ->assertRedirect();

        $this->assertEquals(StatutCommandeVente::ANNULEE, $commande->fresh()->statut);
    }

    public function test_annuler_statut_depuis_chargement_en_cours_retourne_403(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne([
            'statut' => StatutCommandeVente::CHARGEMENT_EN_COURS,
        ]);

        $this->actingAs($this->user)
            ->post(route('ventes.statut.annuler', $commande), [
                'motif_annulation_code' => 'erreur_saisie',
            ])
            ->assertStatus(403);
    }

    public function test_annuler_statut_sans_motif_echoue(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne();

        $this->actingAs($this->user)
            ->post(route('ventes.statut.annuler', $commande), [])
            ->assertSessionHasErrors('motif_annulation_code');
    }

    public function test_annuler_statut_avec_autre_exige_detail(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne();

        $this->actingAs($this->user)
            ->post(route('ventes.statut.annuler', $commande), [
                'motif_annulation_code' => 'autre',
            ])
            ->assertSessionHasErrors('motif_annulation_detail');
    }

    public function test_annuler_statut_stocke_motif_et_detail(): void
    {
        ['commande' => $commande] = $this->makeCommandeWithLigne();

        $this->actingAs($this->user)
            ->post(route('ventes.statut.annuler', $commande), [
                'motif_annulation_code' => 'autre',
                'motif_annulation_detail' => 'Stock manquant en entrepôt',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('commandes_ventes', [
            'id' => $commande->id,
            'motif_annulation' => 'autre : Stock manquant en entrepôt',
        ]);
    }
}
