<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutAffectationRemuneration;
use App\Enums\StatutDepense;
use App\Enums\StatutPeriodePaie;
use App\Enums\StatutTransmissionPaie;
use App\Enums\StatutTypeRemuneration;
use App\Enums\TypeVariablePaie;
use App\Models\AffectationRemuneration;
use App\Models\AuditLog;
use App\Models\Contrat;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\Employe;
use App\Models\Organization;
use App\Models\PaieLigne;
use App\Models\PaiePaiement;
use App\Models\PaiePeriode;
use App\Models\Site;
use App\Models\TransmissionPaie;
use App\Models\TypeRemuneration;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Comptabilite\ReprisePaieService;
use App\Services\PaieCalculService;
use App\Services\Rh\Paie\TransmissionPaieService;
use App\Services\Tresorerie\Obligations\SalaireObligationContributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\Feature\Concerns\PrepareLaPaieDepuisLesRh;
use Tests\Feature\Concerns\SimuleUnePanneDuJournal;
use Tests\TestCase;

/**
 * Reprise de la transmission RH par la Comptabilité (lot 5, ADR 0041, docs/paie.md) : copie sans
 * recalcul des montants figés, une seule reprise par version, version en vigueur seulement,
 * remplacement avant validation, atomicité, droits et cloisonnement, avantages en nature hors net,
 * retenues de la Comptabilité, paiement inchangé, régularisation des dépenses tardives.
 *
 * Scénario du guide (novembre 2026, 30 jours) : A = 3 000 000 + prime 500 000 du 01 au 14/11
 * (233 333,33) puis 600 000 dès le 15/11 (320 000) + logement 150 000 (avantage en nature) ;
 * B = 3 000 000 dès le 16/11 (1 500 000). Total versé 5 053 333,33 ; valorisé 150 000.
 */
class ReprisePaieTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, PrepareLaPaieDepuisLesRh, RefreshDatabase, SimuleUnePanneDuJournal;

    private Site $site;

    private Employe $a;

    private Employe $b;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-12-02 09:00:00');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->initOrgAndUser(['comptabilite.read', 'comptabilite.payer', 'rh-paie.read', 'rh-paie.update', 'rh-paie.validate']);
        $this->site = Site::where('organization_id', $this->org->id)->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Aides ─────────────────────────────────────────────────────────────────

    private function salarie(string $nom, float $salaire, string $debut = '2025-01-01', ?Organization $org = null): Employe
    {
        $org ??= $this->org;
        $site = Site::where('organization_id', $org->id)->first();
        $e = Employe::factory()->create([
            'organization_id' => $org->id,
            'site_id' => $site?->id,
            'nom' => $nom,
            'prenom' => 'Test',
            'matricule' => 'M'.random_int(1000, 9999),
        ]);
        Contrat::create([
            'organization_id' => $org->id,
            'employe_id' => $e->id,
            'type_contrat' => 'cdi',
            'date_debut' => $debut,
            'salaire_base' => $salaire,
            'statut_contrat' => 'actif',
        ]);

        return $e;
    }

    private function type(string $code, string $categorie = 'prime'): TypeRemuneration
    {
        return TypeRemuneration::create([
            'organization_id' => $this->org->id,
            'code' => $code,
            'libelle' => ucfirst(strtolower($code)),
            'categorie' => $categorie,
            'mode_calcul' => 'montant_fixe',
            'periodicite' => 'mensuelle',
            'nature_montant' => 'fixe',
            'proratisable' => true,
            'statut' => StatutTypeRemuneration::ACTIF->value,
        ]);
    }

    private function affectation(Employe $e, TypeRemuneration $t, float $montant, string $debut, ?string $fin = null): AffectationRemuneration
    {
        return AffectationRemuneration::create([
            'organization_id' => $this->org->id,
            'employe_id' => $e->id,
            'type_remuneration_id' => $t->id,
            'date_debut' => $debut,
            'date_fin' => $fin,
            'montant' => $montant,
            'statut' => StatutAffectationRemuneration::VALIDEE->value,
        ]);
    }

    private function scenarioDuGuide(): void
    {
        $this->a = $this->salarie('ALPHA', 3000000);
        $this->b = $this->salarie('BRAVO', 3000000, '2026-11-16');
        $prime = $this->type('TRANSPORT');
        $this->affectation($this->a, $prime, 500000, '2026-11-01', '2026-11-14');
        $this->affectation($this->a, $prime, 600000, '2026-11-15');
        $this->affectation($this->a, $this->type('LOGEMENT', 'avantage_nature'), 150000, '2026-01-01');
    }

    private function periode(int $mois = 11): PaiePeriode
    {
        return app(PaieCalculService::class)->getOrCreerPeriode($this->org->id, $mois, 2026);
    }

    private function reprendre(PaiePeriode $periode, TransmissionPaie $transmission, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user)
            ->from('/backoffice/comptabilite/salaires?mois='.$periode->mois.'&annee=2026')
            ->post(route('comptabilite.salaires.periodes.reprendre', $periode), ['transmission_id' => $transmission->id]);
    }

    private function valider(PaiePeriode $periode)
    {
        return $this->actingAs($this->user)
            ->from('/backoffice/comptabilite/salaires?mois='.$periode->mois.'&annee=2026')
            ->post(route('comptabilite.salaires.periodes.valider', $periode));
    }

    private function ligne(Employe $e, ?PaiePeriode $periode = null): PaieLigne
    {
        return PaieLigne::where('paie_periode_id', ($periode ?? $this->periode())->id)->where('employe_id', $e->id)->firstOrFail();
    }

    private function avance(Employe $e, float $montant, string $date, StatutDepense $statut = StatutDepense::VALIDE): Depense
    {
        $type = DepenseType::where('organization_id', $this->org->id)->where('type_paie', TypeVariablePaie::AVANCE->value)->first()
            ?? DepenseType::factory()->employe()->create([
                'organization_id' => $this->org->id,
                'libelle' => 'Avance sur salaire',
                'type_paie' => TypeVariablePaie::AVANCE->value,
            ]);

        $factory = Depense::factory();
        $factory = $statut === StatutDepense::VALIDE ? $factory->valide() : $factory->soumis();

        return $factory->create([
            'organization_id' => $this->org->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $type->id,
            'beneficiaire_type' => 'employe',
            'beneficiaire_id' => $e->id,
            'montant' => $montant,
            'date_depense' => $date,
        ]);
    }

    // ── Reprise d'une version transmise ───────────────────────────────────────

    public function test_la_reprise_copie_les_montants_figes_sans_recalcul(): void
    {
        $this->scenarioDuGuide();
        $transmission = $this->transmettreLeMois($this->org, 11, 2026);
        // Donnée RH modifiée après la transmission : la Comptabilité ne doit pas la voir.
        Contrat::where('employe_id', $this->a->id)->update(['salaire_base' => 9000000]);
        $periode = $this->periode();

        $this->reprendre($periode, $transmission)->assertSessionHasNoErrors();

        $a = $this->ligne($this->a);
        $this->assertSame(3000000.0, (float) $a->salaire_base);
        $this->assertSame(553333.33, (float) $a->total_primes);
        $this->assertSame(150000.0, (float) $a->total_avantages_nature);
        $this->assertSame(3553333.33, (float) $a->brut);
        $this->assertSame(3553333.33, (float) $a->net, 'avantage en nature hors net');
        $this->assertSame(30.0, (float) $a->jours_travailles);

        $b = $this->ligne($this->b);
        $this->assertSame(1500000.0, (float) $b->salaire_base);
        $this->assertSame(15.0, (float) $b->jours_travailles);
        $this->assertSame(1500000.0, (float) $b->net);

        // Concordance avec la version figée.
        $lignes = PaieLigne::where('paie_periode_id', $periode->id)->get();
        $this->assertSame(5053333.33, round((float) $lignes->sum('net'), 2));
        $this->assertSame((float) $transmission->total_verse, round((float) $lignes->sum('net'), 2));
        $this->assertSame((float) $transmission->total_valorise, (float) $lignes->sum('total_avantages_nature'));

        $periode->refresh();
        $this->assertSame(StatutPeriodePaie::CALCULE, $periode->statut);
        $this->assertSame($transmission->id, $periode->transmission_paie_id);
        $this->assertSame($this->user->id, $periode->reprise_par);
        $transmission->refresh();
        $this->assertNotNull($transmission->prise_en_charge_le);
        $this->assertSame('paie_periode:'.$periode->id, $transmission->prise_en_charge_reference);
        $this->assertSame(StatutTransmissionPaie::TRANSMISE, $transmission->statut);

        $journal = AuditLog::where('auditable_id', $periode->id)->sole();
        $this->assertStringContainsString('version 1 reprise : 2 salarié(s)', $journal->meta['description']);
        $this->assertArrayNotHasKey('lignes', $journal->new_values ?? [], 'pas de détail par salarié dans le journal');

        $this->actingAs($this->user)->get('/backoffice/comptabilite/salaires?mois=11&annee=2026')
            ->assertInertia(fn (Assert $page) => $page
                ->where('reprise.a_jour', true)
                ->where('reprise.a_reprendre', false)
                ->where('reprise.reprise.version', 1)
                ->where('can_reprendre', false)
                ->where('can_valider_periode', true)
                ->where('kpis.total_net', 5053333.33)
                ->where('kpis.total_avantages_nature', 150000)
                ->where('lignes.0.employe_id', $this->a->id)
                ->has('lignes.0.elements', 4)
                ->where('lignes.0.elements', fn ($elements) => collect($elements)->pluck('montant')->sort()->values()->all() === [150000, 233333.33, 320000, 3000000]
                    && collect($elements)->where('avantage_nature', true)->pluck('montant')->all() === [150000]));
    }

    public function test_la_comptabilite_suit_l_etape_de_la_paie_sans_transmission_manuelle(): void
    {
        $rh = app(TransmissionPaieService::class);
        $etat = fn () => app(ReprisePaieService::class)->etat($this->periode()->fresh());
        $acteur = $this->acteurPaie($this->org);
        $this->salarie('DIALLO', 3000000);
        $this->assertSame('paie_aucune', $etat()['etape']['code']);

        // Brouillon RH : visible en lecture seule, ni repris ni payable.
        $brouillon = $rh->preparer($acteur, 2026, 11);
        $this->assertSame('paie_prete_a_valider', $etat()['etape']['code']);
        $this->assertSame($brouillon->version, $etat()['brouillon']['version']);
        $this->assertFalse($etat()['a_reprendre']);

        // Une donnée RH change : la Comptabilité voit qu'il est à recalculer.
        Contrat::where('organization_id', $this->org->id)->update(['salaire_base' => 4000000]);
        $this->assertSame('paie_a_recalculer', $etat()['etape']['code']);

        // Validée par les RH : aussitôt disponible, sans « Transmettre ».
        $rh->recalculer($brouillon, $acteur);
        $rh->valider($brouillon->fresh(), $acteur);
        $this->assertSame('paie_validee_rh', $etat()['etape']['code']);
        $this->assertTrue($etat()['a_reprendre']);

        $this->reprendre($this->periode(), $brouillon->fresh())->assertSessionHasNoErrors();
        $this->assertSame('paie_reprise', $etat()['etape']['code']);
    }

    public function test_l_ecran_propose_la_reprise_d_une_version_transmise(): void
    {
        $this->scenarioDuGuide();
        $this->actingAs($this->user)->get('/backoffice/comptabilite/salaires?mois=11&annee=2026')
            ->assertInertia(fn (Assert $page) => $page
                ->where('reprise.courante', null)
                ->where('can_reprendre', false)
                ->where('can_valider_periode', false)
                ->has('lignes', 0));

        $transmission = $this->transmettreLeMois($this->org, 11, 2026);

        $this->actingAs($this->user)->get('/backoffice/comptabilite/salaires?mois=11&annee=2026')
            ->assertInertia(fn (Assert $page) => $page
                ->where('reprise.courante.id', $transmission->id)
                ->where('reprise.courante.total_verse', 5053333.33)
                ->where('reprise.a_reprendre', true)
                ->where('can_reprendre', true)
                ->where('can_valider_periode', false));
    }

    public function test_seule_la_version_transmise_en_vigueur_est_reprise(): void
    {
        $this->scenarioDuGuide();
        $rh = app(TransmissionPaieService::class);
        $acteur = $this->acteurPaie($this->org);
        $periode = $this->periode();

        $brouillon = $rh->preparer($acteur, 2026, 11);
        $this->reprendre($periode, $brouillon)->assertSessionHasErrors(['reprise' => 'Seule une version transmise par les RH peut être reprise (version 1 : Brouillon).']);

        // ADR 0047 : validée par les RH = disponible pour la Comptabilité.
        $v1 = $rh->valider($brouillon, $acteur);
        $this->affectation($this->b, $this->type('RISQUE'), 200000, '2026-12-01');
        $v2 = $this->transmettreLeMois($this->org, 11, 2026);

        $this->reprendre($periode, $v1->fresh())
            ->assertSessionHasErrors(['reprise' => 'La version 1 a été remplacée par une version plus récente : reprenez la version transmise en vigueur.']);
        $this->assertSame(0, PaieLigne::count());

        $this->reprendre($periode, $v2)->assertSessionHasNoErrors();
        $this->assertSame($v2->id, $periode->fresh()->transmission_paie_id);
        $this->assertNull($v1->fresh()->prise_en_charge_le);

        // Version d'un autre mois.
        $decembre = $this->transmettreLeMois($this->org, 12, 2026);
        $this->reprendre($periode, $decembre)->assertSessionHasErrors(['reprise' => 'Cette version de transmission ne concerne pas la paie de Novembre 2026.']);
    }

    public function test_une_version_n_est_reprise_qu_une_fois_meme_en_double_clic(): void
    {
        $this->scenarioDuGuide();
        $transmission = $this->transmettreLeMois($this->org, 11, 2026);
        $periode = $this->periode();

        $this->reprendre($periode, $transmission)->assertSessionHasNoErrors();
        $repriseLe = $transmission->fresh()->prise_en_charge_le;
        $this->reprendre($periode, $transmission)->assertSessionHasErrors(['reprise' => 'La version 1 a déjà été reprise.']);

        $this->assertSame(2, PaieLigne::where('paie_periode_id', $periode->id)->count());
        $this->assertEquals($repriseLe, $transmission->fresh()->prise_en_charge_le);
        $this->assertSame(1, AuditLog::where('auditable_id', $periode->id)->count());

        // La même version ne peut pas non plus alimenter la période d'un autre mois.
        $this->expectException(InvalidArgumentException::class);
        app(ReprisePaieService::class)->reprendre($this->periode(12), $transmission->id, $this->user);
    }

    public function test_une_nouvelle_version_avant_validation_remplace_les_lignes_et_garde_la_trace(): void
    {
        $this->scenarioDuGuide();
        $v1 = $this->transmettreLeMois($this->org, 11, 2026);
        $periode = $this->periode();
        $this->reprendre($periode, $v1)->assertSessionHasNoErrors();

        $this->affectation($this->b, $this->type('RISQUE'), 200000, '2026-11-16');
        $v2 = $this->transmettreLeMois($this->org, 11, 2026);

        $this->actingAs($this->user)->get('/backoffice/comptabilite/salaires?mois=11&annee=2026')
            ->assertInertia(fn (Assert $page) => $page
                ->where('reprise.a_reprendre', true)
                ->where('reprise.reprise.version', 1)
                ->where('reprise.courante.version', 2)
                ->where('can_valider_periode', false));
        $this->valider($periode)->assertSessionHasErrors(['periode' => 'Les RH ont transmis une version plus récente (version 2) : reprenez-la avant de valider.']);
        $this->assertSame(StatutPeriodePaie::CALCULE, $periode->fresh()->statut);

        $this->reprendre($periode, $v2)->assertSessionHasNoErrors();

        $this->assertSame(1600000.0, (float) $this->ligne($this->b)->net, '1 500 000 + 200 000 × 15/30');
        $this->assertSame(2, PaieLigne::where('paie_periode_id', $periode->id)->count());
        $this->assertNotNull($v1->fresh()->prise_en_charge_le, 'la reprise de la version 1 reste tracée');
        $this->assertSame(StatutTransmissionPaie::REMPLACEE, $v1->fresh()->statut);
        $this->assertSame($v2->id, $periode->fresh()->transmission_paie_id);
        $this->assertStringContainsString('(remplace la version 1)', AuditLog::where('auditable_id', $periode->id)->latest('id')->first()->meta['description']);

        $this->valider($periode)->assertSessionHasNoErrors();
        $this->assertSame(StatutPeriodePaie::VALIDE_RH, $periode->fresh()->statut);
    }

    public function test_la_validation_exige_une_reprise_et_ferme_ensuite_le_mois(): void
    {
        $this->scenarioDuGuide();
        $transmission = $this->transmettreLeMois($this->org, 11, 2026);
        $periode = $this->periode();

        $this->valider($periode)->assertSessionHasErrors('periode');
        $this->assertSame(StatutPeriodePaie::BROUILLON, $periode->fresh()->statut);

        $this->reprendre($periode, $transmission);
        $this->valider($periode)->assertSessionHasNoErrors();

        // Mois validé : plus de nouvelle version côté RH, plus de reprise côté Comptabilité.
        try {
            app(TransmissionPaieService::class)->preparer($this->acteurPaie($this->org), 2026, 11, 'Oubli');
            $this->fail('Une nouvelle version a été préparée sur un mois validé.');
        } catch (ValidationException) {
        }
        $this->expectExceptionMessage('La paie de Novembre 2026 est déjà validée : aucune transmission ne peut plus y être reprise.');
        app(ReprisePaieService::class)->reprendre($periode->fresh(), $transmission->id, $this->user);
    }

    // ── Atomicité et intégrité ────────────────────────────────────────────────

    public function test_une_panne_pendant_la_reprise_n_ecrit_rien_et_la_reprise_peut_etre_relancee(): void
    {
        $this->scenarioDuGuide();
        $transmission = $this->transmettreLeMois($this->org, 11, 2026);
        $periode = $this->periode();
        $this->simulerPanneDuJournal();

        try {
            app(ReprisePaieService::class)->reprendre($periode, $transmission->id, $this->user);
            $this->fail('La reprise aurait dû échouer.');
        } catch (RuntimeException) {
        }

        $this->assertSame(0, PaieLigne::count());
        $this->assertSame(0, DB::table('paie_variables')->count());
        $this->assertNull($transmission->fresh()->prise_en_charge_le);
        $this->assertNull($periode->fresh()->transmission_paie_id);
        $this->assertSame(StatutPeriodePaie::BROUILLON, $periode->fresh()->statut);

        // Nouvelle tentative après l'erreur.
        $this->app->forgetInstance(AuditLogService::class);
        $this->reprendre($periode, $transmission)->assertSessionHasNoErrors();
        $this->assertSame(2, PaieLigne::count());
    }

    public function test_une_version_alteree_en_base_n_est_pas_reprise(): void
    {
        $this->scenarioDuGuide();
        $transmission = $this->transmettreLeMois($this->org, 11, 2026);
        DB::table('transmission_paie_lignes')->where('transmission_paie_id', $transmission->id)
            ->where('employe_id', $this->b->id)->update(['montant' => 2500000]);

        $this->reprendre($this->periode(), $transmission)
            ->assertSessionHasErrors(['reprise' => 'Le contenu de la version 1 ne correspond plus à son empreinte : elle ne peut pas être lue.']);
        $this->assertSame(0, PaieLigne::count());
        $this->assertNull($transmission->fresh()->prise_en_charge_le);
    }

    // ── Droits et cloisonnement ───────────────────────────────────────────────

    public function test_droits_et_cloisonnement(): void
    {
        $this->scenarioDuGuide();
        $transmission = $this->transmettreLeMois($this->org, 11, 2026);
        $periode = $this->periode();

        // Sans le droit de préparer la paie.
        $lecteur = $this->makeUserWithPermissions($this->org, ['comptabilite.read', 'rh-paie.validate']);
        $lecteur->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);
        $this->reprendre($periode, $transmission, $lecteur)->assertForbidden();

        // Droit présent, mais une seule agence : la version couvre toute l'organisation.
        Role::firstOrCreate(['name' => 'comptable', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'rh-paie.update', 'guard_name' => 'web']);
        $agence = User::factory()->create(['organization_id' => $this->org->id]);
        $agence->assignRole('comptable');
        $agence->givePermissionTo(['comptabilite.read', 'rh-paie.update']);
        $agence->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);
        $this->reprendre($periode, $transmission, $agence)->assertForbidden();
        $this->actingAs($agence)->get('/backoffice/comptabilite/salaires?mois=11&annee=2026')
            ->assertInertia(fn (Assert $page) => $page->where('can_reprendre', false));

        // Autre organisation, administrateur ou super administrateur.
        $autreOrg = Organization::factory()->create();
        Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Ailleurs', 'type' => 'depot']);
        $autreAdmin = $this->makeUserWithPermissions($autreOrg, ['comptabilite.read', 'rh-paie.update']);
        $this->reprendre($periode, $transmission, $autreAdmin)->assertForbidden();
        $this->reprendre($periode, $transmission, $this->acteurPaie($autreOrg))->assertForbidden();

        // Version d'une autre organisation sur sa propre période.
        $this->salarie('ZULU', 1000000, '2025-01-01', $autreOrg);
        $etrangere = $this->transmettreLeMois($autreOrg, 11, 2026);
        $this->reprendre($periode, $etrangere)->assertSessionHasErrors('reprise');

        $this->assertSame(0, PaieLigne::count());
        $this->assertNull($transmission->fresh()->prise_en_charge_le);
        $this->assertNull($etrangere->fresh()->prise_en_charge_le);
    }

    // ── Retenues, avantages, paiement ─────────────────────────────────────────

    public function test_les_retenues_de_la_comptabilite_sont_deduites_et_les_avantages_restent_hors_net(): void
    {
        $this->scenarioDuGuide();
        $this->avance($this->a, 200000, '2026-11-10');
        $transmission = $this->transmettreLeMois($this->org, 11, 2026);
        $this->reprendre($this->periode(), $transmission)->assertSessionHasNoErrors();

        $a = $this->ligne($this->a);
        $this->assertSame(3553333.33, (float) $a->brut);
        $this->assertSame(200000.0, (float) $a->total_avances);
        $this->assertSame(3353333.33, (float) $a->net);
        $this->assertSame(150000.0, (float) $a->total_avantages_nature);
    }

    public function test_le_paiement_apres_reprise_et_validation_reste_celui_d_aujourd_hui(): void
    {
        $this->scenarioDuGuide();
        $transmission = $this->transmettreLeMois($this->org, 11, 2026);
        $periode = $this->periode();
        $this->reprendre($periode, $transmission);

        $ligneB = $this->ligne($this->b);
        $this->actingAs($this->user)->post(route('comptabilite.salaires.payer', $ligneB->id), [
            'montant' => 1500000, 'date_paiement' => '2026-11-30', 'mode_paiement' => 'especes',
        ])->assertForbidden();

        $this->valider($periode)->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post(route('comptabilite.salaires.payer', $ligneB->id), [
            'montant' => 1500000, 'date_paiement' => '2026-11-30', 'mode_paiement' => 'especes',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->user)->post(route('comptabilite.salaires.payer', $ligneB->id), [
            'montant' => 1, 'date_paiement' => '2026-11-30', 'mode_paiement' => 'especes',
        ])->assertSessionHasErrors('montant');

        $this->assertSame(1500000.0, (float) PaiePaiement::sole()->montant);
        $this->assertSame(0.0, (float) $ligneB->fresh()->reste_a_payer);
        $this->assertDatabaseHas('compta_pieces', ['source_id' => PaiePaiement::sole()->id]);
    }

    // ── Dépenses validées après la validation de la paie ──────────────────────

    public function test_une_depense_validee_apres_la_paie_est_regularisee_le_mois_suivant(): void
    {
        $this->scenarioDuGuide();
        $novembre = $this->periode();
        $this->reprendre($novembre, $this->transmettreLeMois($this->org, 11, 2026));
        $tardive = $this->avance($this->a, 50000, '2026-11-20', StatutDepense::SOUMIS);
        $this->valider($novembre)->assertSessionHasNoErrors();
        $netNovembre = (float) $this->ligne($this->a, $novembre)->net;

        $tardive->update(['statut' => StatutDepense::VALIDE->value]);

        $this->assertSame($netNovembre, (float) $this->ligne($this->a, $novembre)->net, 'une paie validée ne change plus');
        $this->assertSame(0, DB::table('paie_variables')->where('depense_id', $tardive->id)->count());

        $decembre = $this->periode(12);
        $this->reprendre($decembre, $this->transmettreLeMois($this->org, 12, 2026))->assertSessionHasNoErrors();

        $a = $this->ligne($this->a, $decembre);
        $this->assertSame(50000.0, (float) $a->total_avances);
        $this->assertSame('Avance sur salaire (régularisation 11/2026)', $a->variables()->sole()->libelle);
        // Retenue une seule fois : décembre recalculé, novembre toujours sans.
        app(PaieCalculService::class)->calculerPeriode($decembre->fresh());
        $this->assertSame(1, DB::table('paie_variables')->where('depense_id', $tardive->id)->count());
    }

    // ── Prévision de trésorerie ───────────────────────────────────────────────

    public function test_la_prevision_estime_par_les_rh_puis_lit_la_paie_reprise(): void
    {
        $this->scenarioDuGuide();
        $contributeur = app(SalaireObligationContributor::class);

        $besoin = [];
        $contributeur->collecter($this->org->id, 2026, 11, $besoin);
        $this->assertSame(5053333.33, round($besoin[$this->site->id]['salaires'], 2), 'estimation RH avant toute transmission');
        $this->assertSame(0, PaiePeriode::count(), 'la prévision n\'écrit rien');

        $this->avance($this->a, 200000, '2026-11-10');
        $this->reprendre($this->periode(), $this->transmettreLeMois($this->org, 11, 2026));

        $besoin = [];
        $contributeur->collecter($this->org->id, 2026, 11, $besoin);
        $this->assertSame(4853333.33, round($besoin[$this->site->id]['salaires'], 2), 'net repris, retenue déduite');

        $detail = $contributeur->detail($this->org->id, 2026, 11, $this->site->id)['salaires'];
        $this->assertCount(2, $detail);
    }
}
