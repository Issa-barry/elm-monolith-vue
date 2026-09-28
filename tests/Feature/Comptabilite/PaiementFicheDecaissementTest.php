<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\PrestataireType;
use App\Enums\StatutFichePaiement;
use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use App\Features\ModuleFeature;
use App\Http\Controllers\Comptabilite\PaiementFichePaiementController;
use App\Models\CompteTresorerie;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\PaiementFiche;
use App\Models\PaiementFichePaiement;
use App\Models\PaiementPeriode;
use App\Models\Personne;
use App\Models\PieceComptable;
use App\Models\Prestataire;
use App\Models\Site;
use App\Services\Commission\FichePayableResolver;
use App\Services\Tresorerie\DecaissementFicheResolver;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Payer une fiche = décaissement réel (ADR 0009) : l'argent sort d'un support de trésorerie de
 * l'agence de la fiche (siège principal pour une fiche sans agence) — caisse dédiée du payeur en
 * espèces, compte Mobile Money/banque sinon. Solde insuffisant = refus serveur, sans aucun effet.
 */
class PaiementFicheDecaissementTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, RefreshDatabase;

    private Site $agence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['comptabilite.read', 'comptabilite.payer']);
        Feature::for($this->org)->activate(ModuleFeature::COMPTABILITE);
        $this->agence = $this->user->sites()->wherePivot('is_default', true)->firstOrFail();
    }

    private function fiche(?string $siteId, float $net = 300_000, string $type = 'livreur', ?string $beneficiaireId = null): PaiementFiche
    {
        $periode = PaiementPeriode::create([
            'organization_id' => $this->org->id,
            'reference' => 'PAY-TEST-'.Str::upper(Str::random(6)),
            'type' => TypePeriodePaiement::LIVREUR->value,
            'date_debut' => now()->startOfMonth()->toDateString(),
            'date_fin' => now()->startOfMonth()->addDays(14)->toDateString(),
            'statut' => StatutPeriodePaiement::VALIDEE->value,
            'created_by' => $this->user->id,
        ]);

        return PaiementFiche::create([
            'organization_id' => $this->org->id,
            'periode_id' => $periode->id,
            'reference' => 'FICHE-TEST-'.Str::upper(Str::random(6)),
            'beneficiaire_type' => $type,
            'beneficiaire_id' => $beneficiaireId ?? Livreur::factory()->create(['organization_id' => $this->org->id])->id,
            'beneficiaire_nom' => 'Bénéficiaire Test',
            'site_id' => $siteId,
            'montant_brut' => $net,
            'total_deductions' => 0,
            'montant_net' => $net,
            'montant_paye' => 0,
            'statut' => StatutFichePaiement::A_PAYER->value,
        ]);
    }

    private function payer(PaiementFiche $fiche, array $donnees)
    {
        return $this->actingAs($this->user)->post(route('comptabilite.fiches.paiements.store', $fiche), array_merge([
            'date_paiement' => now()->toDateString(),
        ], $donnees));
    }

    private function solde(CompteTresorerie $support): float
    {
        return app(TresorerieDisponibiliteService::class)->soldePourSupport($support->fresh(), now());
    }

    private function prestataire(): Prestataire
    {
        $personne = Personne::create([
            'organization_id' => $this->org->id,
            'nom' => 'Diallo',
            'prenom' => 'Consultant',
            'telephone' => '+224'.fake()->unique()->numerify('#########'),
        ]);

        return Prestataire::create([
            'organization_id' => $this->org->id,
            'reference' => 'PRE-'.uniqid(),
            'personne_id' => $personne->id,
            'type' => PrestataireType::CONSULTANT->value,
            'is_active' => true,
        ]);
    }

    // ── Débit du support réel ─────────────────────────────────────────────────

    public function test_especes_debite_la_caisse_dediee_du_payeur(): void
    {
        $caisse = $this->equiperPayeurEspeces($this->user, $this->agence->id, 500_000);
        $fiche = $this->fiche($this->agence->id);

        $this->payer($fiche, ['montant' => 200_000, 'mode_paiement' => 'especes'])->assertSessionHasNoErrors();

        $this->assertSame(300_000.0, $this->solde($caisse));
        $paiement = PaiementFichePaiement::where('fiche_id', $fiche->id)->firstOrFail();
        $this->assertSame($caisse->id, $paiement->compte_tresorerie_id);
        $this->assertSame($this->agence->id, $paiement->site_id);
        $this->assertSame(StatutFichePaiement::PARTIELLEMENT_PAYE, $fiche->fresh()->statut);
    }

    public function test_mobile_money_debite_le_compte_reel_et_garde_la_reference(): void
    {
        $orange = $this->creerSupportAgence($this->agence->id, 'mobile_money', '561100', 'orange_money');
        $this->alimenterCaisse($orange, 1_000_000);
        $fiche = $this->fiche($this->agence->id);

        $this->payer($fiche, [
            'montant' => 300_000,
            'mode_paiement' => 'mobile_money',
            'compte_tresorerie_id' => $orange->id,
            'reference_paiement' => 'OM123456789',
        ])->assertSessionHasNoErrors();

        $this->assertSame(700_000.0, $this->solde($orange));
        $this->assertDatabaseHas('paiement_fiche_paiements', [
            'fiche_id' => $fiche->id,
            'compte_tresorerie_id' => $orange->id,
            'reference_paiement' => 'OM123456789',
            'moyen_paiement_detail' => 'orange_money',
        ]);
        $this->assertSame(StatutFichePaiement::PAYE, $fiche->fresh()->statut);
    }

    public function test_virement_debite_le_compte_bancaire(): void
    {
        $banque = $this->creerSupportAgence($this->agence->id, 'banque', '521000');
        $this->alimenterCaisse($banque, 2_000_000);
        $fiche = $this->fiche($this->agence->id);

        $this->payer($fiche, [
            'montant' => 300_000,
            'mode_paiement' => 'virement',
            'compte_tresorerie_id' => $banque->id,
            'reference_paiement' => 'VIR-001',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1_700_000.0, $this->solde($banque));
    }

    // ── Refus ─────────────────────────────────────────────────────────────────

    public function test_solde_insuffisant_refuse_sans_aucun_effet(): void
    {
        $caisse = $this->equiperPayeurEspeces($this->user, $this->agence->id, 100_000);
        $fiche = $this->fiche($this->agence->id);
        $piecesAvant = PieceComptable::count();

        $this->payer($fiche, ['montant' => 150_000, 'mode_paiement' => 'especes'])
            ->assertSessionHasErrors(['montant' => 'Solde insuffisant : 100 000 GNF disponible dans « '.$caisse->libelle.' » pour un paiement de 150 000 GNF.']);

        $this->assertDatabaseCount('paiement_fiche_paiements', 0);
        $this->assertSame($piecesAvant, PieceComptable::count());
        $this->assertSame(100_000.0, $this->solde($caisse));
        $this->assertSame(StatutFichePaiement::A_PAYER, $fiche->fresh()->statut);
        $this->assertSame(0.0, (float) $fiche->fresh()->montant_paye);
    }

    public function test_le_solde_est_relu_entre_deux_paiements_successifs(): void
    {
        $caisse = $this->equiperPayeurEspeces($this->user, $this->agence->id, 150_000);
        $premiere = $this->fiche($this->agence->id);
        $seconde = $this->fiche($this->agence->id);

        $this->payer($premiere, ['montant' => 100_000, 'mode_paiement' => 'especes'])->assertSessionHasNoErrors();
        $this->payer($seconde, ['montant' => 100_000, 'mode_paiement' => 'especes'])->assertSessionHasErrors('montant');

        $this->assertSame(50_000.0, $this->solde($caisse));
        $this->assertDatabaseCount('paiement_fiche_paiements', 1);
    }

    public function test_moyen_dune_autre_agence_refuse(): void
    {
        $kindia = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kindia', 'type' => 'depot', 'localisation' => 'Kindia']);
        $orangeKindia = $this->creerSupportAgence($kindia->id, 'mobile_money', '561100', 'orange_money');
        $this->alimenterCaisse($orangeKindia, 1_000_000);
        $fiche = $this->fiche($this->agence->id);

        $this->payer($fiche, [
            'montant' => 100_000,
            'mode_paiement' => 'mobile_money',
            'compte_tresorerie_id' => $orangeKindia->id,
            'reference_paiement' => 'OM1',
        ])->assertSessionHasErrors(['compte_tresorerie_id' => DecaissementFicheResolver::MESSAGE_MOYEN_INDISPONIBLE]);

        $this->assertDatabaseCount('paiement_fiche_paiements', 0);
        $this->assertSame(1_000_000.0, $this->solde($orangeKindia));
    }

    public function test_reference_obligatoire_pour_le_mobile_money(): void
    {
        $orange = $this->creerSupportAgence($this->agence->id, 'mobile_money', '561100', 'orange_money');
        $this->alimenterCaisse($orange, 1_000_000);
        $fiche = $this->fiche($this->agence->id);

        $this->payer($fiche, [
            'montant' => 100_000,
            'mode_paiement' => 'mobile_money',
            'compte_tresorerie_id' => $orange->id,
        ])->assertSessionHasErrors('reference_paiement');

        $this->assertDatabaseCount('paiement_fiche_paiements', 0);
    }

    public function test_especes_sans_caisse_active_refusees(): void
    {
        $fiche = $this->fiche($this->agence->id);

        $this->payer($fiche, ['montant' => 100_000, 'mode_paiement' => 'especes'])
            ->assertSessionHasErrors(['mode_paiement' => DecaissementFicheResolver::MESSAGE_SANS_CAISSE]);

        $this->assertDatabaseCount('paiement_fiche_paiements', 0);
    }

    public function test_support_dune_autre_organisation_refuse(): void
    {
        $autreOrg = Organization::factory()->create();
        $autreSite = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Externe', 'type' => 'depot', 'localisation' => 'Conakry']);
        $supportExterne = $this->creerSupportAgence($autreSite->id, 'banque', '521000');
        $fiche = $this->fiche($this->agence->id);

        $this->payer($fiche, [
            'montant' => 100_000,
            'mode_paiement' => 'virement',
            'compte_tresorerie_id' => $supportExterne->id,
            'reference_paiement' => 'VIR-X',
        ])->assertSessionHasErrors('compte_tresorerie_id');

        $this->assertDatabaseCount('paiement_fiche_paiements', 0);
    }

    // ── Fiche salarié : jamais payable par la fiche (circuit Paie) ────────────

    public function test_fiche_salarie_refusee_sans_aucun_effet(): void
    {
        $caisse = $this->equiperPayeurEspeces($this->user, $this->agence->id, 500_000);
        $fiche = $this->fiche($this->agence->id, 200_000, 'salarie', (string) Str::ulid());
        $piecesAvant = PieceComptable::count();

        $this->payer($fiche, ['montant' => 100_000, 'mode_paiement' => 'especes'])
            ->assertSessionHasErrors(['fiche' => PaiementFichePaiementController::MESSAGE_SALARIE]);

        $this->assertDatabaseCount('paiement_fiche_paiements', 0);
        $this->assertSame($piecesAvant, PieceComptable::count());
        $this->assertSame(500_000.0, $this->solde($caisse));
        $this->assertSame(StatutFichePaiement::A_PAYER, $fiche->fresh()->statut);
    }

    public function test_fiche_salarie_sans_bouton_payer_mais_consultable(): void
    {
        $fiche = $this->fiche($this->agence->id, 200_000, 'salarie', (string) Str::ulid());

        $this->actingAs($this->user)
            ->get(route('comptabilite.fiches.show', $fiche))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Comptabilite/Fiches/Show')
                ->where('can_payer', false)
                ->where('paiement', null)
            );
    }

    // ── Fiche sans agence : siège principal ───────────────────────────────────

    public function test_fiche_consultant_sans_agence_payee_depuis_le_siege_principal(): void
    {
        $siege = Site::create(['organization_id' => $this->org->id, 'nom' => 'Siège', 'type' => 'siege', 'localisation' => 'Conakry']);
        $this->assertTrue((bool) $siege->fresh()->is_siege_principal);
        // Une caisse dédiée n'existe que pour un agent rattaché à l'agence.
        $this->user->sites()->attach($siege->id, ['role' => 'employe', 'is_default' => false]);
        $caisseSiege = $this->equiperPayeurEspeces($this->user, $siege->id, 1_000_000);
        $fiche = $this->fiche(null, 250_000, 'prestataire', $this->prestataire()->id);

        $this->payer($fiche, ['montant' => 250_000, 'mode_paiement' => 'especes'])->assertSessionHasNoErrors();

        $this->assertSame(750_000.0, $this->solde($caisseSiege));
        $this->assertSame($siege->id, PaiementFichePaiement::where('fiche_id', $fiche->id)->value('site_id'));
    }

    public function test_fiche_sans_agence_et_sans_siege_principal_bloquee(): void
    {
        $this->equiperPayeurEspeces($this->user, $this->agence->id);
        $fiche = $this->fiche(null, 250_000, 'prestataire', $this->prestataire()->id);

        $this->payer($fiche, ['montant' => 100_000, 'mode_paiement' => 'especes'])
            ->assertSessionHasErrors(['compte_tresorerie_id' => DecaissementFicheResolver::MESSAGE_SANS_SIEGE]);

        $this->assertDatabaseCount('paiement_fiche_paiements', 0);
    }

    // ── Données du dialogue ───────────────────────────────────────────────────

    public function test_le_dialogue_recoit_les_moyens_de_lagence_avec_leur_solde(): void
    {
        $this->equiperPayeurEspeces($this->user, $this->agence->id, 400_000);
        $orange = $this->creerSupportAgence($this->agence->id, 'mobile_money', '561100', 'orange_money');
        $this->alimenterCaisse($orange, 900_000);
        $fiche = $this->fiche($this->agence->id);

        $presentee = FichePayableResolver::pourBeneficiaires($this->user, 'livreur', [$fiche->beneficiaire_id])
            ->get($fiche->beneficiaire_id);

        $this->assertSame($this->agence->id, $presentee['tresorerie']['site_id']);
        $this->assertTrue($presentee['tresorerie']['especes_disponibles']);
        $this->assertSame(400_000.0, $presentee['tresorerie']['solde_especes']);
        $this->assertCount(1, $presentee['tresorerie']['moyens']);
        $this->assertSame($orange->id, $presentee['tresorerie']['moyens'][0]['compte_tresorerie_id']);
        $this->assertSame(900_000.0, $presentee['tresorerie']['moyens'][0]['solde_disponible']);
        $this->assertSame(300_000.0, $presentee['montant_net']);
        $this->assertSame(0.0, $presentee['montant_paye']);
    }

    public function test_le_dialogue_signale_labsence_de_siege_pour_une_fiche_sans_agence(): void
    {
        $fiche = $this->fiche(null, 250_000, 'prestataire', $this->prestataire()->id);

        $presentee = FichePayableResolver::pourBeneficiaires($this->user, 'prestataire', [$fiche->beneficiaire_id])
            ->get($fiche->beneficiaire_id);

        $this->assertNull($presentee['tresorerie']['site_id']);
        $this->assertSame(DecaissementFicheResolver::MESSAGE_SANS_SIEGE, $presentee['tresorerie']['message']);
    }
}
