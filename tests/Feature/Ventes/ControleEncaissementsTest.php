<?php

namespace Tests\Feature\Ventes;

use App\Enums\AuditEvent;
use App\Enums\GraviteAnomalieEncaissement;
use App\Enums\StatutAnomalieEncaissement;
use App\Enums\TypeAnomalieEncaissement;
use App\Models\AnomalieEncaissement;
use App\Models\AnomalieEncaissementNotification as Envoi;
use App\Models\AuditLog;
use App\Models\CommandeVente;
use App\Models\ControleEncaissements;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use App\Models\Vehicule;
use App\Notifications\AnomalieEncaissementNotification;
use App\Services\Ventes\ControleEncaissements\AlertesAnomaliesEncaissement;
use App\Services\Ventes\ControleEncaissements\SurveillanceEncaissements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\TestCase;

/**
 * Contrôle des encaissements (ADR 0036). Scénario de référence : VTE-221026-080 — facture de
 * 450 000 GNF, deux encaissements espèces de 450 000 GNF à quelques secondes d'intervalle.
 */
class ControleEncaissementsTest extends TestCase
{
    use HasCaissesDediees, RefreshDatabase;

    private Organization $org;

    private Site $site;

    private User $agent;

    private User $superAdmin;

    private User $adminSuperieur;

    private User $assistante;

    private User $sansDroit;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['encaissements.surveiller', 'encaissements.traiter_anomalies', 'factures.encaisser', 'ventes.annuler_exceptionnel'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'commerciale', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'administrateur_superieur', 'guard_name' => 'web'])
            ->givePermissionTo(['encaissements.surveiller', 'encaissements.traiter_anomalies']);
        Role::firstOrCreate(['name' => 'assistante_direction', 'guard_name' => 'web'])
            ->givePermissionTo('encaissements.surveiller');

        $this->org = Organization::factory()->create();
        $this->site = $this->creerSite($this->org, 'Cba');

        $this->agent = $this->utilisateur('Diamo Mariam', 'TRAORE');
        $this->agent->assignRole('commerciale')->givePermissionTo('factures.encaisser');
        $this->superAdmin = $this->utilisateur('Super', 'ADMIN')->assignRole('super_admin');
        $this->adminSuperieur = $this->utilisateur('Admin', 'SUPERIEUR')->assignRole('administrateur_superieur');
        $this->assistante = $this->utilisateur('Assistante', 'DIRECTION')->assignRole('assistante_direction');
        $this->sansDroit = $this->utilisateur('Simple', 'EMPLOYE');

        $this->creerCaisseActivePour($this->agent, $this->site->id);
    }

    // ── Détection ────────────────────────────────────────────────────────────

    public function test_vte_221026_080_detecte_au_premier_scan_puis_jamais_recree(): void
    {
        $facture = $this->facture(450_000, 'VTE-221026-080');
        $this->encaisser($facture, 450_000, '11:18:02');
        $this->encaisser($facture, 450_000, '11:18:09');
        $this->oublierLeControleImmediat();

        $this->artisan('encaissements:surveiller', ['--source' => 'planificateur'])->assertSuccessful();

        $anomalie = AnomalieEncaissement::sole();
        $this->assertSame(TypeAnomalieEncaissement::DOUBLE_ENCAISSEMENT, $anomalie->type);
        $this->assertSame(GraviteAnomalieEncaissement::CRITIQUE, $anomalie->gravite);
        $this->assertSame(StatutAnomalieEncaissement::NOUVELLE, $anomalie->statut);
        $this->assertEquals(450_000, (float) $anomalie->montant_facture);
        $this->assertEquals(900_000, (float) $anomalie->montant_encaisse_net);
        $this->assertEquals(450_000, (float) $anomalie->surplus);
        $this->assertSame('VTE-221026-080', $anomalie->details['commande_reference']);
        $this->assertCount(2, $anomalie->details['encaissements']);
        $notificationsApresPremierScan = Envoi::count();
        $this->assertGreaterThan(0, $notificationsApresPremierScan);

        $this->artisan('encaissements:surveiller', ['--source' => 'planificateur'])->assertSuccessful();
        $this->artisan('encaissements:surveiller', ['--source' => 'planificateur'])->assertSuccessful();

        $this->assertSame(1, AnomalieEncaissement::count());
        $this->assertSame($notificationsApresPremierScan, Envoi::count());
        $this->assertSame(1, AuditLog::where('auditable_id', $anomalie->id)->where('event_code', AuditEvent::ANOMALIE_DETECTEE->value)->count());
        $this->assertSame(3, ControleEncaissements::where('organization_id', $this->org->id)->where('declencheur', ControleEncaissements::PLANIFICATEUR)->count());
    }

    public function test_le_controle_immediat_detecte_le_doublon_des_l_encaissement(): void
    {
        $facture = $this->facture(450_000);
        $this->encaisser($facture, 450_000, '11:18:02');
        $this->assertSame(0, AnomalieEncaissement::count());

        $this->encaisser($facture, 450_000, '11:18:09');

        $anomalie = AnomalieEncaissement::sole();
        $this->assertSame(TypeAnomalieEncaissement::DOUBLE_ENCAISSEMENT, $anomalie->type);
        $this->assertTrue($this->agent->fresh()->unreadNotifications()->where('type', AnomalieEncaissementNotification::class)->exists());
    }

    public function test_paiements_partiels_legitimes_ne_declenchent_aucune_alerte(): void
    {
        $facture = $this->facture(900_000);
        $this->encaisser($facture, 400_000, '09:00:00');
        $this->encaisser($facture, 500_000, '09:00:20');

        // Même montant, même agent, mais à 30 minutes d'intervalle : deux paiements réels.
        $autre = $this->facture(900_000);
        $this->encaisser($autre, 450_000, '10:00:00');
        $this->encaisser($autre, 450_000, '10:30:00');

        // Même montant au même moment, mais par deux agents différents.
        $collegue = $this->utilisateur('Autre', 'AGENT');
        $this->creerCaisseActivePour($collegue, $this->site->id);
        $troisieme = $this->facture(900_000);
        $this->encaisser($troisieme, 450_000, '11:00:00');
        $this->encaisser($troisieme, 450_000, '11:00:05', $collegue);

        $this->artisan('encaissements:surveiller')->assertSuccessful();

        $this->assertSame(0, AnomalieEncaissement::count());
    }

    public function test_doublon_suspect_sans_surplus_est_une_simple_suspicion(): void
    {
        $facture = $this->facture(900_000);
        $this->encaisser($facture, 450_000, '11:18:02');
        $this->encaisser($facture, 450_000, '11:18:09');

        $anomalie = AnomalieEncaissement::sole();
        $this->assertSame(TypeAnomalieEncaissement::DOUBLON_SUSPECT, $anomalie->type);
        $this->assertSame(GraviteAnomalieEncaissement::ATTENTION, $anomalie->gravite);
        $this->assertFalse($anomalie->type->estCertaine());
        $this->assertEquals(0, (float) $anomalie->surplus);
    }

    public function test_surplus_sans_doublon_et_remboursement_pris_en_compte(): void
    {
        $facture = $this->facture(450_000);
        $this->encaisser($facture, 300_000, '09:00:00');
        $this->encaisser($facture, 250_000, '15:00:00');

        $anomalie = AnomalieEncaissement::sole();
        $this->assertSame(TypeAnomalieEncaissement::SURPLUS_ENCAISSE, $anomalie->type);
        $this->assertEquals(100_000, (float) $anomalie->surplus);

        // Un remboursement valide ramène l'encaissé net au facturé : l'écart disparaît.
        $facture->update(['montant_rembourse' => 100_000]);
        app(SurveillanceEncaissements::class)->controlerFacture($facture->id, 'Remboursement', [], false);

        $this->assertNotNull($anomalie->fresh()->ecart_disparu_le);
        $this->assertSame(StatutAnomalieEncaissement::NOUVELLE, $anomalie->fresh()->statut);
    }

    public function test_trop_percu_de_precommande_est_signale_en_attention(): void
    {
        $facture = $this->facture(450_000, null, true);
        $this->encaisser($facture, 300_000, '09:00:00', null, ['est_acompte' => true]);
        $this->encaisser($facture, 250_000, '15:00:00', null, ['est_acompte' => true]);

        $anomalie = AnomalieEncaissement::sole();
        $this->assertSame(TypeAnomalieEncaissement::TROP_PERCU_PRECOMMANDE, $anomalie->type);
        $this->assertSame(GraviteAnomalieEncaissement::ATTENTION, $anomalie->gravite);
    }

    public function test_reference_mobile_money_reutilisee_dans_l_historique(): void
    {
        $support = $this->creerCompteMobileMoneyActifPour($this->agent, $this->site->id);
        $a = $this->encaisser($this->facture(100_000), 100_000, '09:00:00', null, ['mode_paiement' => 'mobile_money', 'compte_tresorerie_id' => $support->id, 'reference_paiement' => 'OM-123']);
        $b = $this->encaisser($this->facture(100_000), 100_000, '10:00:00', null, ['mode_paiement' => 'mobile_money', 'compte_tresorerie_id' => $support->id, 'reference_paiement' => 'OM-999']);
        // Doublon historique, antérieur à l'index unique (ADR 0014) : conservé sans clé.
        DB::table('encaissements_ventes')->where('id', $b->id)->update(['reference_paiement' => 'om-123 ', 'cle_reference_mobile_money' => null]);

        $this->artisan('encaissements:surveiller')->assertSuccessful();

        $anomalie = AnomalieEncaissement::where('type', TypeAnomalieEncaissement::REFERENCE_MOBILE_MONEY_REUTILISEE->value)->sole();
        $this->assertSame(GraviteAnomalieEncaissement::INFORMATION, $anomalie->gravite);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $anomalie->details['suspects']);
        // Information : dans l'application seulement, jamais d'e-mail.
        $this->assertSame(0, Envoi::where('anomalie_encaissement_id', $anomalie->id)->where('canal', Envoi::CANAL_EMAIL)->count());
    }

    public function test_incoherences_comptables_ecriture_orpheline_et_encaissement_sans_ecriture(): void
    {
        $facture = $this->facture(900_000);
        $orphelin = $this->encaisser($facture, 100_000, '09:00:00');
        $sansEcriture = $this->encaisser($facture, 200_000, '10:00:00');

        // Suppression hors application (SQL direct) : la pièce reste validée en trésorerie.
        DB::table('encaissements_ventes')->where('id', $orphelin->id)->delete();
        // Pièce de l'autre encaissement perdue.
        $piece = DB::table('compta_pieces')->where('source_id', $sansEcriture->id)->value('id');
        DB::table('compta_ecritures')->where('piece_comptable_id', $piece)->delete();
        DB::table('compta_pieces')->where('id', $piece)->delete();

        $this->artisan('encaissements:surveiller')->assertSuccessful();

        $types = AnomalieEncaissement::where('type', TypeAnomalieEncaissement::INCOHERENCE_COMPTABLE->value)->pluck('cle')->all();
        $this->assertEqualsCanonicalizing([
            'compta:encaissement:'.$sansEcriture->id,
            'compta:piece:'.DB::table('compta_pieces')->where('source_id', $orphelin->id)->value('id'),
        ], $types);
    }

    // ── Notifications ────────────────────────────────────────────────────────

    public function test_les_quatre_categories_de_destinataires_sont_notifiees_et_seulement_elles(): void
    {
        $autreOrg = Organization::factory()->create();
        $autreSite = $this->creerSite($autreOrg, 'Ailleurs');
        $etranger = User::factory()->create(['organization_id' => $autreOrg->id]);
        $etranger->sites()->attach($autreSite->id, ['role' => 'employe', 'is_default' => true]);
        $etranger->assignRole('administrateur_superieur');

        $this->doublonVte080();

        $destinataires = Envoi::where('canal', Envoi::CANAL_APPLICATION)->pluck('user_id')->all();
        $this->assertEqualsCanonicalizing(
            [$this->agent->id, $this->superAdmin->id, $this->adminSuperieur->id, $this->assistante->id],
            $destinataires,
        );
        foreach ([$this->agent, $this->superAdmin, $this->adminSuperieur, $this->assistante] as $u) {
            $this->assertSame(1, $u->notifications()->where('type', AnomalieEncaissementNotification::class)->count());
        }
        $this->assertSame(0, $this->sansDroit->notifications()->count());
        $this->assertSame(0, $etranger->notifications()->count());

        // E-mails : en attente puis envoyés, un par destinataire.
        $this->assertSame(4, Envoi::where('canal', Envoi::CANAL_EMAIL)->where('statut', Envoi::EN_ATTENTE)->count());
        $bilan = app(AlertesAnomaliesEncaissement::class)->envoyerEnAttente();
        $this->assertSame(['envoyes' => 4, 'echecs' => 0], $bilan);
        $this->assertSame(4, Envoi::where('canal', Envoi::CANAL_EMAIL)->where('statut', Envoi::ENVOYEE)->count());
    }

    public function test_un_responsable_limite_a_une_autre_agence_n_est_ni_notifie_ni_autorise(): void
    {
        $autreSite = $this->creerSite($this->org, 'Kindia');
        $responsableKindia = User::factory()->create(['organization_id' => $this->org->id]);
        $responsableKindia->sites()->attach($autreSite->id, ['role' => 'employe', 'is_default' => true]);
        $responsableKindia->assignRole('assistante_direction');

        $anomalie = $this->doublonVte080();

        $this->assertSame(0, $responsableKindia->notifications()->count());
        $this->actingAs($responsableKindia)->get($this->url($anomalie))->assertForbidden();
    }

    public function test_un_email_en_echec_est_retente_au_controle_suivant(): void
    {
        $this->doublonVte080();
        $canaux = $this->app->make(ChannelManager::class);

        $canaux->extend('mail', fn () => new class
        {
            public function send($notifiable, $notification): void
            {
                throw new \RuntimeException('Daily user sending limit exceeded');
            }
        });
        $canaux->forgetDrivers();

        $bilan = app(AlertesAnomaliesEncaissement::class)->envoyerEnAttente();

        $this->assertSame(['envoyes' => 0, 'echecs' => 4], $bilan);
        $ligne = Envoi::where('canal', Envoi::CANAL_EMAIL)->first();
        $this->assertSame(Envoi::ECHEC, $ligne->statut);
        $this->assertSame(1, $ligne->tentatives);
        $this->assertStringContainsString('Daily user sending limit', $ligne->derniere_erreur);

        $canaux->extend('mail', fn ($app) => $app->make(MailChannel::class));
        $canaux->forgetDrivers();
        $this->artisan('encaissements:surveiller')->assertSuccessful();

        $this->assertSame(4, Envoi::where('canal', Envoi::CANAL_EMAIL)->where('statut', Envoi::ENVOYEE)->count());
        $this->assertSame(2, Envoi::where('canal', Envoi::CANAL_EMAIL)->value('tentatives'));
    }

    public function test_relance_apres_24_heures_aux_responsables_trois_fois_au_plus(): void
    {
        $anomalie = $this->doublonVte080();
        $envoisAgentAvant = Envoi::where('user_id', $this->agent->id)->count();

        foreach (range(1, 5) as $jour) {
            Carbon::setTestNow(now()->addHours(25));
            $this->artisan('encaissements:surveiller')->assertSuccessful();
        }

        $this->assertSame(3, $anomalie->fresh()->nb_relances);
        $this->assertSame($envoisAgentAvant, Envoi::where('user_id', $this->agent->id)->count());
        $this->assertSame(3, Envoi::where('user_id', $this->adminSuperieur->id)->where('canal', Envoi::CANAL_APPLICATION)->where('envoi', 'like', 'relance:%')->count());
    }

    // ── Écran et traitement ──────────────────────────────────────────────────

    public function test_ecran_reserve_a_la_permission_surveiller_et_isole_par_organisation(): void
    {
        $anomalie = $this->doublonVte080();

        $this->actingAs($this->sansDroit)->get('/backoffice/comptabilite/tresorerie/controle-encaissements')->assertForbidden();
        $this->actingAs($this->assistante)->get('/backoffice/comptabilite/tresorerie/controle-encaissements')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Comptabilite/ControleEncaissements/Index')
                ->where('anomalies.total', 1)
                ->where('kpis.nouvelles', 1)
                ->where('kpis.surplus', 450000));

        $autreOrg = Organization::factory()->create();
        $etranger = User::factory()->create(['organization_id' => $autreOrg->id]);
        $etranger->sites()->attach($this->creerSite($autreOrg, 'Ailleurs')->id, ['role' => 'employe', 'is_default' => true]);
        $etranger->assignRole('administrateur_superieur');
        $this->actingAs($etranger)->get($this->url($anomalie))->assertNotFound();
        $this->actingAs($etranger)->post($this->url($anomalie).'/prendre-en-charge')->assertNotFound();
    }

    public function test_la_fiche_ouverte_marque_l_alerte_comme_lue(): void
    {
        $anomalie = $this->doublonVte080();

        $this->actingAs($this->assistante)->get($this->url($anomalie))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Comptabilite/ControleEncaissements/Show')
                ->where('anomalie.type', 'double_encaissement')
                ->has('encaissements', 2)
                ->has('notifications')
                ->where('annulation_possible', false));

        $this->assertSame(0, $this->assistante->fresh()->unreadNotifications()->count());
    }

    public function test_traitement_refuse_sans_permission_dediee(): void
    {
        $anomalie = $this->doublonVte080();

        // L'assistante voit l'anomalie mais n'a pas `encaissements.traiter_anomalies`.
        $this->actingAs($this->assistante)->post($this->url($anomalie).'/prendre-en-charge')->assertForbidden();
        $this->actingAs($this->assistante)->post($this->url($anomalie).'/fausse-alerte', ['commentaire' => 'Pas un doublon'])->assertForbidden();
        $this->actingAs($this->assistante)->post('/backoffice/comptabilite/tresorerie/controle-encaissements/analyser')->assertForbidden();
        // Annuler un encaissement exige `ventes.annuler_exceptionnel` (route existante).
        $this->actingAs($this->adminSuperieur)->delete(route('encaissements.destroy', $anomalie->details['suspects'][1]))->assertForbidden();

        $this->assertSame(StatutAnomalieEncaissement::NOUVELLE, $anomalie->fresh()->statut);
        $this->assertSame(2, EncaissementVente::count());
    }

    public function test_resolution_refusee_tant_que_l_ecart_existe(): void
    {
        $anomalie = $this->doublonVte080();

        $this->actingAs($this->adminSuperieur)->post($this->url($anomalie).'/resoudre', ['commentaire' => 'Vu'])
            ->assertSessionHasErrors('statut');

        $this->assertSame(StatutAnomalieEncaissement::NOUVELLE, $anomalie->fresh()->statut);
    }

    public function test_regularisation_complete_d_un_vrai_doublon(): void
    {
        $anomalie = $this->doublonVte080();
        $this->adminSuperieur->givePermissionTo('ventes.annuler_exceptionnel');
        $doublon = $anomalie->details['suspects'][1];

        // 1. Analyse confirmée : prise en charge (l'annulation n'est proposée qu'ensuite).
        $this->actingAs($this->adminSuperieur)->post($this->url($anomalie).'/prendre-en-charge', ['commentaire' => 'Diamo confirme : un seul paiement de 450 000'])
            ->assertSessionHasNoErrors();
        $this->assertSame(StatutAnomalieEncaissement::EN_COURS, $anomalie->fresh()->statut);
        $this->actingAs($this->adminSuperieur)->get($this->url($anomalie))
            ->assertInertia(fn ($page) => $page->where('annulation_possible', true)
                ->where('encaissements.1.annulable', true)
                ->where('encaissements.1.impact.encaisse_apres', 450000)
                ->where('encaissements.1.impact.statut_apres', 'Payée'));
        // Double clic : la seconde prise en charge est refusée.
        $this->actingAs($this->adminSuperieur)->post($this->url($anomalie).'/prendre-en-charge')->assertSessionHasErrors('statut');

        // 2. Annulation par la procédure existante : contrepassation, recalcul, audit.
        $this->actingAs($this->adminSuperieur)->delete(route('encaissements.destroy', $doublon))->assertSessionHasNoErrors();
        $this->assertNull(EncaissementVente::find($doublon));
        $this->assertSame('contrepassee', DB::table('compta_pieces')->where('source_id', $doublon)->where('type_evenement', 'encaissement_vente_recu')->value('statut'));

        // 3. Le contrôle immédiat constate la disparition de l'écart, avec sa cause.
        $anomalie->refresh();
        $this->assertNotNull($anomalie->ecart_disparu_le);
        $this->assertStringContainsString('Suppression', AuditLog::where('auditable_id', $anomalie->id)
            ->where('event_code', AuditEvent::ANOMALIE_ECART_DISPARU->value)->sole()->meta['cause']);

        // 4. Résolution acceptée, historisée.
        $this->actingAs($this->adminSuperieur)->post($this->url($anomalie).'/resoudre', ['commentaire' => 'Doublon annulé'])
            ->assertSessionHasNoErrors();
        $this->assertSame(StatutAnomalieEncaissement::RESOLUE, $anomalie->fresh()->statut);
        $this->assertSame($this->adminSuperieur->id, $anomalie->fresh()->cloture_par);
        $this->assertSame(2, AuditLog::where('auditable_id', $anomalie->id)->where('event_code', AuditEvent::STATUS_CHANGED->value)->count());

        // Un nouveau contrôle ne rouvre rien.
        $this->artisan('encaissements:surveiller')->assertSuccessful();
        $this->assertSame(StatutAnomalieEncaissement::RESOLUE, $anomalie->fresh()->statut);
    }

    public function test_fausse_alerte_exige_un_motif_et_ne_renotifie_plus(): void
    {
        $anomalie = $this->doublonVte080();

        $this->actingAs($this->adminSuperieur)->post($this->url($anomalie).'/fausse-alerte', ['commentaire' => ''])
            ->assertSessionHasErrors('commentaire');
        $this->actingAs($this->adminSuperieur)->post($this->url($anomalie).'/fausse-alerte', ['commentaire' => 'Deux livraisons distinctes, deux paiements réels'])
            ->assertSessionHasNoErrors();

        $envois = Envoi::count();
        Carbon::setTestNow(now()->addDays(3));
        $this->artisan('encaissements:surveiller')->assertSuccessful();

        $this->assertSame(StatutAnomalieEncaissement::FAUSSE_ALERTE, $anomalie->fresh()->statut);
        $this->assertSame($envois, Envoi::count());
    }

    public function test_une_anomalie_resolue_dont_l_ecart_revient_est_rouverte_et_realertee(): void
    {
        $anomalie = $this->doublonVte080();
        $anomalie->update(['statut' => StatutAnomalieEncaissement::RESOLUE, 'ecart_disparu_le' => now()]);

        $this->artisan('encaissements:surveiller')->assertSuccessful();

        $anomalie->refresh();
        $this->assertSame(StatutAnomalieEncaissement::NOUVELLE, $anomalie->statut);
        $this->assertSame(2, $anomalie->occurrence);
        $this->assertTrue(Envoi::where('envoi', 'detection:2')->where('user_id', $this->superAdmin->id)->exists());
    }

    public function test_analyser_maintenant_lance_un_controle_complet(): void
    {
        $facture = $this->facture(450_000);
        $this->encaisser($facture, 450_000, '11:18:02');
        $this->encaisser($facture, 450_000, '11:18:09');
        $this->oublierLeControleImmediat();

        $this->actingAs($this->adminSuperieur)->post('/backoffice/comptabilite/tresorerie/controle-encaissements/analyser')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame(1, AnomalieEncaissement::count());
        $this->assertSame(1, ControleEncaissements::where('declencheur', ControleEncaissements::MANUEL)->where('user_id', $this->adminSuperieur->id)->count());
    }

    public function test_l_ecran_signale_un_controle_planifie_absent(): void
    {
        $this->actingAs($this->assistante)->get('/backoffice/comptabilite/tresorerie/controle-encaissements')
            ->assertInertia(fn ($page) => $page->where('controle.planificateur_en_retard', true));

        $this->artisan('encaissements:surveiller', ['--source' => 'planificateur'])->assertSuccessful();

        $this->actingAs($this->assistante)->get('/backoffice/comptabilite/tresorerie/controle-encaissements')
            ->assertInertia(fn ($page) => $page->where('controle.planificateur_en_retard', false));
    }

    public function test_un_echec_du_controle_ne_bloque_jamais_l_encaissement(): void
    {
        $this->app->bind(SurveillanceEncaissements::class, fn () => throw new \RuntimeException('contrôle indisponible'));

        $facture = $this->facture(450_000);
        $this->actingAs($this->agent)->post(route('encaissements.store', $facture), [
            'montant' => 450_000,
            'date_encaissement' => now()->toDateString(),
            'mode_paiement' => 'especes',
        ])->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, EncaissementVente::count());
    }

    // ── Outils ───────────────────────────────────────────────────────────────

    private function doublonVte080(): AnomalieEncaissement
    {
        $facture = $this->facture(450_000, 'VTE-221026-080');
        $this->encaisser($facture, 450_000, '11:18:02');
        $this->encaisser($facture, 450_000, '11:18:09');

        return AnomalieEncaissement::sole();
    }

    /** Simule des données antérieures au déploiement : aucune trace du contrôle immédiat. */
    private function oublierLeControleImmediat(): void
    {
        Envoi::query()->delete();
        AuditLog::where('auditable_type', (new AnomalieEncaissement)->getMorphClass())->delete();
        AnomalieEncaissement::query()->delete();
        DB::table('notifications')->delete();
    }

    private function url(AnomalieEncaissement $a): string
    {
        return '/backoffice/comptabilite/tresorerie/controle-encaissements/'.$a->id;
    }

    private function creerSite(Organization $org, string $nom): Site
    {
        return Site::create(['organization_id' => $org->id, 'nom' => $nom, 'type' => 'depot', 'localisation' => 'Conakry']);
    }

    private function utilisateur(string $prenom, string $nom): User
    {
        $user = User::factory()->create(['organization_id' => $this->org->id, 'prenom' => $prenom, 'nom' => $nom]);
        $user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function facture(float $montant, ?string $reference = null, bool $precommande = false): FactureVente
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id, 'proprietaire_id' => $proprietaire->id]);
        $commande = CommandeVente::factory()->create(array_filter([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'total_commande' => $montant,
            'reference' => $reference,
            'est_precommande' => $precommande ?: null,
        ], fn ($v) => $v !== null));

        return FactureVente::factory()->create([
            'organization_id' => $this->org->id,
            'commande_vente_id' => $commande->id,
            'montant_net' => $montant,
            'site_id' => $this->site->id,
        ]);
    }

    /** Encaissement enregistré directement (comme les données de production d'avant le correctif). */
    private function encaisser(FactureVente $facture, float $montant, string $heure, ?User $par = null, array $extra = []): EncaissementVente
    {
        Carbon::setTestNow(Carbon::parse('2026-10-22 '.$heure));
        $this->actingAs($par ?? $this->agent);

        return $facture->encaissements()->create([
            'montant' => $montant,
            'date_encaissement' => '2026-10-22',
            'mode_paiement' => 'especes',
            ...$extra,
        ]);
    }
}
