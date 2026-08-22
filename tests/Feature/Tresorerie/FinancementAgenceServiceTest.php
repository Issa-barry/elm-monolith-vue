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
use App\Models\Livreur;
use App\Models\Personne;
use App\Models\Site;
use App\Services\Tresorerie\FinancementAgenceService;
use App\Services\Tresorerie\MouvementFondsService;
use App\Services\Tresorerie\SoldeOuvertureTresorerieService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class FinancementAgenceServiceTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

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

    public function test_disponible_suffisant_ne_demande_aucun_financement(): void
    {
        $this->validerSoldeOuverture(500_000);
        $this->makeLivreurCommission(300_000, Carbon::parse('2026-08-05'));

        $rows = $this->service->calculerPourEcheance($this->org->id, 2026, 8, 'p1');
        $row = collect($rows)->firstWhere('site_id', $this->agence->id);

        $this->assertSame(0.0, $row['a_financer']);
        $this->assertSame('couvert', $row['statut']);
    }

    public function test_fonds_en_transit_non_receptionnes_ne_comptent_jamais_comme_disponible(): void
    {
        $this->validerSoldeOuverture(0);
        $this->makeLivreurCommission(300_000, Carbon::parse('2026-08-05'));

        // Un financement du siège est envoyé mais jamais reçu — ne doit pas
        // apparaître dans le disponible, seulement dans "fonds_en_transit".
        $siege = Site::create(['organization_id' => $this->org->id, 'nom' => 'Siège', 'type' => 'siege', 'localisation' => 'Conakry']);
        $compteCaisse = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();
        $caisseSiege = CompteTresorerie::create([
            'organization_id' => $this->org->id, 'site_id' => $siege->id,
            'compte_comptable_id' => $compteCaisse->id, 'type' => 'caisse', 'libelle' => 'Caisse Siège',
        ]);

        $mvtService = app(MouvementFondsService::class);
        $mouvement = $mvtService->creerBrouillon($this->org->id, [
            'site_origine_id' => $siege->id,
            'site_destination_id' => $this->agence->id,
            'compte_tresorerie_origine_id' => $caisseSiege->id,
            'compte_tresorerie_destination_id' => $this->caisseAgence->id,
            'montant' => 200_000,
        ], $this->user->id);
        $mvtService->envoyer($mouvement, $this->user->id);

        $rows = $this->service->calculerPourEcheance($this->org->id, 2026, 8, 'p1');
        $row = collect($rows)->firstWhere('site_id', $this->agence->id);

        $this->assertSame(0.0, $row['disponible']);
        $this->assertSame(200_000.0, $row['fonds_en_transit']);
        $this->assertSame(300_000.0, $row['a_financer']);
        $this->assertSame('fonds_en_transit', $row['statut']);
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
