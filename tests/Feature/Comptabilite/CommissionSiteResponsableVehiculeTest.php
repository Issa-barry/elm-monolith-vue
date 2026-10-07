<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\CommissionActivationStatut;
use App\Enums\StatutFichePaiement;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionLogistique;
use App\Models\CommissionLogistiquePart;
use App\Models\CommissionProcessus;
use App\Models\Livreur;
use App\Models\PaiementFiche;
use App\Models\PaiementFichePaiement;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\Tresorerie\ObligationsAgenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Règle du 06/10/2026 : une commission livreur/propriétaire se paie au site ACTUEL du véhicule,
 * quel que soit le site où la vente (ou le transfert) a eu lieu, et suit le véhicule tant qu'elle
 * n'est pas payée. Cas réel : Diaraye (rattaché à Matoto) vendait à Cba, Lambanyi, Matoto…
 * et ses commissions apparaissaient à Cba.
 */
class CommissionSiteResponsableVehiculeTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private Site $matoto;

    private Site $cba;

    private ?CommissionProcessus $processus = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['comptabilite.read']);
        $this->matoto = $this->user->sites()->wherePivot('is_default', true)->first();
        $this->matoto->update(['nom' => 'Matoto']);
        $this->cba = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Cba',
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

    private function makeVente(Vehicule $vehicule, Site $siteVente, string $typeBeneficiaire, string $beneficiaireId, float $montant, string $date = '2026-08-05'): CommissionEnveloppePart
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

        $enveloppe = CommissionEnveloppe::create([
            'organization_id' => $this->org->id,
            'source_type' => CommandeVente::class,
            'source_id' => $commande->id,
            'processus_id' => $this->processus->id,
            'cible_type' => $typeBeneficiaire === 'livreur' ? CommissionCibleType::CODE_EQUIPE_LIVRAISON : CommissionCibleType::CODE_PROPRIETAIRE,
            'cible_id' => (string) Str::ulid(),
            'montant_total' => $montant,
            'earned_at' => $date,
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

    public function test_la_fiche_se_paie_au_site_du_vehicule_meme_si_les_ventes_sont_majoritairement_ailleurs(): void
    {
        $diaraye = $this->makeVehicule($this->matoto);
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);

        $this->makeVente($diaraye, $this->cba, 'livreur', $livreur->id, 600_000);
        $this->makeVente($diaraye, $this->matoto, 'livreur', $livreur->id, 100_000);

        $rows = $this->calculerAout();

        $this->assertSame($this->matoto->id, $this->fiche('livreur', $livreur->id)->site_id);
        $this->assertSame(700_000.0, $this->besoin($rows, $this->matoto, 'livreurs_p1'));
        $this->assertSame(0.0, $this->besoin($rows, $this->cba, 'livreurs_p1'));
    }

    public function test_la_fiche_proprietaire_suit_aussi_le_site_du_vehicule(): void
    {
        $diaraye = $this->makeVehicule($this->matoto);
        $this->makeVente($diaraye, $this->cba, 'proprietaire', $diaraye->proprietaire_id, 250_000);

        $this->calculerAout();

        $this->assertSame($this->matoto->id, $this->fiche('proprietaire', $diaraye->proprietaire_id)->site_id);
    }

    public function test_une_fiche_non_payee_suit_le_vehicule_reaffecte_apres_la_periode(): void
    {
        $diaraye = $this->makeVehicule($this->matoto);
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $this->makeVente($diaraye, $this->cba, 'livreur', $livreur->id, 300_000);
        $this->calculerAout();

        $diaraye->update(['site_id' => $this->cba->id]);

        $this->assertSame($this->cba->id, $this->fiche('livreur', $livreur->id)->site_id);
        $this->assertSame(300_000.0, $this->besoin($this->calculerAout(), $this->cba, 'livreurs_p1'));

        $sonfonia = Site::create(['organization_id' => $this->org->id, 'nom' => 'Sonfonia', 'type' => 'depot', 'localisation' => 'Conakry']);
        $diaraye->update(['site_id' => $sonfonia->id]);

        $this->assertSame($sonfonia->id, $this->fiche('livreur', $livreur->id)->site_id);
    }

    public function test_une_fiche_partiellement_payee_suit_le_vehicule_et_le_paiement_deja_fait_garde_son_site(): void
    {
        $diaraye = $this->makeVehicule($this->matoto);
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $this->makeVente($diaraye, $this->cba, 'livreur', $livreur->id, 300_000);
        $this->calculerAout();

        $fiche = $this->fiche('livreur', $livreur->id);
        $paiement = PaiementFichePaiement::create([
            'fiche_id' => $fiche->id,
            'organization_id' => $this->org->id,
            'site_id' => $fiche->site_id,
            'montant' => 100_000,
            'mode_paiement' => 'especes',
            'date_paiement' => '2026-08-20',
        ]);
        $fiche->recalculStatut();

        $diaraye->update(['site_id' => $this->cba->id]);

        $this->assertSame($this->cba->id, $fiche->fresh()->site_id);
        $this->assertSame(StatutFichePaiement::PARTIELLEMENT_PAYE, $fiche->fresh()->statut);
        $this->assertSame($this->matoto->id, $paiement->fresh()->site_id);
    }

    public function test_une_fiche_entierement_payee_ne_change_plus_de_site(): void
    {
        $diaraye = $this->makeVehicule($this->matoto);
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $this->makeVente($diaraye, $this->cba, 'livreur', $livreur->id, 300_000);
        $this->calculerAout();

        $fiche = $this->fiche('livreur', $livreur->id);
        PaiementFichePaiement::create([
            'fiche_id' => $fiche->id,
            'organization_id' => $this->org->id,
            'site_id' => $fiche->site_id,
            'montant' => 300_000,
            'mode_paiement' => 'especes',
            'date_paiement' => '2026-08-20',
        ]);
        $fiche->recalculStatut();

        $diaraye->update(['site_id' => $this->cba->id]);

        $this->assertSame($this->matoto->id, $fiche->fresh()->site_id);
    }

    public function test_la_commission_logistique_se_paie_au_site_du_vehicule_pas_au_site_source(): void
    {
        $vehicule = $this->makeVehicule($this->matoto);
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $transfert = TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $this->cba->id,
            'site_destination_id' => Site::create(['organization_id' => $this->org->id, 'nom' => 'Destination', 'type' => 'depot', 'localisation' => 'Conakry'])->id,
            'vehicule_id' => $vehicule->id,
            'created_by' => $this->user->id,
        ]);
        $commission = CommissionLogistique::create([
            'organization_id' => $this->org->id,
            'transfert_logistique_id' => $transfert->id,
            'vehicule_id' => $vehicule->id,
            'base_calcul' => 'forfait',
            'valeur_base' => 75_000,
            'montant_total' => 75_000,
            'montant_verse' => 0,
            'statut' => 'impaye',
        ]);
        CommissionLogistiquePart::create([
            'commission_logistique_id' => $commission->id,
            'type_beneficiaire' => 'livreur',
            'livreur_id' => $livreur->id,
            'beneficiaire_nom' => 'Livreur',
            'taux_commission' => 100,
            'montant_brut' => 75_000,
            'frais_supplementaires' => 0,
            'montant_net' => 75_000,
            'montant_verse' => 0,
            'statut' => 'impaye',
            'earned_at' => '2026-08-05',
        ]);

        $rows = $this->calculerAout();

        $this->assertSame($this->matoto->id, $this->fiche('livreur', $livreur->id)->site_id);
        $this->assertSame(75_000.0, $this->besoin($rows, $this->matoto, 'livreurs_p1'));

        $vehicule->update(['site_id' => $this->cba->id]);

        $this->assertSame($this->cba->id, $this->fiche('livreur', $livreur->id)->site_id);
    }

    public function test_sans_site_sur_le_vehicule_repli_sur_le_site_de_la_vente(): void
    {
        $vehicule = $this->makeVehicule($this->matoto);
        $vehicule->update(['site_id' => null]);
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $this->makeVente($vehicule, $this->cba, 'livreur', $livreur->id, 120_000);

        $this->calculerAout();

        $this->assertSame($this->cba->id, $this->fiche('livreur', $livreur->id)->site_id);
    }

    public function test_ecran_livreurs_affiche_et_filtre_lagence_du_vehicule(): void
    {
        $diaraye = $this->makeVehicule($this->matoto);
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $this->makeVente($diaraye, $this->cba, 'livreur', $livreur->id, 60_000);

        $props = fn (array $siteIds) => $this->actingAs($this->user)
            ->get('/backoffice/comptabilite/commissions/vente?'.http_build_query(['site_ids' => $siteIds]))
            ->assertOk()
            ->viewData('page')['props'];

        $ligne = collect($props([$this->matoto->id])['beneficiaires'])->firstWhere('beneficiaire_id', $livreur->id);
        $this->assertNotNull($ligne);
        $this->assertSame('Matoto', $ligne['agence']);

        $this->assertNull(collect($props([$this->cba->id])['beneficiaires'])->firstWhere('beneficiaire_id', $livreur->id));
    }

    public function test_un_manager_dune_autre_agence_ne_voit_pas_les_commissions_du_vehicule(): void
    {
        $diaraye = $this->makeVehicule($this->matoto);
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $this->makeVente($diaraye, $this->cba, 'livreur', $livreur->id, 60_000);

        $managerCba = User::factory()->create(['organization_id' => $this->org->id]);
        $managerCba->assignRole(Role::firstOrCreate(['name' => 'employe', 'guard_name' => 'web']));
        $managerCba->givePermissionTo('comptabilite.read');
        $managerCba->sites()->attach($this->cba->id, ['role' => 'employe', 'is_default' => true]);

        $props = $this->actingAs($managerCba)->get('/backoffice/comptabilite/commissions/vente')
            ->assertOk()->viewData('page')['props'];
        $this->assertNull(collect($props['beneficiaires'])->firstWhere('beneficiaire_id', $livreur->id));

        $export = $this->actingAs($managerCba)->get('/backoffice/comptabilite/commissions/vente/export/excel')
            ->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Matoto', $export);

        $managerMatoto = User::factory()->create(['organization_id' => $this->org->id]);
        $managerMatoto->assignRole('employe');
        $managerMatoto->givePermissionTo('comptabilite.read');
        $managerMatoto->sites()->attach($this->matoto->id, ['role' => 'employe', 'is_default' => true]);

        $props = $this->actingAs($managerMatoto)->get('/backoffice/comptabilite/commissions/vente')
            ->assertOk()->viewData('page')['props'];
        $this->assertSame('Matoto', collect($props['beneficiaires'])->firstWhere('beneficiaire_id', $livreur->id)['agence'] ?? null);
    }

    public function test_ecran_proprietaires_affiche_et_filtre_lagence_du_vehicule(): void
    {
        $diaraye = $this->makeVehicule($this->matoto);
        $this->makeVente($diaraye, $this->cba, 'proprietaire', $diaraye->proprietaire_id, 90_000);

        $props = fn (array $siteIds) => $this->actingAs($this->user)
            ->get('/backoffice/comptabilite/commissions/proprietaires?'.http_build_query(['site_ids' => $siteIds]))
            ->assertOk()
            ->viewData('page')['props'];

        $ligne = collect($props([$this->matoto->id])['beneficiaires'])->firstWhere('beneficiaire_id', $diaraye->proprietaire_id);
        $this->assertNotNull($ligne);
        $this->assertSame('Matoto', $ligne['agence']);

        $this->assertNull(collect($props([$this->cba->id])['beneficiaires'])->firstWhere('beneficiaire_id', $diaraye->proprietaire_id));
    }

    public function test_commande_de_realignement_corrige_les_fiches_existantes_seulement_avec_appliquer(): void
    {
        $diaraye = $this->makeVehicule($this->matoto);
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $this->makeVente($diaraye, $this->cba, 'livreur', $livreur->id, 300_000);
        $this->calculerAout();

        // Fiche calculée avant la règle : rattachée au site de la vente.
        $fiche = $this->fiche('livreur', $livreur->id);
        $fiche->update(['site_id' => $this->cba->id]);

        $this->artisan('commissions:realigner-sites-fiches')->assertSuccessful();
        $this->assertSame($this->cba->id, $fiche->fresh()->site_id);

        $this->artisan('commissions:realigner-sites-fiches', ['--appliquer' => true])->assertSuccessful();
        $this->assertSame($this->matoto->id, $fiche->fresh()->site_id);
    }
}
