<?php

namespace Tests\Feature;

use App\Contracts\Rh\SourceDonneesPaie;
use App\Enums\StatutAffectationRemuneration;
use App\Enums\StatutTransmissionPaie;
use App\Enums\StatutTypeRemuneration;
use App\Models\AffectationRemuneration;
use App\Models\Contrat;
use App\Models\Employe;
use App\Models\Organization;
use App\Models\PaiePeriode;
use App\Models\SaisieRemuneration;
use App\Models\Site;
use App\Models\TransmissionPaie;
use App\Models\TypeRemuneration;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Rh\Paie\CalculElementsPaieService;
use App\Services\Rh\Paie\TransmissionPaieService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\Feature\Concerns\SimuleUnePanneDuJournal;
use Tests\TestCase;

/**
 * Transmission mensuelle RH → Comptabilité (docs/transmission-paie.md, ADR 0040) : calcul par
 * partie du mois depuis les contrats et les affectations validées, anomalies bloquantes, gel et
 * empreinte, versions et corrections, transmission unique, droits séparés, cloisonnement.
 *
 * Novembre 2026 : 30 jours.
 */
class TransmissionPaieTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase, SimuleUnePanneDuJournal;

    private const RH = ['rh-transmissions-paie.read', 'rh-transmissions-paie.preparer'];

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-12-02 09:00:00');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['rh-transmissions-paie.valider', 'rh-transmissions-paie.transmettre'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $this->initOrgAndUser(self::RH);
        $this->site = Site::where('organization_id', $this->org->id)->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Aides ─────────────────────────────────────────────────────────────────

    private function superAdmin(?Organization $org = null): User
    {
        $org ??= $this->org;
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole('super_admin');
        $site = Site::where('organization_id', $org->id)->first()
            ?? Site::create(['organization_id' => $org->id, 'nom' => 'Autre', 'type' => 'depot']);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function managerAgence(Site $site, array $permissions): User
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $user = User::factory()->create(['organization_id' => $site->organization_id]);
        $user->assignRole('manager');
        $user->givePermissionTo($permissions);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function salarie(float $salaire = 3000000, array $contrat = [], array $employe = [], ?Site $site = null): Employe
    {
        $e = Employe::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => ($site ?? $this->site)->id,
            'date_embauche' => '2025-01-01',
            ...$employe,
        ]);
        Contrat::create([
            'organization_id' => $this->org->id,
            'employe_id' => $e->id,
            'type_contrat' => 'cdi',
            'date_debut' => '2025-01-01',
            'salaire_base' => $salaire,
            'statut_contrat' => 'actif',
            ...$contrat,
        ]);

        return $e;
    }

    private function type(array $attributs = []): TypeRemuneration
    {
        static $n = 0;
        $n++;

        return TypeRemuneration::create([
            'organization_id' => $this->org->id,
            'code' => 'T'.$n,
            'libelle' => 'Type '.$n,
            'categorie' => 'prime',
            'mode_calcul' => 'montant_fixe',
            'periodicite' => 'mensuelle',
            'nature_montant' => 'fixe',
            'proratisable' => true,
            'statut' => StatutTypeRemuneration::ACTIF->value,
            ...$attributs,
        ]);
    }

    private function affectation(Employe $e, TypeRemuneration $t, array $attributs = []): AffectationRemuneration
    {
        return AffectationRemuneration::create([
            'organization_id' => $this->org->id,
            'employe_id' => $e->id,
            'type_remuneration_id' => $t->id,
            'date_debut' => '2026-01-01',
            'montant' => 500000,
            'statut' => StatutAffectationRemuneration::VALIDEE->value,
            ...$attributs,
        ]);
    }

    /** @return array<string, mixed> */
    private function calcul(int $mois = 11): array
    {
        return app(CalculElementsPaieService::class)->calculer($this->org->id, 2026, $mois);
    }

    /** @param  array<string, mixed>  $resultat */
    private function lignesDe(array $resultat, Employe $e): array
    {
        return array_values(array_filter($resultat['lignes'], fn ($l) => $l['employe_id'] === $e->id));
    }

    private function preparer(?User $user = null, array $donnees = [])
    {
        return $this->actingAs($user ?? $this->user)
            ->from(route('transmissions-paie.index'))
            ->post(route('transmissions-paie.preparer'), ['annee' => 2026, 'mois' => 11, ...$donnees]);
    }

    private function brouillon(): TransmissionPaie
    {
        $this->preparer()->assertSessionHasNoErrors();

        return TransmissionPaie::where('statut', 'brouillon')->sole();
    }

    // ── Calcul ────────────────────────────────────────────────────────────────

    public function test_salaire_de_base_et_prime_proratises_selon_la_presence(): void
    {
        $plein = $this->salarie(3000000);
        $arrive = $this->salarie(3000000, ['date_debut' => '2026-11-16']);
        $prime = $this->type();
        $this->affectation($plein, $prime);
        $this->affectation($arrive, $prime, ['date_debut' => '2026-11-16', 'montant' => 300000]);

        $r = $this->calcul();

        $lignesPlein = $this->lignesDe($r, $plein);
        $this->assertCount(2, $lignesPlein);
        $this->assertSame('contrat', $lignesPlein[0]['origine']);
        $this->assertSame(3000000.0, $lignesPlein[0]['montant']);
        $this->assertSame(500000.0, $lignesPlein[1]['montant']);

        $lignesArrive = $this->lignesDe($r, $arrive);
        $this->assertSame(1500000.0, $lignesArrive[0]['montant']);
        $this->assertSame(15, $lignesArrive[0]['jours']);
        $this->assertSame(150000.0, $lignesArrive[1]['montant']);
        $this->assertSame('300 000 GNF × 15/30 jours', $lignesArrive[1]['detail_calcul']);
        $this->assertSame(5150000.0, $r['total_verse']);
        $this->assertSame([], $r['anomalies']);
    }

    public function test_un_salarie_sans_prime_ne_transmet_que_son_salaire_de_base(): void
    {
        $seul = $this->salarie(2000000);
        $lignes = $this->lignesDe($this->calcul(), $seul);

        $this->assertCount(1, $lignes);
        $this->assertSame('Salaire de base (contrat CDI)', $lignes[0]['libelle']);
        $this->assertNull($lignes[0]['type_remuneration_id']);
    }

    public function test_un_changement_de_montant_en_cours_de_mois_donne_deux_parties(): void
    {
        $e = $this->salarie();
        $prime = $this->type();
        $ancienne = $this->affectation($e, $prime, ['date_fin' => '2026-11-14', 'montant' => 500000]);
        $this->affectation($e, $prime, ['date_debut' => '2026-11-15', 'montant' => 600000, 'remplace_affectation_id' => $ancienne->id]);

        $primes = array_values(array_filter($this->lignesDe($this->calcul(), $e), fn ($l) => $l['origine'] === 'affectation'));

        $this->assertCount(2, $primes);
        $this->assertSame(['2026-11-01', '2026-11-14'], [$primes[0]['du'], $primes[0]['au']]);
        $this->assertSame(233333.33, $primes[0]['montant']);
        $this->assertSame(['2026-11-15', '2026-11-30'], [$primes[1]['du'], $primes[1]['au']]);
        $this->assertSame(320000.0, $primes[1]['montant']);
    }

    public function test_non_proratisable_montant_entier_et_nouveau_montant_pour_le_mois(): void
    {
        $e = $this->salarie(3000000, ['date_debut' => '2026-11-16']);
        $prime = $this->type(['proratisable' => false]);
        $ancienne = $this->affectation($e, $prime, ['date_fin' => '2026-11-19', 'montant' => 500000]);
        $this->affectation($e, $prime, ['date_debut' => '2026-11-20', 'montant' => 600000, 'remplace_affectation_id' => $ancienne->id]);

        $primes = array_values(array_filter($this->lignesDe($this->calcul(), $e), fn ($l) => $l['origine'] === 'affectation'));

        $this->assertCount(1, $primes);
        $this->assertSame(600000.0, $primes[0]['montant']);
    }

    public function test_pourcentage_du_salaire_de_base_de_la_meme_partie_du_mois(): void
    {
        $e = $this->salarie(2000000, ['date_debut' => '2026-11-16']);
        $this->affectation($e, $this->type(['mode_calcul' => 'pourcentage_salaire_base']), ['montant' => null, 'taux' => 10]);

        $ligne = $this->lignesDe($this->calcul(), $e)[1];

        $this->assertSame(1000000.0, $ligne['base_calcul']);
        $this->assertSame(10.0, $ligne['taux']);
        $this->assertSame(100000.0, $ligne['montant']);
    }

    public function test_avantage_en_nature_valorise_hors_net_a_payer(): void
    {
        $e = $this->salarie(1000000);
        $this->affectation($e, $this->type(['categorie' => 'avantage_nature']), ['montant' => 150000]);

        $r = $this->calcul();

        $this->assertSame('valorise', $this->lignesDe($r, $e)[1]['versement']);
        $this->assertSame(1000000.0, $r['total_verse']);
        $this->assertSame(150000.0, $r['total_valorise']);
    }

    public function test_element_saisi_chaque_mois(): void
    {
        $e = $this->salarie();
        $affectation = $this->affectation($e, $this->type(['nature_montant' => 'variable']), ['montant' => null]);

        $r = $this->calcul();
        $this->assertCount(1, $this->lignesDe($r, $e));
        $this->assertSame('montant_mois_absent', $r['anomalies'][0]['code']);
        $this->assertSame('avertissement', $r['anomalies'][0]['niveau']);

        $saisie = SaisieRemuneration::create([
            'organization_id' => $this->org->id, 'affectation_id' => $affectation->id,
            'annee' => 2026, 'mois' => 11, 'montant' => 80000, 'statut' => 'a_valider',
        ]);
        $this->assertSame('bloquante', $this->calcul()['anomalies'][0]['niveau']);

        $saisie->update(['statut' => 'validee']);
        $r = $this->calcul();
        $this->assertSame([], $r['anomalies']);
        $this->assertSame(80000.0, $this->lignesDe($r, $e)[1]['montant']);
        $this->assertSame($saisie->id, $this->lignesDe($r, $e)[1]['saisie_id']);
    }

    public function test_prime_ponctuelle_seulement_dans_son_mois(): void
    {
        $e = $this->salarie();
        $this->affectation($e, $this->type(['periodicite' => 'ponctuelle']), ['date_debut' => '2026-12-01', 'date_fin' => '2026-12-31', 'montant' => 400000]);

        $this->assertCount(1, $this->lignesDe($this->calcul(11), $e));
        $this->assertSame(400000.0, $this->lignesDe($this->calcul(12), $e)[1]['montant']);
    }

    public function test_un_salarie_sorti_est_proratise_puis_absent(): void
    {
        $e = $this->salarie(3000000, [], ['date_sortie' => '2026-11-10']);
        $this->affectation($e, $this->type(), ['montant' => 300000]);

        $novembre = $this->lignesDe($this->calcul(11), $e);
        $this->assertSame(1000000.0, $novembre[0]['montant']);
        $this->assertSame(100000.0, $novembre[1]['montant']);

        $decembre = $this->calcul(12);
        $this->assertSame([], $this->lignesDe($decembre, $e));
        $this->assertSame('hors_emploi', $decembre['anomalies'][0]['code']);
    }

    public function test_un_type_desactive_ne_produit_plus_rien_apres_sa_date(): void
    {
        $e = $this->salarie();
        // Affectation restée ouverte par erreur : la date de désactivation du type fait foi.
        $this->affectation($e, $this->type(['statut' => 'inactif', 'desactive_au' => '2026-11-16']), ['montant' => 300000]);

        $prime = $this->lignesDe($this->calcul(11), $e)[1];
        $this->assertSame('2026-11-15', $prime['au']);
        $this->assertSame(150000.0, $prime['montant']);
        $this->assertCount(1, $this->lignesDe($this->calcul(12), $e));
    }

    public function test_anomalies_bloquantes(): void
    {
        $prime = $this->type();
        $sansContrat = Employe::factory()->create(['organization_id' => $this->org->id, 'site_id' => $this->site->id]);
        $this->affectation($sansContrat, $prime);
        $proposition = $this->salarie();
        $this->affectation($proposition, $prime, ['statut' => 'a_valider']);
        $finDemandee = $this->salarie();
        $this->affectation($finDemandee, $prime, ['date_fin_demandee' => '2026-11-20']);
        $sansSalaire = $this->salarie(0);

        $codes = collect($this->calcul()['anomalies'])->where('niveau', 'bloquante')->pluck('code')->sort()->values()->all();

        $this->assertSame(['affectation_a_valider', 'contrat_absent', 'fin_a_valider', 'salaire_absent'], $codes);
    }

    // ── Cycle : préparer, valider (figer), transmettre ────────────────────────

    public function test_preparer_valider_et_transmettre_avec_des_droits_separes(): void
    {
        $super = $this->superAdmin();
        $e = $this->salarie();
        $this->affectation($e, $this->type());

        $transmission = $this->brouillon();
        $this->assertSame(1, $transmission->version);
        $this->assertSame(2, $transmission->lignes()->count());
        $this->assertSame('3500000.00', $transmission->total_verse);

        // Préparer ne donne ni la validation ni la transmission, même par requête directe.
        $this->actingAs($this->user)->post(route('transmissions-paie.valider', $transmission))->assertForbidden();
        $this->actingAs($super)->post(route('transmissions-paie.transmettre', $transmission))->assertSessionHasErrors('transmission');

        $this->actingAs($super)->post(route('transmissions-paie.valider', $transmission))->assertSessionHasNoErrors();
        $transmission->refresh();
        $this->assertSame(StatutTransmissionPaie::VALIDEE, $transmission->statut);
        $this->assertSame($super->id, $transmission->validee_par);
        $this->assertTrue(app(TransmissionPaieService::class)->estIntegre($transmission));

        $this->actingAs($this->user)->post(route('transmissions-paie.transmettre', $transmission))->assertForbidden();
        $this->actingAs($super)->post(route('transmissions-paie.transmettre', $transmission))->assertSessionHasNoErrors();
        $this->assertSame(StatutTransmissionPaie::TRANSMISE, $transmission->fresh()->statut);

        $this->actingAs($super)->from(route('transmissions-paie.index'))
            ->post(route('transmissions-paie.transmettre', $transmission))->assertSessionHasErrors('transmission');
        $this->assertSame($transmission->id, app(SourceDonneesPaie::class)->transmissionCourante($this->org->id, 2026, 11)?->id);
    }

    public function test_une_version_validee_ne_se_modifie_ni_ne_se_supprime(): void
    {
        $super = $this->superAdmin();
        $this->salarie();
        $transmission = $this->brouillon();
        $this->actingAs($super)->post(route('transmissions-paie.valider', $transmission));
        $transmission->refresh();

        $ligne = $transmission->lignes()->first();
        try {
            $ligne->update(['montant' => 1]);
            $this->fail('Une ligne figée a été modifiée.');
        } catch (LogicException) {
        }
        try {
            $transmission->update(['total_verse' => 1]);
            $this->fail('Une version figée a été modifiée.');
        } catch (LogicException) {
        }
        try {
            $transmission->delete();
            $this->fail('Une version figée a été supprimée.');
        } catch (LogicException) {
        }
        $this->actingAs($super)->from(route('transmissions-paie.index'))
            ->post(route('transmissions-paie.recalculer', $transmission))->assertSessionHasErrors('transmission');

        $this->assertSame('3000000.00', $ligne->fresh()->montant);
    }

    public function test_une_alteration_directe_en_base_empeche_la_transmission(): void
    {
        $super = $this->superAdmin();
        $this->salarie();
        $transmission = $this->brouillon();
        $this->actingAs($super)->post(route('transmissions-paie.valider', $transmission));

        DB::table('transmission_paie_lignes')->where('transmission_paie_id', $transmission->id)->update(['montant' => 1]);

        $this->assertFalse(app(TransmissionPaieService::class)->estIntegre($transmission->fresh()));
        $this->actingAs($super)->from(route('transmissions-paie.index'))
            ->post(route('transmissions-paie.transmettre', $transmission))->assertSessionHasErrors('transmission');
        $this->assertSame(StatutTransmissionPaie::VALIDEE, $transmission->fresh()->statut);
    }

    public function test_la_validation_est_refusee_si_une_anomalie_bloque_ou_si_les_donnees_ont_change(): void
    {
        $super = $this->superAdmin();
        $e = $this->salarie();
        $proposition = $this->affectation($e, $this->type(), ['statut' => 'a_valider']);
        $transmission = $this->brouillon();
        $this->assertTrue($transmission->aDesAnomaliesBloquantes());

        $valider = fn () => $this->actingAs($super)->from(route('transmissions-paie.index'))->post(route('transmissions-paie.valider', $transmission));
        $valider()->assertSessionHasErrors('transmission');

        // L'anomalie est corrigée mais le brouillon n'a pas été recalculé : refus, rien n'est figé à l'aveugle.
        $proposition->update(['statut' => 'validee']);
        $valider()->assertSessionHasErrors('transmission');
        $this->assertStringContainsString('recalculez', session('errors')->first('transmission'));

        $this->actingAs($this->user)->post(route('transmissions-paie.recalculer', $transmission))->assertSessionHasNoErrors();
        $valider()->assertSessionHasNoErrors();
        $this->assertSame(StatutTransmissionPaie::VALIDEE, $transmission->fresh()->statut);
    }

    // ── Versions et corrections ───────────────────────────────────────────────

    public function test_une_correction_ouvre_une_nouvelle_version_et_conserve_les_precedentes(): void
    {
        $super = $this->superAdmin();
        $e = $this->salarie(3000000);
        $v1 = $this->brouillon();
        $this->actingAs($super)->post(route('transmissions-paie.valider', $v1));
        $this->actingAs($super)->post(route('transmissions-paie.transmettre', $v1));

        // Correction du salaire du contrat après transmission.
        Contrat::where('employe_id', $e->id)->update(['salaire_base' => 3300000]);
        $changements = app(TransmissionPaieService::class)->changementsDepuis($v1->fresh());
        $this->assertCount(1, $changements);
        $this->assertSame(300000.0, $changements[0]['ecart']);

        $this->preparer()->assertSessionHasErrors('motif');
        $this->preparer(null, ['motif' => 'Salaire corrigé'])->assertSessionHasNoErrors();
        $v2 = TransmissionPaie::where('version', 2)->sole();
        $this->assertSame($v1->id, $v2->version_precedente_id);
        $this->assertSame('Salaire corrigé', $v2->motif);
        // Un seul brouillon à la fois.
        $this->preparer(null, ['motif' => 'Encore'])->assertSessionHasErrors('mois');

        $this->actingAs($super)->post(route('transmissions-paie.valider', $v2));
        // Validée mais pas encore transmise : la Comptabilité lit toujours la v1.
        $this->assertSame($v1->id, app(SourceDonneesPaie::class)->transmissionCourante($this->org->id, 2026, 11)?->id);

        $this->actingAs($super)->post(route('transmissions-paie.transmettre', $v2));
        $this->assertSame(StatutTransmissionPaie::REMPLACEE, $v1->fresh()->statut);
        $this->assertSame($v2->id, app(SourceDonneesPaie::class)->transmissionCourante($this->org->id, 2026, 11)?->id);
        $this->assertSame('3000000.00', $v1->fresh()->lignes()->first()->montant);
        $this->assertSame('3300000.00', $v2->fresh()->lignes()->first()->montant);

        $this->actingAs($this->user)->get(route('transmissions-paie.show', $v2))
            ->assertInertia(fn (Assert $page) => $page
                ->component('TransmissionsPaie/Show')
                ->where('transmission.version_precedente', 1)
                ->has('ecarts_precedente', 1)
                ->where('ecarts_precedente.0.avant', 3000000)
                ->where('ecarts_precedente.0.apres', 3300000)
                ->has('versions', 2));
    }

    public function test_un_brouillon_abandonne_garde_son_numero(): void
    {
        $this->salarie();
        $v1 = $this->brouillon();
        $this->actingAs($this->user)->post(route('transmissions-paie.abandonner', $v1))->assertSessionHasNoErrors();
        $this->assertSame(StatutTransmissionPaie::ABANDONNEE, $v1->fresh()->statut);

        $this->preparer()->assertSessionHasNoErrors();
        $this->assertSame(2, TransmissionPaie::where('statut', 'brouillon')->sole()->version);
    }

    public function test_plus_de_nouvelle_version_quand_la_comptabilite_a_valide_le_mois(): void
    {
        PaiePeriode::create(['organization_id' => $this->org->id, 'annee' => 2026, 'mois' => 11, 'statut' => 'valide_rh']);
        $this->salarie();

        $this->preparer()->assertSessionHasErrors('mois');
        $this->assertSame(0, TransmissionPaie::count());
    }

    public function test_une_version_transmise_n_est_reprise_qu_une_fois_par_la_comptabilite(): void
    {
        $super = $this->superAdmin();
        $this->salarie();
        $transmission = $this->brouillon();
        $source = app(SourceDonneesPaie::class);

        try {
            $source->prendreEnCharge($transmission, $super, 'periode-11');
            $this->fail('Un brouillon a été repris.');
        } catch (ValidationException) {
        }

        $this->actingAs($super)->post(route('transmissions-paie.valider', $transmission));
        $this->actingAs($super)->post(route('transmissions-paie.transmettre', $transmission));

        $repris = $source->prendreEnCharge($transmission->fresh(), $super, 'periode-11');
        $this->assertNotNull($repris->prise_en_charge_le);
        $this->assertCount(1, $source->lignes($repris));

        $this->expectException(ValidationException::class);
        $source->prendreEnCharge($transmission->fresh(), $super, 'periode-11-bis');
    }

    public function test_apres_validation_le_mois_est_fige_pour_les_affectations(): void
    {
        $super = $this->superAdmin();
        $e = $this->salarie();
        $transmission = $this->brouillon();
        $this->actingAs($super)->post(route('transmissions-paie.valider', $transmission));

        Permission::firstOrCreate(['name' => 'rh-affectations-remuneration.create', 'guard_name' => 'web']);
        $this->actingAs($super)->from(route('transmissions-paie.index'))
            ->post(route('affectations-remuneration.store'), [
                'employe_id' => $e->id, 'type_remuneration_id' => $this->type()->id, 'date_debut' => '2026-11-15', 'montant' => 1000,
            ])->assertSessionHasErrors('date_debut');
    }

    // ── Droits et cloisonnement ───────────────────────────────────────────────

    public function test_la_consultation_ne_montre_que_les_agences_consultables(): void
    {
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kindia', 'type' => 'depot']);
        $chezMoi = $this->salarie(1000000);
        $this->salarie(2000000, [], [], $autreSite);
        $transmission = $this->brouillon();
        $lecteur = $this->managerAgence($this->site, ['rh-transmissions-paie.read']);

        $this->actingAs($lecteur)->get(route('transmissions-paie.show', $transmission))
            ->assertInertia(fn (Assert $page) => $page
                ->has('salaries', 1)
                ->where('salaries.0.employe_id', $chezMoi->id)
                ->where('totaux_visibles.verse', 1000000)
                ->where('transmission.total_verse_organisation', null));

        $this->actingAs($lecteur)->get(route('transmissions-paie.index'))
            ->assertInertia(fn (Assert $page) => $page->where('transmissions.0.total_verse', null));
        $this->actingAs($this->user)->get(route('transmissions-paie.show', $transmission))
            ->assertInertia(fn (Assert $page) => $page->has('salaries', 2)->where('transmission.total_verse_organisation', 3000000));
    }

    public function test_preparer_exige_d_agir_sur_toutes_les_agences(): void
    {
        $this->salarie();
        $limite = $this->managerAgence($this->site, self::RH);

        $this->preparer($limite)->assertForbidden();
        $this->assertSame(0, TransmissionPaie::count());
    }

    public function test_sans_permission_et_entre_organisations(): void
    {
        $this->salarie();
        $transmission = $this->brouillon();
        $sans = $this->managerAgence($this->site, []);
        $autreOrg = Organization::factory()->create();
        Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Ailleurs', 'type' => 'depot']);
        $superAilleurs = $this->superAdmin($autreOrg);

        $this->actingAs($sans)->get(route('transmissions-paie.index'))->assertForbidden();
        $this->actingAs($sans)->get(route('transmissions-paie.show', $transmission))->assertForbidden();
        $this->actingAs($sans)->post(route('transmissions-paie.preparer'), [])->assertForbidden();

        $this->actingAs($superAilleurs)->get(route('transmissions-paie.show', $transmission))->assertForbidden();
        $this->actingAs($superAilleurs)->post(route('transmissions-paie.valider', $transmission))->assertForbidden();
        $this->actingAs($superAilleurs)->getJson(route('transmissions-paie.historique', $transmission))->assertForbidden();
        $this->assertSame(StatutTransmissionPaie::BROUILLON, $transmission->fresh()->statut);
    }

    public function test_l_historique_de_la_version_retrace_le_cycle(): void
    {
        $super = $this->superAdmin();
        $this->salarie();
        $transmission = $this->brouillon();
        $this->actingAs($super)->post(route('transmissions-paie.valider', $transmission));
        $this->actingAs($super)->post(route('transmissions-paie.transmettre', $transmission));

        $this->actingAs($this->user)->getJson(route('transmissions-paie.historique', $transmission))
            ->assertOk()
            ->assertJsonCount(3)
            ->assertJsonFragment(['event_code' => 'transmitted'])
            ->assertJsonFragment(['module_label' => 'Transmission de paie']);
    }

    // ── Scénario du guide de test, de bout en bout par les écrans ─────────────

    public function test_scenario_du_guide_novembre_de_l_attribution_a_l_ecran_de_transmission(): void
    {
        foreach (['rh-affectations-remuneration.create', 'rh-affectations-remuneration.read'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
            $this->user->givePermissionTo($p);
        }
        $super = $this->superAdmin();
        $a = $this->salarie(3000000, [], ['nom' => 'ALPHA', 'prenom' => 'Ali']);
        $b = $this->salarie(3000000, ['date_debut' => '2026-11-16'], ['nom' => 'BRAVO', 'prenom' => 'Bah']);
        $prime = $this->type(['libelle' => 'Prime de transport', 'code' => 'TRANSPORT']);

        // Attribution par RH, validation par le super administrateur, changement de montant au 15/11.
        $this->actingAs($this->user)->post(route('affectations-remuneration.store'), [
            'employe_id' => $a->id, 'type_remuneration_id' => $prime->id, 'date_debut' => '2026-11-01', 'montant' => 500000,
        ])->assertSessionHasNoErrors();
        $initiale = AffectationRemuneration::sole();
        $this->actingAs($super)->patch(route('affectations-remuneration.valider', $initiale))->assertSessionHasNoErrors();
        $this->actingAs($super)->post(route('affectations-remuneration.changement-montant', $initiale), [
            'date_effet' => '2026-11-15', 'montant' => 600000, 'valider' => true,
        ])->assertSessionHasNoErrors();

        $this->preparer()->assertSessionHasNoErrors();
        $transmission = TransmissionPaie::sole();

        $this->actingAs($this->user)->get(route('transmissions-paie.show', $transmission))
            ->assertInertia(fn (Assert $page) => $page
                ->has('salaries', 2)
                ->where('salaries.0.salarie', $a->fresh()->nom_complet)
                ->where('salaries.0.lignes.0.montant', 3000000)
                ->where('salaries.0.lignes.1.du', '01/11/2026')
                ->where('salaries.0.lignes.1.au', '14/11/2026')
                ->where('salaries.0.lignes.1.montant_reference', 500000)
                ->where('salaries.0.lignes.1.montant', 233333.33)
                ->where('salaries.0.lignes.2.du', '15/11/2026')
                ->where('salaries.0.lignes.2.montant_reference', 600000)
                ->where('salaries.0.lignes.2.montant', 320000)
                ->where('salaries.0.total_verse', 3553333.33)
                ->where('salaries.1.lignes.0.du', '16/11/2026')
                ->where('salaries.1.lignes.0.jours', 15)
                ->where('salaries.1.lignes.0.montant', 1500000)
                ->where('totaux_visibles.verse', 5053333.33)
                ->where('transmission.est_figee', false)
                ->has('anomalies', 0));
    }

    public function test_cdd_et_contrats_termines_ou_rompus_en_cours_de_mois(): void
    {
        $cdd = $this->salarie(3000000, ['type_contrat' => 'cdd', 'date_debut' => '2026-01-01', 'date_fin' => '2026-11-20']);
        $rompu = $this->salarie(3000000, ['statut_contrat' => 'rompu', 'date_fin' => '2026-11-10']);
        $termineAvant = $this->salarie(3000000, ['statut_contrat' => 'termine', 'date_fin' => '2026-10-31']);
        $termineSansDate = $this->salarie(3000000, ['statut_contrat' => 'termine', 'date_fin' => null]);

        $r = $this->calcul();

        $this->assertSame(2000000.0, $this->lignesDe($r, $cdd)[0]['montant']);
        $this->assertSame('Salaire de base (contrat CDD)', $this->lignesDe($r, $cdd)[0]['libelle']);
        $this->assertSame(1000000.0, $this->lignesDe($r, $rompu)[0]['montant']);
        $this->assertSame([], $this->lignesDe($r, $termineAvant));
        // Un contrat terminé sans date de fin ne peut pas être daté : jamais repris.
        $this->assertSame([], $this->lignesDe($r, $termineSansDate));
    }

    // ── Atomicité : une panne en cours d'opération ne laisse rien à moitié ────

    public function test_une_panne_pendant_la_preparation_ne_laisse_aucune_version(): void
    {
        $this->salarie();
        $this->simulerPanneDuJournal();

        try {
            app(TransmissionPaieService::class)->preparer($this->user, 2026, 11);
            $this->fail('La préparation aurait dû échouer.');
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, TransmissionPaie::count());
        $this->assertSame(0, DB::table('transmission_paie_lignes')->count());
    }

    public function test_une_panne_pendant_la_validation_ou_la_reprise_ne_change_rien(): void
    {
        $super = $this->superAdmin();
        $this->salarie();
        $transmission = $this->brouillon();
        $this->simulerPanneDuJournal();

        try {
            app(TransmissionPaieService::class)->valider($transmission, $super);
            $this->fail('La validation aurait dû échouer.');
        } catch (\RuntimeException) {
        }
        $this->assertSame(StatutTransmissionPaie::BROUILLON, $transmission->fresh()->statut);
        $this->assertNull($transmission->fresh()->validee_par);

        // Version transmise (journal rétabli), puis panne pendant la reprise par la Comptabilité.
        $this->app->forgetInstance(AuditLogService::class);
        $this->actingAs($super)->post(route('transmissions-paie.valider', $transmission));
        $this->actingAs($super)->post(route('transmissions-paie.transmettre', $transmission));
        $this->simulerPanneDuJournal();
        try {
            app(SourceDonneesPaie::class)->prendreEnCharge($transmission->fresh(), $super, 'periode-11');
            $this->fail('La reprise aurait dû échouer.');
        } catch (\RuntimeException) {
        }
        $this->assertNull($transmission->fresh()->prise_en_charge_le);
    }
}
