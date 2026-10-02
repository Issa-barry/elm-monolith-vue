<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\CommissionAnomalieStatut;
use App\Enums\CommissionGenerationStatut;
use App\Enums\CommissionMode;
use App\Enums\CommissionMotifNonGeneration;
use App\Enums\CommissionRegleStatut;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionUniteCalcul;
use App\Enums\DeclencheurCommissionVente;
use App\Enums\StatutCommandeVente;
use App\Models\Categorie;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionGenerationAttempt;
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
use App\Models\Site;
use App\Models\User;
use App\Models\Vehicule;
use App\Notifications\CommissionManquanteNotification;
use App\Services\CommandeVenteService;
use App\Services\Commission\CommissionMonitoringService;
use App\Services\Commission\CommissionProcessusDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Commissions → Monitoring (30/09/2026) : les commissions attendues mais non générées, dérivées
 * de commission_generation_attempts et des enveloppes, relancées via le moteur officiel.
 */
class CommissionMonitoringTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private const URL = '/backoffice/comptabilite/commissions/monitoring';

    private Site $site;

    private CommissionProcessus $processus;

    private Categorie $bouteille;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-25 10:00:00');
        $this->withoutVite();

        $this->initOrgAndUser(['comptabilite.read', 'commissions.read', 'commissions.update']);
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);
        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::CHARGEMENT_VALIDE);

        $this->site = $this->user->sites()->firstOrFail();
        $this->processus = CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_VENTE);
        $this->bouteille = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Bouteille', 'statut' => 'actif']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function regle(string $cible, int $montant): CommissionRegle
    {
        return CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $this->processus->id,
            'libelle' => "{$cible} — Bouteille",
            'scope_type' => CommissionScopeType::CATEGORIE->value,
            'scope_id' => $this->bouteille->id,
            'cible_type' => $cible,
            'mode' => $cible === CommissionCibleType::CODE_EQUIPE_LIVRAISON ? CommissionMode::A_REPARTIR->value : CommissionMode::DIRECT->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => $montant,
            'effective_from' => '2026-08-01',
            'statut' => CommissionRegleStatut::ACTIVE->value,
        ]);
    }

    /**
     * Véhicule + équipe de 2 livreurs, partage Bouteille = $parts (null = aucun partage).
     *
     * @param  list<int>|null  $parts
     * @return array{vehicule: Vehicule, equipe: EquipeLivraison, livreurs: list<Livreur>}
     */
    private function vehicule(?array $parts, bool $avecProprietaire = true): array
    {
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $avecProprietaire ? Proprietaire::factory()->create(['organization_id' => $this->org->id])->id : null,
            'livraison_vente' => true,
            'livraison_logistique' => false,
            'is_active' => true,
        ]);
        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Équipe '.$vehicule->nom_vehicule,
            'is_active' => true,
        ]);

        $livreurs = [];
        foreach (['Chauffeur Alpha', 'Convoyeur Beta'] as $i => $nom) {
            $livreur = Livreur::factory()->create(['organization_id' => $this->org->id, 'is_active' => true, 'nom_complet' => $nom]);
            EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $livreur->id, 'role' => $i === 0 ? 'chauffeur' : 'convoyeur', 'ordre' => $i]);
            $livreurs[] = $livreur;

            if ($parts !== null) {
                EquipeLivraisonPartageCategorie::create([
                    'equipe_id' => $equipe->id,
                    'processus_id' => $this->processus->id,
                    'categorie_id' => $this->bouteille->id,
                    'livreur_id' => $livreur->id,
                    'part_pourcentage' => 0,
                    'montant_unitaire' => $parts[$i],
                    'effective_from' => '2026-08-01',
                ]);
            }
        }

        return ['vehicule' => $vehicule->fresh(), 'equipe' => $equipe, 'livreurs' => $livreurs];
    }

    private function produit(): Produit
    {
        return $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Produit '.uniqid(), 'categorie_id' => $this->bouteille->id],
            ['prix_vente' => 5000, 'prix_usine' => 3500],
        );
    }

    /** Commande chargée : la génération se déclenche au chargement (CHARGEMENT_VALIDE). */
    private function commandeChargee(Vehicule $vehicule, int $quantite = 10, ?Site $site = null): CommandeVente
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => ($site ?? $this->site)->id,
            'vehicule_id' => $vehicule->id,
            'client_id' => null,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 5000 * $quantite,
            'commission_eligible_snapshot' => true,
        ]);
        $commande->lignes()->create([
            'variante_id' => $this->produit()->variantePrincipale()->first()->id,
            'quantite_demandee' => $quantite,
            'prix_usine_snapshot' => 3500,
            'prix_vente_snapshot' => 5000,
            'total_ligne' => 5000 * $quantite,
        ]);

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande->fresh());
        $commande = $commande->fresh('lignes');
        CommandeVenteService::validerChargement($commande, $commande->lignes->map(fn ($l) => ['id' => $l->id, 'quantite_chargee' => $quantite])->all());

        return $commande->fresh();
    }

    /** Commande PARTIELLE comme en production : partage 500 + 450 = 950 pour un barème de 800. */
    private function commandePartageNonConforme(): array
    {
        $this->regle(CommissionCibleType::CODE_PROPRIETAIRE, 950);
        $this->regle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 800);
        $v = $this->vehicule([500, 450]);

        return [...$v, 'commande' => $this->commandeChargee($v['vehicule'])];
    }

    private function corrigerPartage(EquipeLivraison $equipe, array $parts): void
    {
        foreach (EquipeLivraisonPartageCategorie::where('equipe_id', $equipe->id)->orderBy('id')->get() as $i => $partage) {
            $partage->update(['montant_unitaire' => $parts[$i]]);
        }
    }

    /** @return Collection<int, array<string, mixed>> */
    private function anomalies(?string $orgId = null): Collection
    {
        return app(CommissionMonitoringService::class)->anomalies($orgId ?? $this->org->id);
    }

    private function nonAdmin(array $permissions, Site $site): User
    {
        $role = Role::firstOrCreate(['name' => 'employe', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole($role);
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $user->givePermissionTo($permissions);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    /** Tentative historique (avant le monitoring) : motif texte seul, sans `cibles`. */
    private function tentativeHistorique(CommandeVente $commande, string $message, string $orgId): CommissionGenerationAttempt
    {
        return CommissionGenerationAttempt::create([
            'organization_id' => $orgId,
            'source_type' => CommandeVente::class,
            'source_id' => $commande->id,
            'processus_id' => CommissionProcessusDefaults::resoudreOuCreer($orgId, CommissionProcessus::CODE_VENTE)->id,
            'statut' => CommissionGenerationStatut::ERREUR->value,
            'motif_erreur' => $message,
            'detail_erreur' => ['erreurs' => [$message]],
            'declenchee_par' => 'systeme',
        ]);
    }

    // ── Détection ─────────────────────────────────────────────────────────────

    public function test_commission_attendue_et_generee_ne_produit_aucune_anomalie(): void
    {
        $this->regle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 800);
        $v = $this->vehicule([500, 300]);
        $this->commandeChargee($v['vehicule']);

        $this->assertCount(0, $this->anomalies());
    }

    public function test_partage_non_conforme_cree_une_seule_anomalie_sur_la_cible_livreurs_avec_motif_exact(): void
    {
        ['commande' => $commande, 'livreurs' => $l] = $this->commandePartageNonConforme();

        $this->assertTrue(CommissionEnveloppe::where('source_id', $commande->id)->where('cible_type', CommissionCibleType::CODE_PROPRIETAIRE)->exists());

        $anomalies = $this->anomalies();
        $this->assertCount(1, $anomalies, 'Le propriétaire généré n\'est jamais signalé.');
        $a = $anomalies->first();

        $this->assertSame(CommissionCibleType::CODE_EQUIPE_LIVRAISON, $a['cible']);
        $this->assertSame(CommissionAnomalieStatut::NON_GENEREE->value, $a['statut']);
        $this->assertSame(CommissionMotifNonGeneration::PARTAGE_LIVREUR_NON_CONFORME->value, $a['motif_code']);
        $this->assertSame("Cible equipe_livraison : Dépassement de 150 GNF sur l'enveloppe Livreur de 800 GNF (attribué : 950 GNF).", $a['message']);
        $this->assertSame(8000.0, $a['montant_attendu']);
        $this->assertSame(['Bouteille'], $a['categories']);
        $this->assertSame($commande->reference, $a['reference']);
        $this->assertTrue($a['relancable']);
        $this->assertSame(1, $a['nb_tentatives_echouees']);

        $contexte = $a['erreurs'][0]['contexte'];
        $this->assertSame(800, $contexte['enveloppe_unitaire']);
        $this->assertSame(950, $contexte['attribue']);
        $this->assertSame(-150, $contexte['ecart']);
        $this->assertEqualsCanonicalizing(
            [['Chauffeur Alpha', 500], ['Convoyeur Beta', 450]],
            array_map(fn (array $p) => [$p['livreur_nom'], $p['montant_unitaire']], $contexte['parts']),
        );
        $this->assertSame($l[0]->id, collect($contexte['parts'])->firstWhere('livreur_nom', 'Chauffeur Alpha')['livreur_id']);
    }

    public function test_deux_cibles_en_echec_donnent_deux_anomalies_distinctes(): void
    {
        $this->regle(CommissionCibleType::CODE_PROPRIETAIRE, 950);
        $this->regle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 800);
        $v = $this->vehicule(null, avecProprietaire: false);
        $this->commandeChargee($v['vehicule']);

        $anomalies = $this->anomalies()->keyBy('cible');
        $this->assertCount(2, $anomalies);
        $this->assertSame(CommissionMotifNonGeneration::PROPRIETAIRE_MANQUANT->value, $anomalies[CommissionCibleType::CODE_PROPRIETAIRE]['motif_code']);
        $this->assertSame(9500.0, $anomalies[CommissionCibleType::CODE_PROPRIETAIRE]['montant_attendu']);
        $this->assertSame(CommissionMotifNonGeneration::PARTAGE_LIVREUR_MANQUANT->value, $anomalies[CommissionCibleType::CODE_EQUIPE_LIVRAISON]['motif_code']);
    }

    public function test_bareme_a_zero_absence_de_bareme_et_site_desactive_ne_sont_jamais_des_anomalies(): void
    {
        $this->regle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 0);
        $this->regle(CommissionCibleType::CODE_SITE, 100);
        $this->site->update(['commissions_active' => false]);
        $v = $this->vehicule(null);
        $commande = $this->commandeChargee($v['vehicule']);

        $this->assertSame(CommissionGenerationStatut::SUCCES, CommissionGenerationAttempt::where('source_id', $commande->id)->firstOrFail()->statut);
        $this->assertCount(0, $this->anomalies());
    }

    public function test_tentative_historique_sans_code_est_classee_depuis_son_message(): void
    {
        $v = $this->vehicule([500, 300]);
        $commande = CommandeVente::factory()->create(['organization_id' => $this->org->id, 'site_id' => $this->site->id, 'vehicule_id' => $v['vehicule']->id]);
        $this->tentativeHistorique($commande, "Cible equipe_livraison : Dépassement de 150 GNF sur l'enveloppe Livreur de 800 GNF (attribué : 950 GNF).", $this->org->id);

        $a = $this->anomalies()->sole();
        $this->assertSame(CommissionCibleType::CODE_EQUIPE_LIVRAISON, $a['cible']);
        $this->assertSame(CommissionMotifNonGeneration::PARTAGE_LIVREUR_NON_CONFORME->value, $a['motif_code']);
        $this->assertNull($a['montant_attendu']);
        $this->assertSame(CommissionAnomalieStatut::NON_GENEREE->value, $a['statut']);
    }

    public function test_commande_annulee_rend_l_anomalie_sans_objet_et_non_relancable(): void
    {
        ['commande' => $commande] = $this->commandePartageNonConforme();
        $commande->update(['statut' => StatutCommandeVente::ANNULEE]);

        $a = $this->anomalies()->sole();
        $this->assertSame(CommissionAnomalieStatut::SANS_OBJET->value, $a['statut']);
        $this->assertFalse($a['relancable']);
        $this->assertNotNull($a['raison_sans_objet']);
    }

    // ── Relance ───────────────────────────────────────────────────────────────

    public function test_relance_apres_correction_regularise_sans_doublon_et_double_relance_sans_effet(): void
    {
        ['commande' => $commande, 'equipe' => $equipe] = $this->commandePartageNonConforme();
        $id = $this->anomalies()->sole()['id'];
        $this->corrigerPartage($equipe, [500, 300]);

        Carbon::setTestNow('2026-09-26 09:00:00');
        $this->actingAs($this->user)->from(self::URL)
            ->post(self::URL.'/relancer', ['anomalies' => [$id]])
            ->assertRedirect(self::URL)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Commission régularisée avec succès.');

        $livreur = CommissionEnveloppe::where('source_id', $commande->id)->where('cible_type', CommissionCibleType::CODE_EQUIPE_LIVRAISON)->sole();
        $this->assertSame(8000.0, (float) $livreur->montant_total);
        $this->assertSame('2026-09-25', $livreur->earned_at->toDateString(), 'Date de gain d\'origine (R3).');

        $a = $this->anomalies()->sole();
        $this->assertSame(CommissionAnomalieStatut::REGULARISEE->value, $a['statut']);
        $this->assertFalse($a['relancable']);
        $this->assertSame(8000.0, $a['montant_regularise']);

        // Double clic / seconde relance : rien de plus, aucune nouvelle tentative.
        $tentatives = CommissionGenerationAttempt::where('source_id', $commande->id)->count();
        $this->actingAs($this->user)->from(self::URL)
            ->post(self::URL.'/relancer', ['anomalies' => [$id, $id]])
            ->assertSessionHasErrors('relance');
        $this->assertSame(2, CommissionEnveloppe::where('source_id', $commande->id)->count());
        $this->assertSame($tentatives, CommissionGenerationAttempt::where('source_id', $commande->id)->count());
    }

    public function test_relance_en_echec_conserve_l_anomalie_trace_la_tentative_et_devient_recurrente(): void
    {
        ['commande' => $commande] = $this->commandePartageNonConforme();
        $id = $this->anomalies()->sole()['id'];

        $this->actingAs($this->user)->from(self::URL)
            ->post(self::URL.'/relancer', ['anomalies' => [$id]])
            ->assertSessionHasErrors('relance');
        $this->assertStringContainsString('Dépassement de 150 GNF', session('errors')->first('relance'));

        $a = $this->anomalies()->sole();
        $this->assertSame(CommissionAnomalieStatut::NON_GENEREE->value, $a['statut']);
        $this->assertSame(2, $a['nb_tentatives_echouees']);
        $this->assertSame('Relance manuelle', $a['tentatives'][0]['declenchee_par']);
        $this->assertSame($this->user->name, $a['tentatives'][0]['auteur']);

        $this->actingAs($this->user)->from(self::URL)->post(self::URL.'/relancer', ['anomalies' => [$id]]);
        $a = $this->anomalies()->sole();
        $this->assertSame(CommissionAnomalieStatut::ECHEC_RECURRENT->value, $a['statut']);
        $this->assertSame(3, $a['nb_tentatives_echouees']);
        $this->assertSame(1, CommissionEnveloppe::where('source_id', $commande->id)->count());
    }

    public function test_relance_multiple_succes_partiel_chaque_operation_independante(): void
    {
        $this->regle(CommissionCibleType::CODE_EQUIPE_LIVRAISON, 800);
        $a = $this->vehicule([500, 450]);
        $b = $this->vehicule([600, 400]);
        $commandeA = $this->commandeChargee($a['vehicule']);
        $commandeB = $this->commandeChargee($b['vehicule']);
        $this->assertCount(2, $this->anomalies());

        $this->corrigerPartage($a['equipe'], [500, 300]);

        $this->actingAs($this->user)->from(self::URL)
            ->post(self::URL.'/relancer', ['anomalies' => $this->anomalies()->pluck('id')->all()])
            ->assertSessionHasErrors('relance');
        $this->assertStringStartsWith('1 commission(s) régularisée(s).', session('errors')->first('relance'));

        $parCommande = $this->anomalies()->keyBy('source_id');
        $this->assertSame(CommissionAnomalieStatut::REGULARISEE->value, $parCommande[$commandeA->id]['statut']);
        $this->assertSame(CommissionAnomalieStatut::NON_GENEREE->value, $parCommande[$commandeB->id]['statut']);
    }

    // ── Écran, filtres, permissions, isolation ────────────────────────────────

    public function test_ecran_liste_les_anomalies_ouvertes_avec_kpis_et_filtres(): void
    {
        ['commande' => $commande] = $this->commandePartageNonConforme();

        $this->actingAs($this->user)->get(self::URL)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Comptabilite/CommissionMonitoring/Index')
                ->where('kpis.non_generees', 1)
                ->where('kpis.montant_en_attente', 8000)
                ->where('filtres.statut', 'ouvertes')
                ->where('can_relancer', true)
                ->has('anomalies.data', 1)
                ->where('anomalies.data.0.reference', $commande->reference)
                ->where('anomalies.data.0.source_url', "/backoffice/ventes/{$commande->id}")
                ->has('anomalies.data.0.tentatives', 1));

        $this->actingAs($this->user)->get(self::URL.'?statut=regularisee')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('anomalies.data', 0)->where('kpis.non_generees', 1));
        $this->actingAs($this->user)->get(self::URL.'?motif=proprietaire_manquant')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('anomalies.data', 0));
        $this->actingAs($this->user)->get(self::URL.'?motif=partage_livreur_non_conforme&reference='.$commande->reference)
            ->assertInertia(fn (AssertableInertia $page) => $page->has('anomalies.data', 1));
        $this->actingAs($this->user)->get(self::URL.'?reference=INEXISTANT')
            ->assertInertia(fn (AssertableInertia $page) => $page->has('anomalies.data', 0));
    }

    public function test_lecture_exige_la_permission_commissions_et_la_relance_commissions_update(): void
    {
        ['commande' => $commande] = $this->commandePartageNonConforme();
        $id = $this->anomalies()->sole()['id'];

        $sansDroit = $this->nonAdmin(['ventes.read'], $this->site);
        $this->actingAs($sansDroit)->get(self::URL)->assertForbidden();

        $lecteur = $this->nonAdmin(['commissions.read'], $this->site);
        $this->actingAs($lecteur)->get(self::URL)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can_relancer', false)->has('anomalies.data', 1));
        $this->actingAs($lecteur)->post(self::URL.'/relancer', ['anomalies' => [$id]])->assertForbidden();

        $this->assertSame(1, CommissionGenerationAttempt::where('source_id', $commande->id)->count());
    }

    public function test_un_utilisateur_non_admin_ne_voit_que_les_anomalies_de_ses_agences(): void
    {
        $v = $this->vehicule([500, 300]);
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Autre agence', 'type' => 'depot', 'localisation' => 'Kindia']);
        $ici = CommandeVente::factory()->create(['organization_id' => $this->org->id, 'site_id' => $this->site->id, 'vehicule_id' => $v['vehicule']->id]);
        $ailleurs = CommandeVente::factory()->create(['organization_id' => $this->org->id, 'site_id' => $autreSite->id, 'vehicule_id' => $v['vehicule']->id]);
        $this->tentativeHistorique($ici, 'Cible proprietaire : véhicule sans propriétaire.', $this->org->id);
        $this->tentativeHistorique($ailleurs, 'Cible proprietaire : véhicule sans propriétaire.', $this->org->id);

        $lecteur = $this->nonAdmin(['commissions.read', 'commissions.update'], $this->site);
        $this->actingAs($lecteur)->get(self::URL)
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('anomalies.data', 1)
                ->where('anomalies.data.0.reference', $ici->reference));

        $idAilleurs = $this->anomalies()->firstWhere('source_id', $ailleurs->id)['id'];
        $this->actingAs($lecteur)->from(self::URL)->post(self::URL.'/relancer', ['anomalies' => [$idAilleurs]])
            ->assertSessionHasErrors('relance');
        $this->assertSame(1, CommissionGenerationAttempt::where('source_id', $ailleurs->id)->count());
    }

    public function test_isolation_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $autreCommande = CommandeVente::factory()->create(['organization_id' => $autreOrg->id]);
        $this->tentativeHistorique($autreCommande, 'Cible proprietaire : véhicule sans propriétaire.', $autreOrg->id);

        $this->assertCount(0, $this->anomalies());
        $this->assertCount(1, $this->anomalies($autreOrg->id));

        $idEtranger = $this->anomalies($autreOrg->id)->sole()['id'];
        $this->actingAs($this->user)->from(self::URL)->post(self::URL.'/relancer', ['anomalies' => [$idEtranger]])
            ->assertSessionHasErrors('relance');
        $this->assertSame(1, CommissionGenerationAttempt::where('source_id', $autreCommande->id)->count());
    }

    // ── Diagnostic, email ─────────────────────────────────────────────────────

    public function test_diagnostic_console_liste_les_commandes_historiques_en_lecture_seule(): void
    {
        ['commande' => $commande] = $this->commandePartageNonConforme();
        $enveloppes = CommissionEnveloppe::count();
        $tentatives = CommissionGenerationAttempt::count();

        $this->artisan('commissions:diagnostiquer-manquantes', ['--organization' => [$this->org->id]])
            ->expectsOutputToContain('1 ouverte(s)')
            ->expectsOutputToContain($commande->reference)
            ->assertFailed();

        $this->assertSame($enveloppes, CommissionEnveloppe::count());
        $this->assertSame($tentatives, CommissionGenerationAttempt::count());
    }

    public function test_email_de_commission_manquante_renvoie_vers_le_monitoring(): void
    {
        $mail = (new CommissionManquanteNotification('id', 'VTE-250926-007', 100000, "Cible equipe_livraison : Dépassement de 150 GNF sur l'enveloppe Livreur de 800 GNF (attribué : 950 GNF)."))
            ->toMail($this->user);

        $texte = implode("\n", [...$mail->introLines, ...$mail->outroLines]);
        $this->assertStringContainsString('/backoffice/comptabilite/commissions/monitoring?reference=VTE-250926-007', $texte);
        $this->assertStringContainsString('Commissions > Monitoring', $texte);
    }
}
