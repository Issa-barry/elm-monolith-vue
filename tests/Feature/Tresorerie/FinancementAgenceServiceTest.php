<?php

namespace Tests\Feature\Tresorerie;

use App\Enums\CommissionActivationStatut;
use App\Features\ModuleFeature;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\Livreur;
use App\Models\Personne;
use App\Models\Site;
use App\Services\PeriodePaiementService;
use App\Services\Tresorerie\FinancementAgenceService;
use App\Services\Tresorerie\MouvementFondsService;
use App\Services\Tresorerie\SoldeOuvertureTresorerieService;
use App\Services\Tresorerie\SupportTresorerieValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class FinancementAgenceServiceTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, RefreshDatabase;

    private FinancementAgenceService $service;

    private Site $agence;

    private CompteTresorerie $caisseAgence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['tresorerie.read']);
        Feature::for($this->org)->activate(ModuleFeature::COMPTABILITE);
        $this->service = app(FinancementAgenceService::class);

        $this->agence = $this->user->sites()->first();

        $compteCaisse = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();
        $this->caisseAgence = CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->agence->id,
            'compte_comptable_id' => $compteCaisse->id,
            'type' => 'caisse',
            'libelle' => 'Caisse Agence',
        ]);
    }

    private function validerSoldeOuverture(float $montant, string $date = '2026-08-01'): void
    {
        $service = app(SoldeOuvertureTresorerieService::class);
        $solde = $service->enregistrer($this->org->id, $this->caisseAgence, [
            'date_situation' => $date,
            'montant' => $montant,
        ], $this->user->id);
        $service->valider($solde, $this->user->id);
    }

    private function makeLivreurCommission(float $montant, Carbon $date): void
    {
        $personne = Personne::create([
            'organization_id' => $this->org->id,
            'telephone' => '+224'.fake()->unique()->numerify('#########'),
        ]);
        $livreur = Livreur::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'nom_complet' => 'Livreur '.uniqid(),
            'is_active' => true,
        ]);

        $client = Client::create([
            'organization_id' => $this->org->id,
            'nom' => 'Client', 'prenom' => 'Test',
            'is_active' => true, 'cashback_eligible' => false,
        ]);
        $commande = CommandeVente::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->agence->id,
            'client_id' => $client->id,
            'reference' => 'CMD-'.uniqid(),
            'statut' => 'livree',
            'total_commande' => $montant,
        ]);
        $commande->forceFill(['created_at' => $date])->saveQuietly();

        $processus = CommissionProcessus::firstOrCreate(
            ['organization_id' => $this->org->id, 'code' => CommissionProcessus::CODE_VENTE],
            ['libelle' => 'Vente', 'declencheur' => 'chargement_valide', 'strategie_ancrage_site' => 'operation', 'statut' => CommissionActivationStatut::ACTIF->value],
        );

        $enveloppe = CommissionEnveloppe::create([
            'organization_id' => $this->org->id,
            'source_type' => CommandeVente::class,
            'source_id' => $commande->id,
            'processus_id' => $processus->id,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'cible_id' => (string) Str::ulid(),
            'montant_total' => $montant,
            'earned_at' => $date,
            'statut' => 'impaye',
        ]);
        $enveloppe->forceFill(['created_at' => $date])->saveQuietly();

        CommissionEnveloppePart::create([
            'enveloppe_id' => $enveloppe->id,
            'beneficiaire_type' => 'livreur',
            'beneficiaire_id' => $livreur->id,
            'montant_brut' => $montant,
            'montant_net' => $montant,
            'montant_verse' => 0,
            'statut' => 'impaye',
        ]);
    }

    public function test_site_sans_solde_ouverture_est_donnees_incompletes(): void
    {
        $this->makeLivreurCommission(300_000, Carbon::parse('2026-08-05'));

        $rows = $this->service->calculerPourEcheance($this->org->id, 2026, 8, 'p1');
        $row = collect($rows)->firstWhere('site_id', $this->agence->id);

        $this->assertSame('donnees_incompletes', $row['statut']);
        $this->assertNull($row['disponible']);
        $this->assertNull($row['a_financer']);
    }

    public function test_a_financer_est_le_reste_apres_disponible(): void
    {
        $this->validerSoldeOuverture(100_000);
        $this->makeLivreurCommission(300_000, Carbon::parse('2026-08-05'));

        $rows = $this->service->calculerPourEcheance($this->org->id, 2026, 8, 'p1');
        $row = collect($rows)->firstWhere('site_id', $this->agence->id);

        $this->assertSame(300_000.0, $row['total_a_regler']);
        $this->assertSame(100_000.0, $row['disponible']);
        $this->assertSame(200_000.0, $row['a_financer']);
        $this->assertSame('a_financer', $row['statut']);
    }

    /**
     * Décision du 2026-09-19 : une caisse dédiée à un agent démarre à 0, sans solde
     * d'ouverture, et n'entre pas dans le disponible. Elle ne doit donc jamais rendre
     * la position du site « non fiable » (positionFiable() ne regarde que l'agence).
     */
    public function test_une_caisse_dediee_sans_solde_ouverture_ne_rend_pas_la_position_non_fiable(): void
    {
        $this->validerSoldeOuverture(100_000);
        $this->creerCaisseActive($this->agence->id, $this->creerAgent($this->agence)->id);
        $this->makeLivreurCommission(300_000, Carbon::parse('2026-08-05'));

        $row = collect($this->service->calculerPourEcheance($this->org->id, 2026, 8, 'p1'))->firstWhere('site_id', $this->agence->id);

        $this->assertSame('a_financer', $row['statut']);
        $this->assertSame(100_000.0, $row['disponible']);
        $this->assertSame(200_000.0, $row['a_financer']);
    }

    /**
     * Un support d'agence en brouillon est inutilisable et hors position : il ne rend pas le site
     * « non fiable » avant sa validation. Une fois validé, il doit avoir un solde d'ouverture validé
     * comme n'importe quel support actif (règle historique de positionFiable()).
     */
    public function test_un_support_d_agence_en_brouillon_ne_rend_pas_la_position_non_fiable_jusqu_a_sa_validation(): void
    {
        $this->validerSoldeOuverture(100_000);
        $banque = CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->agence->id,
            'compte_comptable_id' => CompteComptable::where('organization_id', $this->org->id)->where('numero', '521000')->firstOrFail()->id,
            'type' => 'banque',
            'libelle' => 'Banque en préparation',
            'actif' => false,
        ]);
        $this->makeLivreurCommission(300_000, Carbon::parse('2026-08-05'));

        $ligne = fn () => collect($this->service->calculerPourEcheance($this->org->id, 2026, 8, 'p1'))->firstWhere('site_id', $this->agence->id);

        $this->assertSame('a_financer', $ligne()['statut'], 'brouillon : hors position');
        $this->assertSame(200_000.0, $ligne()['a_financer']);

        app(SupportTresorerieValidationService::class)->valider($banque, $this->user);

        $this->assertSame('donnees_incompletes', $ligne()['statut'], 'validé sans solde d\'ouverture : position incomplète');
    }

    /** L'argent encore détenu par un agent ne réduit pas le besoin de financement avant versement. */
    public function test_l_argent_d_une_caisse_dediee_ne_reduit_pas_le_a_financer(): void
    {
        $this->validerSoldeOuverture(100_000);
        $caisse = $this->creerCaisseActive($this->agence->id, $this->creerAgent($this->agence)->id);
        $this->alimenterCaisse($caisse, 250_000);
        $this->makeLivreurCommission(300_000, Carbon::parse('2026-08-05'));

        $row = collect($this->service->calculerPourEcheance($this->org->id, 2026, 8, 'p1'))->firstWhere('site_id', $this->agence->id);

        $this->assertSame(100_000.0, $row['disponible'], 'seule la caisse de l\'agence compte');
        $this->assertSame(200_000.0, $row['a_financer']);
        $this->assertSame('a_financer', $row['statut']);
    }

    /**
     * ADR 0016 (02/10/2026) : au-delà de ce qu'elle conserve, l'agence remet le surplus à la
     * trésorerie principale — le statut n'est plus « Couvert » mais « À remettre ».
     */
    public function test_disponible_suffisant_ne_demande_aucun_financement_et_l_excedent_est_a_remettre(): void
    {
        $this->validerSoldeOuverture(500_000);
        $this->makeLivreurCommission(300_000, Carbon::parse('2026-08-05'));

        $rows = $this->service->calculerPourEcheance($this->org->id, 2026, 8, 'p1');
        $row = collect($rows)->firstWhere('site_id', $this->agence->id);

        $this->assertSame(0.0, $row['a_financer']);
        $this->assertSame(300_000.0, $row['a_conserver']);
        $this->assertSame(200_000.0, $row['excedent_a_remettre']);
        $this->assertSame(0.0, $row['remise_obligatoire']);
        $this->assertSame(200_000.0, $row['total_a_remettre']);
        $this->assertSame('a_remettre', $row['statut']);
    }

    public function test_rien_a_conserver_ni_a_remettre_est_couvert(): void
    {
        $this->validerSoldeOuverture(0);

        $row = collect($this->service->calculerPourEcheance($this->org->id, 2026, 8, 'p1'))->firstWhere('site_id', $this->agence->id);

        $this->assertSame(0.0, $row['total_a_remettre']);
        $this->assertSame('couvert', $row['statut']);
    }

    /** Encaissement en espèces, par l'agence, d'une commande d'une autre agence (dette ADR 0012). */
    private function encaisserPourUneAutreAgence(float $montant, string $date = '2026-08-03'): void
    {
        $autre = Site::create(['organization_id' => $this->org->id, 'nom' => 'Cba', 'type' => 'usine', 'localisation' => 'Cba']);
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $autre->id,
            'statut' => 'livree',
            'total_commande' => $montant,
        ]);
        $facture = FactureVente::factory()->create([
            'organization_id' => $this->org->id,
            'commande_vente_id' => $commande->id,
            'site_id' => $autre->id,
            'montant_net' => $montant,
        ]);

        EncaissementVente::create([
            'facture_vente_id' => $facture->id,
            'site_encaissement_id' => $this->agence->id,
            'montant' => $montant,
            'date_encaissement' => $date,
            'mode_paiement' => 'especes',
        ]);
    }

    /**
     * Kankan (ADR 0016) : l'argent d'une autre agence est remis en totalité et ne paie jamais les
     * obligations de l'agence qui le détient — ses fonds propres seuls les couvrent, le reste est
     * financé par la trésorerie principale.
     */
    public function test_l_argent_d_une_autre_agence_est_remis_en_totalite_et_ne_couvre_pas_les_obligations(): void
    {
        $this->validerSoldeOuverture(200_000);
        $this->makeLivreurCommission(300_000, Carbon::parse('2026-08-05'));
        $this->encaisserPourUneAutreAgence(1_000_000);

        $row = collect($this->service->calculerPourEcheance($this->org->id, 2026, 8, 'p1'))->firstWhere('site_id', $this->agence->id);

        $this->assertSame(1_200_000.0, $row['disponible']);
        $this->assertSame(1_000_000.0, $row['fonds_autres_agences']);
        $this->assertSame(200_000.0, $row['disponible_propre']);
        $this->assertSame(100_000.0, $row['a_financer'], 'jamais « 1 200 000 − 300 000 » : les fonds de Cba ne paient pas l\'agence');
        $this->assertSame(1_000_000.0, $row['remise_obligatoire']);
        $this->assertSame(0.0, $row['excedent_a_remettre']);
        $this->assertSame(1_000_000.0, $row['total_a_remettre']);
        $this->assertSame('a_financer', $row['statut']);
    }

    /**
     * Espèces encore dans la caisse d'un agent : déjà hors du disponible (décision du 2026-09-19),
     * elles ne sont pas déduites une deuxième fois — mais restent intégralement à remettre.
     */
    public function test_les_especes_dues_encore_chez_un_agent_ne_sont_pas_deduites_deux_fois(): void
    {
        // L'alimentation de la caisse est datée du jour : le test se place dans la période calculée.
        $this->travelTo(Carbon::parse('2026-08-10'));
        $this->validerSoldeOuverture(500_000);
        $caisse = $this->creerCaisseActive($this->agence->id, $this->creerAgent($this->agence)->id);
        $this->alimenterCaisse($caisse, 300_000);
        $this->encaisserPourUneAutreAgence(200_000);

        $row = collect($this->service->calculerPourEcheance($this->org->id, 2026, 8, 'p1'))->firstWhere('site_id', $this->agence->id);

        $this->assertSame(0.0, $row['fonds_autres_agences']);
        $this->assertSame(200_000.0, $row['remise_obligatoire']);
    }

    /** Les obligations des mois précédents encore impayées sont conservées avant toute remise. */
    public function test_les_obligations_impayees_des_mois_precedents_sont_conservees(): void
    {
        $this->validerSoldeOuverture(1_000_000, '2026-07-01');
        $this->makeLivreurCommission(150_000, Carbon::parse('2026-07-05'));
        // Le mois de juillet a été calculé (période et fiches existent), sa fiche reste impayée.
        $this->service->calculerPourEcheance($this->org->id, 2026, 7, 'p1');
        $this->makeLivreurCommission(300_000, Carbon::parse('2026-08-05'));

        $row = collect($this->service->calculerPourEcheance($this->org->id, 2026, 8, 'p1'))->firstWhere('site_id', $this->agence->id);

        $this->assertSame(300_000.0, $row['total_a_regler']);
        $this->assertSame(150_000.0, $row['arrieres']);
        $this->assertSame(450_000.0, $row['a_conserver']);
        $this->assertSame(550_000.0, $row['excedent_a_remettre']);
    }

    /** Vue « fin de mois » : la 1re quinzaine du mois est échue, son restant impayé est conservé. */
    public function test_en_fin_de_mois_la_premiere_quinzaine_impayee_est_conservee(): void
    {
        $this->validerSoldeOuverture(1_000_000);
        $this->makeLivreurCommission(300_000, Carbon::parse('2026-08-05'));

        $row = collect($this->service->calculerPourEcheance($this->org->id, 2026, 8, 'p2'))->firstWhere('site_id', $this->agence->id);

        $this->assertSame(0.0, $row['total_a_regler'], 'aucune obligation de fin de mois');
        $this->assertSame(300_000.0, $row['arrieres']);
        $this->assertSame(700_000.0, $row['excedent_a_remettre']);
    }

    /** La trésorerie principale ne se remet rien et ne se finance pas elle-même (ADR 0016, 0017). */
    public function test_la_tresorerie_principale_n_a_ni_remise_ni_financement(): void
    {
        $this->agence->update(['is_central_tresorerie' => true]);
        $this->validerSoldeOuverture(500_000);
        $this->makeLivreurCommission(300_000, Carbon::parse('2026-08-05'));

        $row = collect($this->service->calculerPourEcheance($this->org->id, 2026, 8, 'p1'))->firstWhere('site_id', $this->agence->id);

        $this->assertTrue($row['est_tresorerie_principale']);
        $this->assertSame('tresorerie_principale', $row['statut']);
        $this->assertSame(500_000.0, $row['disponible']);
        $this->assertNull($row['a_financer']);
        $this->assertNull($row['total_a_remettre']);
    }

    private function envoyerFinancementVersAgence(float $montant, ?string $echeanceDebut = null, ?string $echeanceFin = null): void
    {
        $siege = Site::firstOrCreate(
            ['organization_id' => $this->org->id, 'is_central_tresorerie' => true],
            ['nom' => 'Siège', 'type' => 'agence', 'localisation' => 'Conakry'],
        );
        $compteCaisse = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();
        $caisseSiege = CompteTresorerie::firstOrCreate(
            ['organization_id' => $this->org->id, 'site_id' => $siege->id, 'compte_comptable_id' => $compteCaisse->id],
            ['type' => 'caisse', 'libelle' => 'Caisse Siège'],
        );
        // garantirSoldeSuffisant() (règle du 22/09/2026) exige un solde disponible avant l'envoi ;
        // montant confortablement au-dessus de tout $montant utilisé dans ce fichier.
        $this->alimenterCaisse($caisseSiege, 10_000_000);

        $mvtService = app(MouvementFondsService::class);
        $mouvement = $mvtService->creerBrouillon($this->org->id, [
            'site_origine_id' => $siege->id,
            'site_destination_id' => $this->agence->id,
            'compte_tresorerie_origine_id' => $caisseSiege->id,
            'compte_tresorerie_destination_id' => $this->caisseAgence->id,
            'montant' => $montant,
            'echeance_debut' => $echeanceDebut,
            'echeance_fin' => $echeanceFin,
        ], $this->user->id);
        $mvtService->envoyer($mouvement, $this->user->id);
    }

    public function test_fonds_en_transit_non_receptionnes_ne_comptent_jamais_comme_disponible(): void
    {
        $this->validerSoldeOuverture(0);
        $this->makeLivreurCommission(300_000, Carbon::parse('2026-08-05'));

        // Un financement du siège est envoyé mais jamais reçu — ne doit pas
        // apparaître dans le disponible, seulement dans "fonds_en_transit".
        // Aucune échéance déclarée sur ce mouvement : compté prudemment comme
        // pouvant couvrir le besoin courant (cf. FinancementAgenceService).
        $this->envoyerFinancementVersAgence(200_000);

        $rows = $this->service->calculerPourEcheance($this->org->id, 2026, 8, 'p1');
        $row = collect($rows)->firstWhere('site_id', $this->agence->id);

        $this->assertSame(0.0, $row['disponible']);
        $this->assertSame(200_000.0, $row['fonds_en_transit']);
        // Le transit est déduit du besoin — sinon le siège renverrait 300 000 en plus
        // des 200 000 déjà en route (double financement), cf. revue Codex du 2026-08-22.
        $this->assertSame(100_000.0, $row['a_financer']);
        $this->assertSame('fonds_en_transit', $row['statut']);
    }

    public function test_transit_tague_pour_une_autre_echeance_ne_reduit_pas_le_besoin_courant(): void
    {
        $this->validerSoldeOuverture(0);
        $this->makeLivreurCommission(300_000, Carbon::parse('2026-08-05'));

        // Financement explicitement destiné à l'échéance P2 (16-31 août) — ne doit
        // rien changer au besoin P1 calculé ici.
        [$debutP2, $finP2] = PeriodePaiementService::dateRangeFor(2026, 8, PeriodePaiementService::P2);
        $this->envoyerFinancementVersAgence(150_000, $debutP2->toDateString(), $finP2->toDateString());

        $rows = $this->service->calculerPourEcheance($this->org->id, 2026, 8, 'p1');
        $row = collect($rows)->firstWhere('site_id', $this->agence->id);

        $this->assertSame(0.0, $row['fonds_en_transit']);
        $this->assertSame(300_000.0, $row['a_financer']);
    }

    public function test_echeance_p1_ignore_les_colonnes_fin_de_mois(): void
    {
        $this->validerSoldeOuverture(0);
        $this->makeLivreurCommission(150_000, Carbon::parse('2026-08-05')); // P1

        $rowsP1 = $this->service->calculerPourEcheance($this->org->id, 2026, 8, 'p1');
        $rowsP2 = $this->service->calculerPourEcheance($this->org->id, 2026, 8, 'p2');

        $rowP1 = collect($rowsP1)->firstWhere('site_id', $this->agence->id);
        $rowP2 = collect($rowsP2)->firstWhere('site_id', $this->agence->id);

        $this->assertSame(150_000.0, $rowP1['total_a_regler']);
        $this->assertSame(0.0, $rowP2['total_a_regler']);
    }
}
