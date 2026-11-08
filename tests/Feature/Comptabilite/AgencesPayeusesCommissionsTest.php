<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\CommissionActivationStatut;
use App\Enums\StatutFichePaiement;
use App\Enums\TypePeriodePaiement;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionPolitiquePaiement;
use App\Models\CommissionPolitiquePaiementHistorique;
use App\Models\CommissionProcessus;
use App\Models\EcritureComptable;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\PaiementFiche;
use App\Models\PaiementFichePaiement;
use App\Models\PaiementFicheReaffectation;
use App\Models\PieceComptable;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\Commission\AgencePayeuseCommissionService;
use App\Services\Commission\FicheSiteResponsableService;
use App\Services\Comptabilite\FicheComptabilisationService;
use App\Services\Comptabilite\PlanComptableBootstrapService;
use App\Services\PeriodeCalculatorService;
use App\Services\PeriodePaiementService;
use App\Services\Tresorerie\ObligationsAgenceService;
use App\Support\Permissions\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Agences payeuses des commissions (ADR 0061, décision du 07/11/2026) : par défaut, l'agence
 * centralisée (trésorerie principale, Matoto) paie les propriétaires, consultants, parrains et
 * sites ; chaque livreur reste payé par l'agence de son véhicule. La politique se change dans
 * Paramètres → Agences payeuses ; les fiches non payées suivent, les paiements faits restent.
 */
class AgencesPayeusesCommissionsTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private const URL_PARAMETRES = '/settings/commissions/agences-payeuses';

    private Site $matoto;

    private Site $cba;

    private Site $sonfonia;

    private ?CommissionProcessus $processus = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['comptabilite.read', 'comptabilite.payer', 'commissions.configurer_agences_payeuses']);
        $this->matoto = $this->user->sites()->wherePivot('is_default', true)->first();
        $this->matoto->update(['nom' => 'Matoto', 'is_central_tresorerie' => true]);
        $this->cba = $this->site('Cba');
        $this->sonfonia = $this->site('Sonfonia');
    }

    private function site(string $nom, ?Organization $org = null): Site
    {
        return Site::create([
            'organization_id' => ($org ?? $this->org)->id,
            'nom' => $nom,
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
    }

    private function makeVehicule(Site $site): Vehicule
    {
        return Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'proprietaire_id' => Proprietaire::factory()->create(['organization_id' => $this->org->id])->id,
        ]);
    }

    private function makePart(Vehicule $vehicule, Site $siteVente, string $typeBeneficiaire, string $beneficiaireId, float $montant): CommissionEnveloppePart
    {
        $client = Client::create([
            'organization_id' => $this->org->id,
            'nom' => 'Client', 'prenom' => 'Test',
            'is_active' => true, 'cashback_eligible' => false,
        ]);
        $commande = CommandeVente::create([
            'organization_id' => $this->org->id,
            'site_id' => $siteVente->id,
            'vehicule_id' => $vehicule->id,
            'client_id' => $client->id,
            'reference' => 'CMD-'.uniqid(),
            'statut' => 'livree',
            'total_commande' => 1_000_000,
        ]);

        $this->processus ??= CommissionProcessus::create([
            'organization_id' => $this->org->id,
            'code' => CommissionProcessus::CODE_VENTE,
            'libelle' => 'Vente',
            'declencheur' => 'chargement_valide',
            'strategie_ancrage_site' => 'operation',
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);

        $cible = match ($typeBeneficiaire) {
            'livreur' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'site' => CommissionCibleType::CODE_SITE,
            default => CommissionCibleType::CODE_PROPRIETAIRE,
        };

        $enveloppe = CommissionEnveloppe::create([
            'organization_id' => $this->org->id,
            'source_type' => CommandeVente::class,
            'source_id' => $commande->id,
            'processus_id' => $this->processus->id,
            'cible_type' => $cible,
            'cible_id' => $typeBeneficiaire === 'site' ? $beneficiaireId : (string) Str::ulid(),
            'montant_total' => $montant,
            'earned_at' => '2026-08-05',
            'statut' => 'impaye',
        ]);

        return CommissionEnveloppePart::create([
            'enveloppe_id' => $enveloppe->id,
            'beneficiaire_type' => $typeBeneficiaire,
            'beneficiaire_id' => $beneficiaireId,
            'montant_brut' => $montant,
            'montant_net' => $montant,
            'montant_verse' => 0,
            'statut' => 'impaye',
        ]);
    }

    private function calculerAout(): array
    {
        // Les obligations ne lisent que les périodes Site existantes : on calcule la sienne d'abord.
        $periodeSite = app(PeriodePaiementService::class)->getOrCreatePeriod($this->org->id, TypePeriodePaiement::SITE, Carbon::parse('2026-08-05'));
        app(PeriodeCalculatorService::class)->calculer($periodeSite);

        return app(ObligationsAgenceService::class)->calculerPourMois($this->org->id, 2026, 8);
    }

    private function fiche(string $type, string $beneficiaireId): PaiementFiche
    {
        return PaiementFiche::where('organization_id', $this->org->id)
            ->where('beneficiaire_type', $type)
            ->where('beneficiaire_id', $beneficiaireId)
            ->firstOrFail();
    }

    private function besoin(array $rows, Site $site, string $colonne): float
    {
        return (float) (collect($rows)->firstWhere('site_id', $site->id)[$colonne] ?? 0.0);
    }

    /** @param  array<string, array{mode: string, site_id?: ?string}>  $regles */
    private function politique(array $regles = [], ?string $agenceCentraliseeId = null): array
    {
        $completes = [];
        foreach (AgencePayeuseCommissionService::TYPES as $type) {
            $completes[$type] = $regles[$type] ?? ['mode' => AgencePayeuseCommissionService::DEFAUTS[$type], 'site_id' => null];
        }

        return ['agence_centralisee_id' => $agenceCentraliseeId, 'regles' => $completes];
    }

    private function manager(Site $site, array $permissions = ['comptabilite.read', 'comptabilite.payer']): User
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole(Role::firstOrCreate(['name' => 'employe', 'guard_name' => 'web']));
        $user->givePermissionTo($permissions);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    public function test_par_defaut_la_fiche_proprietaire_est_payee_par_lagence_centralisee_et_rattachee_au_vehicule(): void
    {
        $vehicule = $this->makeVehicule($this->cba);
        $this->makePart($vehicule, $this->cba, 'proprietaire', $vehicule->proprietaire_id, 250_000);

        $rows = $this->calculerAout();

        $fiche = $this->fiche('proprietaire', $vehicule->proprietaire_id);
        $this->assertSame($this->matoto->id, $fiche->site_id);
        $this->assertSame($this->cba->id, $fiche->site_rattachement_id);
        $this->assertSame(250_000.0, $this->besoin($rows, $this->matoto, 'proprietaires'));
        $this->assertSame(0.0, $this->besoin($rows, $this->cba, 'proprietaires'));
    }

    public function test_par_defaut_le_livreur_reste_paye_par_lagence_de_son_vehicule(): void
    {
        $vehicule = $this->makeVehicule($this->cba);
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $this->makePart($vehicule, $this->matoto, 'livreur', $livreur->id, 120_000);

        $this->calculerAout();

        $fiche = $this->fiche('livreur', $livreur->id);
        $this->assertSame($this->cba->id, $fiche->site_id);
        $this->assertSame($this->cba->id, $fiche->site_rattachement_id);
    }

    public function test_par_defaut_la_commission_site_est_payee_par_lagence_centralisee(): void
    {
        $vehicule = $this->makeVehicule($this->cba);
        $this->makePart($vehicule, $this->cba, 'site', $this->cba->id, 80_000);

        $this->calculerAout();

        $fiche = $this->fiche('site', $this->cba->id);
        $this->assertSame($this->matoto->id, $fiche->site_id);
        $this->assertSame($this->cba->id, $fiche->site_rattachement_id);
    }

    public function test_regles_par_defaut_de_chaque_categorie(): void
    {
        $service = app(AgencePayeuseCommissionService::class);

        foreach (['proprietaire', 'prestataire', 'parrain', 'site'] as $type) {
            $this->assertSame($this->matoto->id, $service->sitePayeur($this->org->id, $type, $this->cba->id), $type);
        }
        $this->assertSame($this->cba->id, $service->sitePayeur($this->org->id, 'livreur', $this->cba->id));
        $this->assertSame($this->matoto->id, $service->sitePayeur($this->org->id, 'prestataire', null));
        // Un salarié n'est pas concerné par la politique : son agence reste la sienne.
        $this->assertSame($this->cba->id, $service->sitePayeur($this->org->id, 'salarie', $this->cba->id));
    }

    public function test_une_categorie_peut_etre_payee_par_une_autre_agence(): void
    {
        $vehicule = $this->makeVehicule($this->cba);
        $this->makePart($vehicule, $this->cba, 'proprietaire', $vehicule->proprietaire_id, 250_000);
        $this->calculerAout();

        $this->actingAs($this->user)
            ->put(self::URL_PARAMETRES, $this->politique([
                'proprietaire' => ['mode' => 'agence', 'site_id' => $this->sonfonia->id],
            ]))
            ->assertRedirect()
            ->assertSessionHas('success', 'Agences payeuses enregistrées. 1 fiche non payée change d\'agence qui paie.');

        $fiche = $this->fiche('proprietaire', $vehicule->proprietaire_id);
        $this->assertSame($this->sonfonia->id, $fiche->site_id);
        $this->assertSame($this->cba->id, $fiche->site_rattachement_id);

        $historique = CommissionPolitiquePaiementHistorique::where('organization_id', $this->org->id)->sole();
        $this->assertSame($this->user->id, $historique->user_id);
        $this->assertSame(1, $historique->fiches_reaffectees);
        $this->assertSame('centralisee', $historique->avant['regles']['proprietaire']['mode']);
        $this->assertSame(['mode' => 'agence', 'site_id' => $this->sonfonia->id], $historique->apres['regles']['proprietaire']);
    }

    public function test_changer_lagence_centralisee_deplace_toutes_les_categories_centralisees(): void
    {
        $vehicule = $this->makeVehicule($this->cba);
        $this->makePart($vehicule, $this->cba, 'proprietaire', $vehicule->proprietaire_id, 250_000);
        $this->makePart($vehicule, $this->cba, 'site', $this->cba->id, 80_000);
        $this->calculerAout();

        $this->actingAs($this->user)
            ->put(self::URL_PARAMETRES, $this->politique([], $this->sonfonia->id))
            ->assertSessionHasNoErrors();

        $this->assertSame($this->sonfonia->id, $this->fiche('proprietaire', $vehicule->proprietaire_id)->site_id);
        $this->assertSame($this->sonfonia->id, $this->fiche('site', $this->cba->id)->site_id);
    }

    public function test_transferer_la_tresorerie_principale_deplace_les_fiches_non_payees(): void
    {
        $vehicule = $this->makeVehicule($this->cba);
        $this->makePart($vehicule, $this->cba, 'proprietaire', $vehicule->proprietaire_id, 250_000);
        $this->calculerAout();
        $this->assertSame($this->matoto->id, $this->fiche('proprietaire', $vehicule->proprietaire_id)->site_id);

        $this->sonfonia->update(['is_central_tresorerie' => true]);

        $this->assertSame($this->sonfonia->id, $this->fiche('proprietaire', $vehicule->proprietaire_id)->site_id);
    }

    public function test_les_fiches_payees_ne_bougent_pas_et_les_paiements_gardent_leur_agence(): void
    {
        CommissionPolitiquePaiement::create([
            'organization_id' => $this->org->id,
            'regles' => $this->politique(['proprietaire' => ['mode' => 'rattachement']])['regles'],
        ]);
        $partiel = $this->makeVehicule($this->cba);
        $paye = $this->makeVehicule($this->cba);
        $this->makePart($partiel, $this->cba, 'proprietaire', $partiel->proprietaire_id, 300_000);
        $this->makePart($paye, $this->cba, 'proprietaire', $paye->proprietaire_id, 200_000);
        $this->calculerAout();

        $fichePartielle = $this->fiche('proprietaire', $partiel->proprietaire_id);
        $fichePayee = $this->fiche('proprietaire', $paye->proprietaire_id);
        $this->assertSame($this->cba->id, $fichePartielle->site_id);

        $paiement = PaiementFichePaiement::create([
            'fiche_id' => $fichePartielle->id, 'organization_id' => $this->org->id, 'site_id' => $this->cba->id,
            'montant' => 100_000, 'mode_paiement' => 'especes', 'date_paiement' => '2026-08-20',
        ]);
        $fichePartielle->recalculStatut();
        PaiementFichePaiement::create([
            'fiche_id' => $fichePayee->id, 'organization_id' => $this->org->id, 'site_id' => $this->cba->id,
            'montant' => 200_000, 'mode_paiement' => 'especes', 'date_paiement' => '2026-08-20',
        ]);
        $fichePayee->recalculStatut();

        $this->actingAs($this->user)
            ->put(self::URL_PARAMETRES, $this->politique())
            ->assertSessionHasNoErrors();

        $this->assertSame($this->matoto->id, $fichePartielle->fresh()->site_id);
        $this->assertSame(StatutFichePaiement::PARTIELLEMENT_PAYE, $fichePartielle->fresh()->statut);
        $this->assertSame($this->cba->id, $paiement->fresh()->site_id);
        $this->assertSame($this->cba->id, $fichePayee->fresh()->site_id);
    }

    /** Solde (crédit − débit) d'un compte sur un site, toutes pièces confondues. */
    private function soldeCrediteur(string $numeroCompte, ?string $siteId): float
    {
        return (float) EcritureComptable::query()
            ->join('compta_comptes', 'compta_comptes.id', '=', 'compta_ecritures.compte_comptable_id')
            ->where('compta_comptes.organization_id', $this->org->id)
            ->where('compta_comptes.numero', $numeroCompte)
            ->where('compta_ecritures.site_id', $siteId)
            ->selectRaw('COALESCE(SUM(compta_ecritures.credit), 0) - COALESCE(SUM(compta_ecritures.debit), 0) as solde')
            ->value('solde');
    }

    public function test_fiche_site_deja_comptabilisee_dette_et_charge_passent_a_la_nouvelle_agence(): void
    {
        app(PlanComptableBootstrapService::class)->bootstrap($this->org->id);
        CommissionPolitiquePaiement::create([
            'organization_id' => $this->org->id,
            'regles' => $this->politique(['site' => ['mode' => 'rattachement']])['regles'],
        ]);
        $vehicule = $this->makeVehicule($this->cba);
        $this->makePart($vehicule, $this->cba, 'site', $this->cba->id, 80_000);
        $this->calculerAout();

        $fiche = $this->fiche('site', $this->cba->id);
        app(FicheComptabilisationService::class)->comptabiliserFicheValidee($fiche);
        $this->assertSame(80_000.0, $this->soldeCrediteur('467170', $this->cba->id));

        $this->actingAs($this->user)
            ->put(self::URL_PARAMETRES, $this->politique())
            ->assertSessionHasNoErrors();

        $this->assertSame(0.0, $this->soldeCrediteur('467170', $this->cba->id));
        $this->assertSame(80_000.0, $this->soldeCrediteur('467170', $this->matoto->id));
        $this->assertSame(0.0, $this->soldeCrediteur('622300', $this->cba->id));
        $this->assertSame(-80_000.0, $this->soldeCrediteur('622300', $this->matoto->id));
    }

    /** Solde (crédit − débit) d'un compte, toutes agences confondues. */
    private function soldeCrediteurTotal(string $numeroCompte): float
    {
        return (float) EcritureComptable::query()
            ->join('compta_comptes', 'compta_comptes.id', '=', 'compta_ecritures.compte_comptable_id')
            ->where('compta_comptes.organization_id', $this->org->id)
            ->where('compta_comptes.numero', $numeroCompte)
            ->selectRaw('COALESCE(SUM(compta_ecritures.credit), 0) - COALESCE(SUM(compta_ecritures.debit), 0) as solde')
            ->value('solde');
    }

    /** Fiche propriétaire de 10 000 000 GNF payée par Matoto, constatée, dont 3 000 000 déjà payés. */
    private function fichePartiellementPayeeParMatoto(): PaiementFiche
    {
        app(PlanComptableBootstrapService::class)->bootstrap($this->org->id);
        $vehicule = $this->makeVehicule($this->cba);
        $this->makePart($vehicule, $this->cba, 'proprietaire', $vehicule->proprietaire_id, 10_000_000);
        $this->calculerAout();

        $fiche = $this->fiche('proprietaire', $vehicule->proprietaire_id);
        $this->assertSame($this->matoto->id, $fiche->site_id);
        app(FicheComptabilisationService::class)->comptabiliserFicheValidee($fiche);

        PaiementFichePaiement::create([
            'fiche_id' => $fiche->id, 'organization_id' => $this->org->id, 'site_id' => $this->matoto->id,
            'montant' => 3_000_000, 'mode_paiement' => 'especes', 'date_paiement' => '2026-08-20',
        ]);
        $fiche->recalculStatut();

        $this->assertSame(7_000_000.0, $this->soldeCrediteur('467110', $this->matoto->id));

        return $fiche->fresh();
    }

    public function test_paiement_partiel_le_deja_paye_reste_a_matoto_le_reste_passe_a_la_nouvelle_agence(): void
    {
        $fiche = $this->fichePartiellementPayeeParMatoto();

        $this->actingAs($this->user)
            ->put(self::URL_PARAMETRES, $this->politique([
                'proprietaire' => ['mode' => 'agence', 'site_id' => $this->cba->id],
            ]))
            ->assertSessionHasNoErrors();

        $fiche->refresh();
        $this->assertSame($this->cba->id, $fiche->site_id);
        $this->assertSame(StatutFichePaiement::PARTIELLEMENT_PAYE, $fiche->statut);
        $this->assertSame(3_000_000.0, (float) $fiche->montant_paye);
        $this->assertSame(7_000_000.0, $fiche->montant_restant);

        // Historique des paiements intact : le paiement de Matoto reste à Matoto.
        $paiements = PaiementFichePaiement::where('fiche_id', $fiche->id)->get();
        $this->assertCount(1, $paiements);
        $this->assertSame($this->matoto->id, $paiements->first()->site_id);
        $this->assertSame(3_000_000.0, (float) $paiements->first()->montant);

        // Dette : plus rien à Matoto, les 7 000 000 restants à Cba.
        $this->assertSame(0.0, $this->soldeCrediteur('467110', $this->matoto->id));
        $this->assertSame(7_000_000.0, $this->soldeCrediteur('467110', $this->cba->id));
        // Charge : Matoto garde ce qu'il a payé (3 000 000), Cba porte le reste (7 000 000).
        $this->assertSame(-3_000_000.0, $this->soldeCrediteur('622100', $this->matoto->id));
        $this->assertSame(-7_000_000.0, $this->soldeCrediteur('622100', $this->cba->id));
        // Trésorerie : seule la caisse de Matoto a décaissé.
        $this->assertSame(3_000_000.0, $this->soldeCrediteur('571000', $this->matoto->id));
        $this->assertSame(0.0, $this->soldeCrediteur('571000', $this->cba->id));

        $reaffectation = PaiementFicheReaffectation::where('fiche_id', $fiche->id)->sole();
        $this->assertSame(7_000_000.0, (float) $reaffectation->montant);
        $this->assertSame($this->matoto->id, $reaffectation->site_origine_id);
        $this->assertSame($this->cba->id, $reaffectation->site_destination_id);
    }

    public function test_aucune_double_comptabilisation_ni_double_paiement_apres_changements_dagence(): void
    {
        $fiche = $this->fichePartiellementPayeeParMatoto();
        $versCba = $this->politique(['proprietaire' => ['mode' => 'agence', 'site_id' => $this->cba->id]]);

        $this->actingAs($this->user)->put(self::URL_PARAMETRES, $versCba)->assertSessionHasNoErrors();
        $pieces = PieceComptable::where('organization_id', $this->org->id)->count();

        // Renvoyer la même politique ne déplace rien et n'écrit rien.
        $this->actingAs($this->user)->put(self::URL_PARAMETRES, $versCba)
            ->assertSessionHas('success', 'Agences payeuses enregistrées. Aucune fiche non payée ne change d\'agence.');
        $this->assertSame($pieces, PieceComptable::where('organization_id', $this->org->id)->count());
        $this->assertSame(1, PaiementFicheReaffectation::where('fiche_id', $fiche->id)->count());
        $this->assertSame(1, CommissionPolitiquePaiementHistorique::where('organization_id', $this->org->id)->count());

        // Retour à Matoto : le reste revient, les totaux de l'organisation ne bougent jamais.
        $this->actingAs($this->user)->put(self::URL_PARAMETRES, $this->politique())->assertSessionHasNoErrors();
        $this->assertSame($this->matoto->id, $fiche->fresh()->site_id);
        $this->assertSame(7_000_000.0, $this->soldeCrediteur('467110', $this->matoto->id));
        $this->assertSame(0.0, $this->soldeCrediteur('467110', $this->cba->id));
        $this->assertSame(7_000_000.0, $this->soldeCrediteurTotal('467110'));
        $this->assertSame(-10_000_000.0, $this->soldeCrediteurTotal('622100'));

        // Le solde payé par la nouvelle agence solde la dette, sans rien payer deux fois.
        PaiementFichePaiement::create([
            'fiche_id' => $fiche->id, 'organization_id' => $this->org->id, 'site_id' => $this->matoto->id,
            'montant' => 7_000_000, 'mode_paiement' => 'especes', 'date_paiement' => '2026-09-05',
        ]);
        $fiche->refresh()->recalculStatut();
        $this->assertSame(StatutFichePaiement::PAYE, $fiche->fresh()->statut);
        $this->assertSame(0.0, $this->soldeCrediteurTotal('467110'));
        $this->assertSame(-10_000_000.0, $this->soldeCrediteurTotal('622100'));
        $this->assertSame(10_000_000.0, $this->soldeCrediteurTotal('571000'));

        // Une fiche payée ne bouge plus, même si la politique change encore.
        $this->actingAs($this->user)->put(self::URL_PARAMETRES, $versCba)
            ->assertSessionHas('success', 'Agences payeuses enregistrées. Aucune fiche non payée ne change d\'agence.');
        $this->assertSame($this->matoto->id, $fiche->fresh()->site_id);
        $this->assertSame(2, PaiementFicheReaffectation::where('fiche_id', $fiche->id)->count());
    }

    public function test_commande_de_realignement_idempotente_sans_ecriture_inutile(): void
    {
        $fiche = $this->fichePartiellementPayeeParMatoto();
        // Fiche calculée avant la règle : payée par l'agence du véhicule, comptabilité comprise.
        app(FicheSiteResponsableService::class)->changerSite($fiche, $this->cba->id);
        $this->assertSame(7_000_000.0, $this->soldeCrediteur('467110', $this->cba->id));

        $this->artisan('commissions:realigner-sites-fiches', ['--appliquer' => true])->assertSuccessful();
        $this->assertSame($this->matoto->id, $fiche->fresh()->site_id);
        $pieces = PieceComptable::where('organization_id', $this->org->id)->count();
        $reaffectations = PaiementFicheReaffectation::where('fiche_id', $fiche->id)->count();

        $this->artisan('commissions:realigner-sites-fiches', ['--appliquer' => true])
            ->expectsOutput('Aucune fiche à réaligner.')
            ->assertSuccessful();
        $this->artisan('commissions:realigner-sites-fiches')
            ->expectsOutput('Aucune fiche à réaligner.')
            ->assertSuccessful();

        $this->assertSame($pieces, PieceComptable::where('organization_id', $this->org->id)->count());
        $this->assertSame($reaffectations, PaiementFicheReaffectation::where('fiche_id', $fiche->id)->count());
        $this->assertSame(7_000_000.0, $this->soldeCrediteur('467110', $this->matoto->id));
        $this->assertSame(0.0, $this->soldeCrediteur('467110', $this->cba->id));
        $this->assertSame(-10_000_000.0, $this->soldeCrediteurTotal('622100'));
    }

    public function test_apercu_de_la_commande_annonce_montants_et_ecritures_sans_rien_ecrire(): void
    {
        $fiche = $this->fichePartiellementPayeeParMatoto();
        app(FicheSiteResponsableService::class)->changerSite($fiche, $this->cba->id);
        $pieces = PieceComptable::where('organization_id', $this->org->id)->count();
        $reaffectations = PaiementFicheReaffectation::count();

        $this->artisan('commissions:realigner-sites-fiches')
            ->expectsOutputToContain('oui (2 pièces)')
            ->expectsOutputToContain('7 000 000')
            ->expectsOutputToContain('1 fiche(s) changent d\'agence qui paie')
            ->assertSuccessful();

        $this->assertSame($this->cba->id, $fiche->fresh()->site_id);
        $this->assertSame($pieces, PieceComptable::where('organization_id', $this->org->id)->count());
        $this->assertSame($reaffectations, PaiementFicheReaffectation::count());
    }

    public function test_lagence_de_rattachement_lit_la_fiche_mais_seule_lagence_payeuse_paie(): void
    {
        $vehicule = $this->makeVehicule($this->cba);
        $this->makePart($vehicule, $this->cba, 'proprietaire', $vehicule->proprietaire_id, 250_000);
        $this->calculerAout();
        $fiche = $this->fiche('proprietaire', $vehicule->proprietaire_id);

        $managerCba = $this->manager($this->cba);
        $managerMatoto = $this->manager($this->matoto);
        $managerSonfonia = $this->manager($this->sonfonia);

        $this->assertTrue($managerCba->can('view', $fiche));
        $this->assertFalse($managerCba->can('payer', $fiche));
        $this->assertTrue($managerMatoto->can('view', $fiche));
        $this->assertTrue($managerMatoto->can('payer', $fiche));
        $this->assertFalse($managerSonfonia->can('view', $fiche));
        $this->assertFalse($managerSonfonia->can('payer', $fiche));
    }

    public function test_ecran_proprietaires_lagence_payeuse_voit_tout_lagence_du_vehicule_garde_la_lecture(): void
    {
        $vehicule = $this->makeVehicule($this->cba);
        $this->makePart($vehicule, $this->cba, 'proprietaire', $vehicule->proprietaire_id, 90_000);

        $ligne = function (User $user) use ($vehicule) {
            $props = $this->actingAs($user)->get('/backoffice/comptabilite/commissions/proprietaires')
                ->assertOk()->viewData('page')['props'];

            return collect($props['beneficiaires'])->firstWhere('beneficiaire_id', $vehicule->proprietaire_id);
        };

        $this->assertNotNull($ligne($this->manager($this->matoto)));
        $this->assertSame('Cba', $ligne($this->manager($this->cba))['agence'] ?? null);
        $this->assertNull($ligne($this->manager($this->sonfonia)));
    }

    public function test_ecran_sites_lagence_payeuse_voit_tous_les_sites(): void
    {
        $vehicule = $this->makeVehicule($this->cba);
        $this->makePart($vehicule, $this->cba, 'site', $this->cba->id, 80_000);

        $ligne = function (User $user) {
            $props = $this->actingAs($user)->get('/backoffice/comptabilite/commissions/sites')
                ->assertOk()->viewData('page')['props'];

            return collect($props['beneficiaires'])->firstWhere('beneficiaire_id', $this->cba->id);
        };

        $this->assertNotNull($ligne($this->manager($this->matoto)));
        $this->assertNotNull($ligne($this->manager($this->cba)));
        $this->assertNull($ligne($this->manager($this->sonfonia)));
    }

    public function test_commande_de_realignement_bascule_les_fiches_existantes_seulement_avec_appliquer(): void
    {
        $vehicule = $this->makeVehicule($this->cba);
        $this->makePart($vehicule, $this->cba, 'proprietaire', $vehicule->proprietaire_id, 250_000);
        $this->calculerAout();

        // Fiche calculée avant la règle : payée par l'agence du véhicule.
        $fiche = $this->fiche('proprietaire', $vehicule->proprietaire_id);
        $fiche->update(['site_id' => $this->cba->id]);

        $this->artisan('commissions:realigner-sites-fiches')->assertSuccessful();
        $this->assertSame($this->cba->id, $fiche->fresh()->site_id);

        $this->artisan('commissions:realigner-sites-fiches', ['--appliquer' => true])->assertSuccessful();
        $this->assertSame($this->matoto->id, $fiche->fresh()->site_id);
        $this->assertSame($this->cba->id, $fiche->fresh()->site_rattachement_id);
    }

    public function test_page_reservee_a_la_permission(): void
    {
        $sansDroit = $this->manager($this->matoto);

        $this->actingAs($sansDroit)->get(self::URL_PARAMETRES)->assertForbidden();
        $this->actingAs($sansDroit)->put(self::URL_PARAMETRES, $this->politique())->assertForbidden();
        $this->assertSame(0, CommissionPolitiquePaiement::count());

        $this->actingAs($this->user)->get(self::URL_PARAMETRES)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('settings/AgencesPayeuses')
                ->where('tresorerie_principale.id', $this->matoto->id)
                ->where('regles.0.type', 'proprietaire')
                ->where('regles.0.mode', 'centralisee')
                ->where('regles.4.type', 'livreur')
                ->where('regles.4.mode', 'rattachement'));
    }

    public function test_validation_agence_obligatoire_et_de_la_meme_organisation(): void
    {
        $this->actingAs($this->user)
            ->put(self::URL_PARAMETRES, $this->politique(['parrain' => ['mode' => 'agence', 'site_id' => null]]))
            ->assertSessionHasErrors('regles.parrain.site_id');

        $autreOrg = Organization::factory()->create();
        $siteEtranger = $this->site('Étranger', $autreOrg);

        $this->actingAs($this->user)
            ->put(self::URL_PARAMETRES, $this->politique(['parrain' => ['mode' => 'agence', 'site_id' => $siteEtranger->id]]))
            ->assertSessionHasErrors('regles.parrain.site_id');

        $this->actingAs($this->user)
            ->put(self::URL_PARAMETRES, $this->politique([], $siteEtranger->id))
            ->assertSessionHasErrors('agence_centralisee_id');

        $this->assertSame(0, CommissionPolitiquePaiement::count());
    }

    public function test_sans_tresorerie_principale_lagence_centralisee_doit_etre_choisie(): void
    {
        $this->matoto->update(['is_central_tresorerie' => false]);

        $this->actingAs($this->user)
            ->put(self::URL_PARAMETRES, $this->politique())
            ->assertSessionHasErrors('agence_centralisee_id');

        $this->actingAs($this->user)
            ->put(self::URL_PARAMETRES, $this->politique([], $this->cba->id))
            ->assertSessionHasNoErrors();
    }

    public function test_permission_presente_au_catalogue(): void
    {
        Permission::firstOrCreate(['name' => 'commissions.configurer_agences_payeuses', 'guard_name' => 'web']);

        $this->assertContains('commissions.configurer_agences_payeuses', PermissionCatalog::allPermissionNames());
    }
}
