<?php

namespace Tests\Feature\Tresorerie;

use App\Enums\CommissionActivationStatut;
use App\Enums\StatutContrat;
use App\Enums\TypeContrat;
use App\Features\ModuleFeature;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionLogistique;
use App\Models\CommissionLogistiquePart;
use App\Models\CommissionProcessus;
use App\Models\Contrat;
use App\Models\Employe;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\PaiementFiche;
use App\Models\PaiementFichePaiement;
use App\Models\Personne;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Services\Tresorerie\ObligationsAgenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class ObligationsAgenceServiceTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private ObligationsAgenceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['comptabilite.read']);
        Feature::for($this->org)->activate(ModuleFeature::COMPTABILITE);
        $this->service = app(ObligationsAgenceService::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function defaultSite(): Site
    {
        return $this->user->sites()->wherePivot('is_default', true)->first();
    }

    private function makeSite(string $nom): Site
    {
        return Site::create([
            'organization_id' => $this->org->id,
            'nom' => $nom,
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
    }

    private function makeLivreur(): Livreur
    {
        $personne = Personne::create([
            'organization_id' => $this->org->id,
            'telephone' => '+224'.fake()->unique()->numerify('#########'),
        ]);

        return Livreur::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'nom_complet' => 'Livreur '.uniqid(),
            'is_active' => true,
        ]);
    }

    private function makeProprietaire(): Proprietaire
    {
        return Proprietaire::factory()->create(['organization_id' => $this->org->id]);
    }

    private function makeCommande(Site $site, Carbon $date): CommandeVente
    {
        $client = Client::create([
            'organization_id' => $this->org->id,
            'nom' => 'Client', 'prenom' => 'Test',
            'is_active' => true, 'cashback_eligible' => false,
        ]);

        $commande = CommandeVente::create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'client_id' => $client->id,
            'reference' => 'CMD-'.uniqid(),
            'statut' => 'livree',
            'total_commande' => 1_000_000,
        ]);
        $commande->forceFill(['created_at' => $date])->saveQuietly();

        return $commande;
    }

    private ?CommissionProcessus $processusVente = null;

    /** Enveloppe de commission vente + une part livreur ou propriétaire, sur une commande rattachée à $site. */
    private function makeCommissionVentePart(Site $site, Carbon $date, string $typeBeneficiaire, string $beneficiaireId, float $montant, ?float $montantActuel = null, string $statut = 'impaye'): CommissionEnveloppePart
    {
        $commande = $this->makeCommande($site, $date);

        $this->processusVente ??= CommissionProcessus::create([
            'organization_id' => $this->org->id,
            'code' => CommissionProcessus::CODE_VENTE,
            'libelle' => 'Vente',
            'declencheur' => 'chargement_valide',
            'strategie_ancrage_site' => 'operation',
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);

        $enveloppe = CommissionEnveloppe::create([
            'organization_id' => $this->org->id,
            'source_type' => CommandeVente::class,
            'source_id' => $commande->id,
            'processus_id' => $this->processusVente->id,
            'cible_type' => $typeBeneficiaire === 'livreur' ? CommissionCibleType::CODE_EQUIPE_LIVRAISON : CommissionCibleType::CODE_PROPRIETAIRE,
            'cible_id' => (string) Str::ulid(),
            'montant_total' => $montant,
            'earned_at' => $date,
            'statut' => $statut,
        ]);
        // La période est résolue sur la date d'acquisition de l'enveloppe (naissance
        // au chargement réel), pas sur celle de la commande — cf. PeriodeCalculatorService.
        $enveloppe->forceFill(['created_at' => $date])->saveQuietly();

        return CommissionEnveloppePart::create([
            'enveloppe_id' => $enveloppe->id,
            'beneficiaire_type' => $typeBeneficiaire,
            'beneficiaire_id' => $beneficiaireId,
            'montant_brut' => $montant,
            'montant_net' => $montant,
            'montant_actuel' => $montantActuel,
            'montant_verse' => 0,
            'statut' => $statut,
        ]);
    }

    private function makeTransfert(Site $siteSource, Site $siteDestination): TransfertLogistique
    {
        return TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $siteSource->id,
            'site_destination_id' => $siteDestination->id,
            'created_by' => $this->user->id,
        ]);
    }

    private function makeCommissionLogistiquePart(TransfertLogistique $transfert, Carbon $earnedAt, string $typeBeneficiaire, string $beneficiaireId, float $montant): CommissionLogistiquePart
    {
        $commission = CommissionLogistique::create([
            'organization_id' => $this->org->id,
            'transfert_logistique_id' => $transfert->id,
            'vehicule_id' => null,
            'base_calcul' => 'forfait',
            'valeur_base' => $montant,
            'quantite_reference' => null,
            'montant_total' => $montant,
            'montant_verse' => 0,
            'statut' => 'impaye',
        ]);

        return CommissionLogistiquePart::create([
            'commission_logistique_id' => $commission->id,
            'type_beneficiaire' => $typeBeneficiaire,
            'livreur_id' => $typeBeneficiaire === 'livreur' ? $beneficiaireId : null,
            'proprietaire_id' => $typeBeneficiaire === 'proprietaire' ? $beneficiaireId : null,
            'beneficiaire_nom' => 'Bénéficiaire logistique',
            'taux_commission' => 100,
            'montant_brut' => $montant,
            'frais_supplementaires' => 0,
            'montant_net' => $montant,
            'montant_verse' => 0,
            'statut' => 'impaye',
            'earned_at' => $earnedAt->toDateString(),
        ]);
    }

    private function makeEmploye(Site $site, float $salaireBase): Employe
    {
        $personne = Personne::create([
            'organization_id' => $this->org->id,
            'nom' => 'Nom',
            'prenom' => 'Prenom',
            'telephone' => '+224'.fake()->unique()->numerify('#########'),
        ]);

        $employe = Employe::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'matricule' => (string) random_int(100000, 999999),
            'type_employe' => 'interne',
            'site_id' => $site->id,
            'statut' => 'actif',
        ]);

        Contrat::create([
            'organization_id' => $this->org->id,
            'employe_id' => $employe->id,
            'type_contrat' => TypeContrat::CDI->value,
            'date_debut' => '2020-01-01',
            'salaire_base' => $salaireBase,
            'statut_contrat' => StatutContrat::ACTIF->value,
        ]);

        return $employe;
    }

    private function rowFor(array $rows, Site $site): ?array
    {
        return collect($rows)->firstWhere('site_id', $site->id);
    }

    // ── 1. Séparation P1/P2 livreurs ─────────────────────────────────────────────

    public function test_separe_les_commissions_livreurs_p1_et_p2(): void
    {
        $site = $this->defaultSite();
        $livreur = $this->makeLivreur();

        $this->makeCommissionVentePart($site, Carbon::parse('2026-08-05'), 'livreur', $livreur->id, 100_000);
        $this->makeCommissionVentePart($site, Carbon::parse('2026-08-20'), 'livreur', $livreur->id, 250_000);

        $rows = $this->service->calculerPourMois($this->org->id, 2026, 8);
        $row = $this->rowFor($rows, $site);

        $this->assertNotNull($row);
        $this->assertSame(100_000.0, $row['livreurs_p1']);
        $this->assertSame(250_000.0, $row['livreurs_p2']);
    }

    // ── 2. Propriétaire mensuel (P1 + P2 cumulés) ────────────────────────────────

    public function test_cumule_les_commissions_proprietaires_des_deux_quinzaines(): void
    {
        $site = $this->defaultSite();
        $proprietaire = $this->makeProprietaire();

        $this->makeCommissionVentePart($site, Carbon::parse('2026-08-03'), 'proprietaire', $proprietaire->id, 80_000);
        $this->makeCommissionVentePart($site, Carbon::parse('2026-08-25'), 'proprietaire', $proprietaire->id, 120_000);

        $rows = $this->service->calculerPourMois($this->org->id, 2026, 8);
        $row = $this->rowFor($rows, $site);

        $this->assertSame(200_000.0, $row['proprietaires']);
    }

    // ── 3. Salaire mensuel ────────────────────────────────────────────────────────

    public function test_calcule_les_salaires_du_mois(): void
    {
        $site = $this->defaultSite();
        $this->makeEmploye($site, 1_500_000);

        $rows = $this->service->calculerPourMois($this->org->id, 2026, 8);
        $row = $this->rowFor($rows, $site);

        $this->assertSame(1_500_000.0, $row['salaires']);
    }

    // ── 9. Salarié sans PaieLigne préalable ──────────────────────────────────────

    public function test_genere_automatiquement_les_lignes_de_paie_du_mois(): void
    {
        $site = $this->defaultSite();
        $this->makeEmploye($site, 900_000);

        $this->assertDatabaseMissing('paie_lignes', ['salaire_base' => 900_000]);

        $this->service->calculerPourMois($this->org->id, 2026, 8);

        $this->assertDatabaseHas('paie_lignes', ['salaire_base' => 900_000]);
    }

    // ── 4. Regroupement par site + 11. total par agence ──────────────────────────

    public function test_regroupe_par_site(): void
    {
        $matoto = $this->defaultSite();
        $kouria = $this->makeSite('Kouria');

        $livreurMatoto = $this->makeLivreur();
        $livreurKouria = $this->makeLivreur();

        $this->makeCommissionVentePart($matoto, Carbon::parse('2026-08-05'), 'livreur', $livreurMatoto->id, 100_000);
        $this->makeCommissionVentePart($kouria, Carbon::parse('2026-08-05'), 'livreur', $livreurKouria->id, 300_000);

        $rows = $this->service->calculerPourMois($this->org->id, 2026, 8);

        $rowMatoto = $this->rowFor($rows, $matoto);
        $rowKouria = $this->rowFor($rows, $kouria);

        $this->assertSame(100_000.0, $rowMatoto['livreurs_p1']);
        $this->assertSame(300_000.0, $rowKouria['livreurs_p1']);
        $this->assertSame(100_000.0, $rowMatoto['total']);
        $this->assertSame(300_000.0, $rowKouria['total']);
    }

    // ── 12. Total général ─────────────────────────────────────────────────────────

    public function test_total_general_additionne_toutes_les_agences(): void
    {
        $matoto = $this->defaultSite();
        $kouria = $this->makeSite('Kouria');

        $this->makeCommissionVentePart($matoto, Carbon::parse('2026-08-05'), 'livreur', $this->makeLivreur()->id, 100_000);
        $this->makeCommissionVentePart($kouria, Carbon::parse('2026-08-05'), 'livreur', $this->makeLivreur()->id, 300_000);
        $this->makeEmploye($matoto, 500_000);

        $rows = $this->service->calculerPourMois($this->org->id, 2026, 8);
        $total = $this->service->totalGeneral($rows);

        $this->assertSame(400_000.0, $total['livreurs_p1']);
        $this->assertSame(500_000.0, $total['salaires']);
        $this->assertSame(900_000.0, $total['total']);
    }

    // ── 5. Isolation entre organisations ─────────────────────────────────────────

    public function test_isole_les_organisations(): void
    {
        $site = $this->defaultSite();
        $this->makeCommissionVentePart($site, Carbon::parse('2026-08-05'), 'livreur', $this->makeLivreur()->id, 100_000);

        $autreOrg = Organization::factory()->create();
        $autreSite = Site::create([
            'organization_id' => $autreOrg->id,
            'nom' => 'Site Autre Org', 'type' => 'depot', 'localisation' => 'Conakry',
        ]);
        $autrePersonne = Personne::create([
            'organization_id' => $autreOrg->id,
            'telephone' => '+224'.fake()->unique()->numerify('#########'),
        ]);
        $autreLivreur = Livreur::create([
            'organization_id' => $autreOrg->id,
            'personne_id' => $autrePersonne->id,
            'nom_complet' => 'Livreur Autre Org',
            'is_active' => true,
        ]);
        $autreClient = Client::create([
            'organization_id' => $autreOrg->id,
            'nom' => 'Client', 'prenom' => 'Autre', 'is_active' => true, 'cashback_eligible' => false,
        ]);
        $autreCommande = CommandeVente::create([
            'organization_id' => $autreOrg->id,
            'site_id' => $autreSite->id,
            'client_id' => $autreClient->id,
            'reference' => 'CMD-AUTREORG',
            'statut' => 'livree',
            'total_commande' => 1_000_000,
        ]);
        $autreCommande->forceFill(['created_at' => Carbon::parse('2026-08-05')])->saveQuietly();
        $autreProcessus = CommissionProcessus::create([
            'organization_id' => $autreOrg->id,
            'code' => CommissionProcessus::CODE_VENTE,
            'libelle' => 'Vente',
            'declencheur' => 'chargement_valide',
            'strategie_ancrage_site' => 'operation',
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);
        $autreEnveloppe = CommissionEnveloppe::create([
            'organization_id' => $autreOrg->id,
            'source_type' => CommandeVente::class,
            'source_id' => $autreCommande->id,
            'processus_id' => $autreProcessus->id,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'cible_id' => (string) Str::ulid(),
            'montant_total' => 999_000,
            'earned_at' => Carbon::parse('2026-08-05'),
            'statut' => 'impaye',
        ]);
        $autreEnveloppe->forceFill(['created_at' => Carbon::parse('2026-08-05')])->saveQuietly();
        CommissionEnveloppePart::create([
            'enveloppe_id' => $autreEnveloppe->id,
            'beneficiaire_type' => 'livreur',
            'beneficiaire_id' => $autreLivreur->id,
            'montant_brut' => 999_000,
            'montant_net' => 999_000,
            'montant_verse' => 0,
            'statut' => 'impaye',
        ]);

        $rows = $this->service->calculerPourMois($this->org->id, 2026, 8);
        $total = $this->service->totalGeneral($rows);

        // Seule la commission de $this->org (100 000) doit apparaître — jamais les
        // 999 000 GNF de l'autre organisation.
        $this->assertSame(100_000.0, $total['livreurs_p1']);
    }

    // ── 6. Ajustement de commission pris en compte ───────────────────────────────

    public function test_utilise_le_montant_ajuste_plutot_que_le_montant_theorique(): void
    {
        $site = $this->defaultSite();
        $livreur = $this->makeLivreur();

        // Théorique 200 000, ajusté à 50 000 (ex : absence partielle) — le besoin
        // de trésorerie doit refléter l'ajustement, jamais le montant théorique.
        $this->makeCommissionVentePart($site, Carbon::parse('2026-08-05'), 'livreur', $livreur->id, 200_000, montantActuel: 50_000);

        $rows = $this->service->calculerPourMois($this->org->id, 2026, 8);
        $row = $this->rowFor($rows, $site);

        $this->assertSame(50_000.0, $row['livreurs_p1']);
    }

    // ── Commission annulée exclue ─────────────────────────────────────────────────

    public function test_exclut_les_commissions_annulees(): void
    {
        $site = $this->defaultSite();
        $livreur = $this->makeLivreur();

        $this->makeCommissionVentePart($site, Carbon::parse('2026-08-05'), 'livreur', $livreur->id, 500_000, statut: 'annulee');

        $rows = $this->service->calculerPourMois($this->org->id, 2026, 8);
        $row = $this->rowFor($rows, $site);

        // formatResultat() liste toutes les agences (même à 0) : une commission
        // annulée ne doit contribuer à rien, mais l'agence reste listée.
        $this->assertNotNull($row);
        $this->assertSame(0.0, $row['livreurs_p1']);
        $this->assertSame(0.0, $row['total']);
    }

    // ── 7. Commission déjà payée exclue ───────────────────────────────────────────

    public function test_exclut_les_commissions_deja_entierement_payees(): void
    {
        $site = $this->defaultSite();
        $livreur = $this->makeLivreur();

        $this->makeCommissionVentePart($site, Carbon::parse('2026-08-05'), 'livreur', $livreur->id, 500_000, statut: 'paye');

        $rows = $this->service->calculerPourMois($this->org->id, 2026, 8);
        $row = $this->rowFor($rows, $site);

        $this->assertNotNull($row);
        $this->assertSame(0.0, $row['livreurs_p1']);
        $this->assertSame(0.0, $row['total']);
    }

    // ── 8. Paiement partiel : seul le reste est comptabilisé ─────────────────────

    public function test_paiement_partiel_ne_compte_que_le_reste(): void
    {
        $site = $this->defaultSite();
        $livreur = $this->makeLivreur();
        $this->makeCommissionVentePart($site, Carbon::parse('2026-08-05'), 'livreur', $livreur->id, 300_000);

        $this->service->calculerPourMois($this->org->id, 2026, 8);

        $fiche = PaiementFiche::where('organization_id', $this->org->id)
            ->where('beneficiaire_type', 'livreur')
            ->where('beneficiaire_id', $livreur->id)
            ->firstOrFail();

        PaiementFichePaiement::create([
            'fiche_id' => $fiche->id,
            'organization_id' => $this->org->id,
            'site_id' => $fiche->site_id,
            'montant' => 100_000,
            'mode_paiement' => 'especes',
            'date_paiement' => '2026-08-06',
        ]);

        $rows = $this->service->calculerPourMois($this->org->id, 2026, 8);
        $row = $this->rowFor($rows, $site);

        $this->assertSame(200_000.0, $row['livreurs_p1']);
    }

    // ── P1 déjà envoyé mi-mois : le reste tombe à 0 mais le "_du" garde la trace ──

    public function test_p1_deja_paye_le_reste_est_nul_mais_le_du_reste_positif(): void
    {
        $site = $this->defaultSite();
        $livreur = $this->makeLivreur();
        $this->makeCommissionVentePart($site, Carbon::parse('2026-08-05'), 'livreur', $livreur->id, 300_000);

        $this->service->calculerPourMois($this->org->id, 2026, 8);

        $fiche = PaiementFiche::where('organization_id', $this->org->id)
            ->where('beneficiaire_type', 'livreur')
            ->where('beneficiaire_id', $livreur->id)
            ->firstOrFail();

        // Paiement intégral de la fiche P1 — simule l'envoi réel du 15.
        PaiementFichePaiement::create([
            'fiche_id' => $fiche->id,
            'organization_id' => $this->org->id,
            'site_id' => $fiche->site_id,
            'montant' => 300_000,
            'mode_paiement' => 'especes',
            'date_paiement' => '2026-08-15',
        ]);

        $rows = $this->service->calculerPourMois($this->org->id, 2026, 8);
        $row = $this->rowFor($rows, $site);

        // Rien à envoyer une deuxième fois...
        $this->assertSame(0.0, $row['livreurs_p1']);
        // ...mais le montant théorique reste visible, pour distinguer "payé" de "rien dû".
        $this->assertSame(300_000.0, $row['livreurs_p1_du']);
    }

    // ── P1 anomalie : paiement partiel visible séparément du "_du" ──────────────

    public function test_p1_partiel_est_visible_comme_anomalie_via_le_du(): void
    {
        $site = $this->defaultSite();
        $livreur = $this->makeLivreur();
        $this->makeCommissionVentePart($site, Carbon::parse('2026-08-05'), 'livreur', $livreur->id, 300_000);

        $this->service->calculerPourMois($this->org->id, 2026, 8);

        $fiche = PaiementFiche::where('organization_id', $this->org->id)
            ->where('beneficiaire_type', 'livreur')
            ->where('beneficiaire_id', $livreur->id)
            ->firstOrFail();

        PaiementFichePaiement::create([
            'fiche_id' => $fiche->id,
            'organization_id' => $this->org->id,
            'site_id' => $fiche->site_id,
            'montant' => 120_000,
            'mode_paiement' => 'especes',
            'date_paiement' => '2026-08-15',
        ]);

        $rows = $this->service->calculerPourMois($this->org->id, 2026, 8);
        $row = $this->rowFor($rows, $site);

        // Ni "rien dû" (0), ni "tout dû" (= du) : le reste doit se situer strictement
        // entre les deux — c'est ce qui permet à l'écran de détecter une anomalie.
        $this->assertSame(180_000.0, $row['livreurs_p1']);
        $this->assertSame(300_000.0, $row['livreurs_p1_du']);
        $this->assertGreaterThan(0.0, $row['livreurs_p1']);
        $this->assertLessThan($row['livreurs_p1_du'], $row['livreurs_p1']);
    }

    // ── 10. Le site historique de la vente ne bouge pas avec le véhicule ────────

    public function test_site_historique_de_la_commande_ignore_le_site_courant_du_vehicule(): void
    {
        $siteVente = $this->defaultSite();
        $autreSite = $this->makeSite('Autre Site');
        $livreur = $this->makeLivreur();

        // La commission est rattachée à CommandeVente.site_id (siteVente) au
        // moment de la vente — même si le véhicule ayant servi à la livraison a,
        // depuis, été réaffecté à un autre site, cette part reste comptée sur
        // le site historique de la commande.
        $this->makeCommissionVentePart($siteVente, Carbon::parse('2026-08-05'), 'livreur', $livreur->id, 150_000);

        $rows = $this->service->calculerPourMois($this->org->id, 2026, 8);

        $this->assertSame(150_000.0, $this->rowFor($rows, $siteVente)['livreurs_p1']);
        $this->assertSame(0.0, $this->rowFor($rows, $autreSite)['total']);
    }

    // ── Commission logistique : rattachement au site source du transfert ────────

    public function test_commission_logistique_rattachee_au_site_source(): void
    {
        $siteSource = $this->defaultSite();
        $siteDestination = $this->makeSite('Destination');
        $livreur = $this->makeLivreur();

        $transfert = $this->makeTransfert($siteSource, $siteDestination);
        $this->makeCommissionLogistiquePart($transfert, Carbon::parse('2026-08-05'), 'livreur', $livreur->id, 75_000);

        $rows = $this->service->calculerPourMois($this->org->id, 2026, 8);

        $this->assertSame(75_000.0, $this->rowFor($rows, $siteSource)['livreurs_p1']);
        $this->assertSame(0.0, $this->rowFor($rows, $siteDestination)['total']);
    }

    // ── Détail par agence (drill-down) ────────────────────────────────────────────

    public function test_detail_agence_liste_les_beneficiaires(): void
    {
        $site = $this->defaultSite();
        $livreur = $this->makeLivreur();
        $this->makeCommissionVentePart($site, Carbon::parse('2026-08-05'), 'livreur', $livreur->id, 150_000);
        $this->makeEmploye($site, 700_000);

        $detail = $this->service->detailAgence($this->org->id, 2026, 8, $site->id);

        $this->assertCount(1, $detail['livreurs_p1']);
        $this->assertSame(150_000.0, $detail['livreurs_p1'][0]['montant_restant']);
        $this->assertCount(1, $detail['salaires']);
        $this->assertSame(700_000.0, $detail['salaires'][0]['reste_a_payer']);
    }
}
