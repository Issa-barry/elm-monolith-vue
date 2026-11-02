<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\AuditEvent;
use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use App\Http\Controllers\Comptabilite\CommissionAjustementController;
use App\Models\AuditLog;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionLogistique;
use App\Models\CommissionLogistiquePart;
use App\Models\CommissionProcessus;
use App\Models\Livreur;
use App\Models\PaiementPeriode;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\Vehicule;
use App\Services\Commission\CommissionProcessusDefaults;
use App\Services\PeriodeCalculatorService;
use App\Services\PeriodeComptableService;
use App\Services\PeriodePaiementService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Validation en masse depuis Comptabilité > Commissions : une sélection de dizaines de livreurs
 * porte des centaines de parts. Le coût d'une requête ne doit pas croître avec leur nombre, sinon
 * le serveur coupe avant la fin (504) — cf. docs/commissions.md.
 */
class CommissionValidationMasseTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private Site $site;

    private CommandeVente $commande;

    private string $processusId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['comptabilite.read', 'comptabilite.payer']);
        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => false]);
        $this->commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
        ]);
        $this->processusId = CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_VENTE)->id;
    }

    /** @return list<CommissionEnveloppePart> */
    private function creerParts(int $nbLivreurs, int $partsParLivreur): array
    {
        $parts = [];
        for ($l = 0; $l < $nbLivreurs; $l++) {
            $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);
            for ($p = 0; $p < $partsParLivreur; $p++) {
                $enveloppe = CommissionEnveloppe::create([
                    'organization_id' => $this->org->id,
                    'source_type' => CommandeVente::class,
                    'source_id' => $this->commande->id,
                    'processus_id' => $this->processusId,
                    'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
                    'cible_id' => (string) Str::ulid(),
                    'montant_total' => 1000,
                    'earned_at' => now(),
                    'statut' => 'creee',
                ]);
                $parts[] = CommissionEnveloppePart::create([
                    'enveloppe_id' => $enveloppe->id,
                    'beneficiaire_type' => CommissionEnveloppePart::TYPE_LIVREUR,
                    'beneficiaire_id' => $livreur->id,
                    'montant_brut' => 1000,
                    'montant_net' => 1000,
                    'montant_verse' => 0,
                    'statut' => 'creee',
                ]);
            }
        }

        return $parts;
    }

    private function periodeLivreurCalculee(): PaiementPeriode
    {
        $periode = app(PeriodePaiementService::class)->getOrCreatePeriod($this->org->id, TypePeriodePaiement::LIVREUR, now(), $this->user->id);
        app(PeriodeCalculatorService::class)->calculer($periode);

        return $periode->refresh();
    }

    /** @param  list<CommissionEnveloppePart>  $parts */
    private function compterRequetesDeValidation(array $parts): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($this->user)
            ->post(route('comptabilite.commissions.ajustements.valider'), [
                'parts' => array_map(fn ($p) => ['type' => 'vente', 'id' => $p->id], $parts),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    /** @test */
    public function le_nombre_de_requetes_ne_croit_pas_avec_le_nombre_de_parts_selectionnees(): void
    {
        $parts = $this->creerParts(30, 20);
        $this->periodeLivreurCalculee();

        // Deux sélections de même nombre de livreurs (donc d'entrées d'audit), de tailles très
        // différentes : une reste volontairement partielle pour que la période ne bascule pas.
        $petite = [];
        $grande = [];
        foreach (array_chunk($parts, 20) as $partsDuLivreur) {
            $petite[] = $partsDuLivreur[0];
            array_push($grande, ...array_slice($partsDuLivreur, 1, 15));
        }

        $requetesPetite = $this->compterRequetesDeValidation($petite);
        $requetesGrande = $this->compterRequetesDeValidation($grande);

        $this->assertLessThan($requetesPetite + 15, $requetesGrande, "30 parts : {$requetesPetite} requêtes ; 450 parts : {$requetesGrande} requêtes");
    }

    /** @test */
    public function valider_quelques_parts_ne_recharge_pas_toutes_les_parts_de_la_periode(): void
    {
        $parts = $this->creerParts(2, 30);
        $this->periodeLivreurCalculee();

        $chargees = 0;
        Event::listen('eloquent.retrieved: '.CommissionEnveloppePart::class, function () use (&$chargees) {
            $chargees++;
        });

        $this->actingAs($this->user)
            ->post(route('comptabilite.commissions.ajustements.valider'), [
                'parts' => [['type' => 'vente', 'id' => $parts[0]->id]],
            ])
            ->assertSessionHas('success');

        // La part demandée + la ligne d'agrégat de la signature de calcul (COUNT/SUM), rien d'autre.
        $this->assertLessThanOrEqual(2, $chargees, 'la période ne doit plus être relue pour savoir si elle est complète');
    }

    /** @test */
    public function les_lots_successifs_valident_tout_et_la_periode_bascule_au_dernier(): void
    {
        $parts = $this->creerParts(3, 2);
        $periode = $this->periodeLivreurCalculee();

        foreach (array_chunk($parts, 4) as $index => $lot) {
            $this->assertSame(StatutPeriodePaiement::CALCULEE, $periode->fresh()->statut, "encore calculée avant le lot {$index}");

            $this->actingAs($this->user)
                ->postJson(route('comptabilite.commissions.ajustements.valider'), [
                    'parts' => array_map(fn ($p) => ['type' => 'vente', 'id' => $p->id], $lot),
                ])
                ->assertOk()
                ->assertExactJson(['validees' => count($lot)]);
        }

        $this->assertSame(StatutPeriodePaiement::VALIDEE, $periode->fresh()->statut);
        $this->assertSame(0, CommissionEnveloppePart::whereNull('validated_at')->count());
        $this->assertSame(0, CommissionEnveloppePart::where('statut', 'creee')->count(), 'parts activées par la validation de la période');
    }

    /** @test */
    public function renvoyer_un_lot_deja_valide_apres_une_interruption_est_sans_effet(): void
    {
        $parts = $this->creerParts(1, 2);
        $this->periodeLivreurCalculee();
        $autreValidateur = $this->makeUserWithPermissions($this->org, []);
        $parts[0]->forceFill(['validated_by' => $autreValidateur->id, 'validated_at' => now()->subHour()])->save();

        $this->actingAs($this->user)
            ->postJson(route('comptabilite.commissions.ajustements.valider'), [
                'parts' => array_map(fn ($p) => ['type' => 'vente', 'id' => $p->id], $parts),
            ])
            ->assertOk()
            ->assertExactJson(['validees' => 1]);

        $this->assertSame($autreValidateur->id, $parts[0]->fresh()->validated_by, 'le premier validateur reste tracé');
        $this->assertSame($this->user->id, $parts[1]->fresh()->validated_by);
    }

    /** @test */
    public function une_validation_ne_touche_pas_updated_at_et_ne_relance_pas_le_calcul(): void
    {
        $parts = $this->creerParts(2, 2);
        $periode = $this->periodeLivreurCalculee();
        CommissionEnveloppePart::query()->toBase()->update(['updated_at' => now()->subDay()]);
        app(PeriodeCalculatorService::class)->calculer($periode->refresh());
        $avant = $parts[0]->fresh()->updated_at;

        $this->actingAs($this->user)
            ->post(route('comptabilite.commissions.ajustements.valider'), [
                'parts' => [['type' => 'vente', 'id' => $parts[0]->id]],
            ])
            ->assertSessionHas('success');

        $this->assertEquals($avant, $parts[0]->fresh()->updated_at);
        $this->assertFalse(app(PeriodeCalculatorService::class)->needsRecalcul($periode->refresh()));
    }

    /** @test */
    public function un_lot_au_dessus_du_plafond_est_refuse(): void
    {
        $this->actingAs($this->user)
            ->postJson(route('comptabilite.commissions.ajustements.valider'), [
                'parts' => array_fill(0, CommissionAjustementController::VALIDATION_LOT_MAX + 1, ['type' => 'vente', 'id' => 'x']),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parts');
    }

    /** @test */
    public function une_part_introuvable_refuse_tout_le_lot(): void
    {
        $parts = $this->creerParts(1, 1);

        $this->actingAs($this->user)
            ->postJson(route('comptabilite.commissions.ajustements.valider'), [
                'parts' => [
                    ['type' => 'vente', 'id' => $parts[0]->id],
                    ['type' => 'vente', 'id' => (string) Str::ulid()],
                ],
            ])
            ->assertNotFound();

        $this->assertNull($parts[0]->fresh()->validated_at);
    }

    /** @test */
    public function une_part_d_une_autre_organisation_refuse_tout_le_lot(): void
    {
        $parts = $this->creerParts(1, 1);

        $this->actingAs($this->makeAdminUser())
            ->postJson(route('comptabilite.commissions.ajustements.valider'), [
                'parts' => [['type' => 'vente', 'id' => $parts[0]->id]],
            ])
            ->assertForbidden();

        $this->assertNull($parts[0]->fresh()->validated_at);
    }

    /** @test */
    public function valider_lot_valide_vente_et_logistique_de_la_periode(): void
    {
        [$vente] = $this->creerParts(1, 1);
        $logistique = $this->creerPartLogistique();
        $periode = $this->periodeLivreurCalculee();

        $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.ajustements.valider-lot', $periode), [
                'parts' => [['type' => 'vente', 'id' => $vente->id], ['type' => 'logistique', 'id' => $logistique->id]],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertNotNull($vente->fresh()->validated_at);
        $this->assertNotNull($logistique->fresh()->validated_at);
    }

    /** @test */
    public function valider_lot_refuse_une_part_d_une_autre_organisation(): void
    {
        [$partAutreOrganisation] = $this->creerParts(1, 1);
        $this->periodeLivreurCalculee();

        $admin = $this->makeAdminUser();
        $periodeDeLAdmin = $this->periodeOuverteAuxAjustements($admin->organization_id, $admin->id);

        $this->actingAs($admin)
            ->postJson(route('comptabilite.periodes.ajustements.valider-lot', $periodeDeLAdmin), [
                'parts' => [['type' => 'vente', 'id' => $partAutreOrganisation->id]],
            ])
            ->assertForbidden();

        $this->assertNull($partAutreOrganisation->fresh()->validated_at);
    }

    /** @test */
    public function valider_lot_refuse_tout_le_lot_si_une_part_n_est_pas_sur_la_periode(): void
    {
        [$partDeLaPeriode, $partHorsPeriode] = $this->creerParts(1, 2);
        $partHorsPeriode->enveloppe->update(['earned_at' => now()->subMonths(2)]);
        $periode = $this->periodeLivreurCalculee();

        $this->actingAs($this->user)
            ->postJson(route('comptabilite.periodes.ajustements.valider-lot', $periode), [
                'parts' => [['type' => 'vente', 'id' => $partDeLaPeriode->id], ['type' => 'vente', 'id' => $partHorsPeriode->id]],
            ])
            ->assertForbidden();

        $this->assertNull($partDeLaPeriode->fresh()->validated_at);
        $this->assertNull($partHorsPeriode->fresh()->validated_at);
    }

    /** @test */
    public function valider_lot_au_dessus_du_plafond_est_refuse(): void
    {
        $periode = $this->periodeOuverteAuxAjustements($this->org->id, $this->user->id);

        $this->actingAs($this->user)
            ->postJson(route('comptabilite.periodes.ajustements.valider-lot', $periode), [
                'parts' => array_fill(0, CommissionAjustementController::VALIDATION_LOT_MAX + 1, ['type' => 'vente', 'id' => 'x']),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('parts');
    }

    /** @test */
    public function un_echec_sur_la_logistique_annule_aussi_la_vente(): void
    {
        [$vente] = $this->creerParts(1, 1);
        $logistique = $this->creerPartLogistique();
        $this->periodeLivreurCalculee();

        $tableLogistique = (new CommissionLogistiquePart)->getTable();
        DB::listen(function ($requete) use ($tableLogistique) {
            if (str_starts_with($requete->sql, 'update') && str_contains($requete->sql, $tableLogistique)) {
                throw new \RuntimeException('Échec simulé sur la logistique');
            }
        });

        $this->actingAs($this->user)
            ->postJson(route('comptabilite.commissions.ajustements.valider'), [
                'parts' => [['type' => 'vente', 'id' => $vente->id], ['type' => 'logistique', 'id' => $logistique->id]],
            ])
            ->assertServerError();

        $this->assertNull($vente->fresh()->validated_at, 'la vente ne reste pas validée seule');
        $this->assertNull($logistique->fresh()->validated_at);
    }

    /** @test */
    public function renvoyer_le_dernier_lot_apres_un_echec_du_traitement_de_periode_valide_la_periode(): void
    {
        $parts = $this->creerParts(1, 2);
        $periode = $this->periodeLivreurCalculee();
        $lot = ['parts' => array_map(fn ($p) => ['type' => 'vente', 'id' => $p->id], $parts)];

        $this->mock(PeriodeCalculatorService::class, fn ($mock) => $mock->shouldReceive('traiterPeriodesPourDates')
            ->once()
            ->andThrow(new \RuntimeException('Coupure simulée après enregistrement')));

        $this->actingAs($this->user)
            ->postJson(route('comptabilite.commissions.ajustements.valider'), $lot)
            ->assertServerError();

        $this->forgetMock(PeriodeCalculatorService::class);
        $this->assertNotNull($parts[0]->fresh()->validated_at, 'les parts restent enregistrées');
        $this->assertSame(StatutPeriodePaiement::CALCULEE, $periode->fresh()->statut);

        $this->actingAs($this->user)
            ->postJson(route('comptabilite.commissions.ajustements.valider'), $lot)
            ->assertOk()
            ->assertExactJson(['validees' => 0]);

        $this->assertSame(StatutPeriodePaiement::VALIDEE, $periode->fresh()->statut);
    }

    /** @test */
    public function le_bouton_de_la_periode_prend_le_relais_apres_un_echec_du_traitement_de_periode(): void
    {
        $parts = $this->creerParts(1, 2);
        $periode = $this->periodeLivreurCalculee();

        $this->mock(PeriodeCalculatorService::class, fn ($mock) => $mock->shouldReceive('traiterPeriodesPourDates')
            ->once()
            ->andThrow(new \RuntimeException('Coupure simulée après enregistrement')));

        $this->actingAs($this->user)
            ->postJson(route('comptabilite.commissions.ajustements.valider'), [
                'parts' => array_map(fn ($p) => ['type' => 'vente', 'id' => $p->id], $parts),
            ])
            ->assertServerError();

        $this->forgetMock(PeriodeCalculatorService::class);
        $this->assertSame(StatutPeriodePaiement::CALCULEE, $periode->fresh()->statut);

        $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.valider', $periode))
            ->assertSessionHasNoErrors();

        $this->assertSame(StatutPeriodePaiement::VALIDEE, $periode->fresh()->statut);
    }

    /** @test */
    public function renvoyer_un_lot_deja_valide_n_ajoute_rien_a_l_historique(): void
    {
        $parts = $this->creerParts(1, 2);
        $this->periodeLivreurCalculee();
        $lot = ['parts' => array_map(fn ($p) => ['type' => 'vente', 'id' => $p->id], $parts)];

        $this->actingAs($this->user)->postJson(route('comptabilite.commissions.ajustements.valider'), $lot)->assertOk();
        $entrees = $this->entreesDHistoriqueValidation();
        $this->assertSame(1, $entrees->count(), $entrees->implode('description', ' | '));

        $this->actingAs($this->user)->postJson(route('comptabilite.commissions.ajustements.valider'), $lot)->assertOk();

        $this->assertCount(1, $this->entreesDHistoriqueValidation());
    }

    /** @return Collection<int, AuditLog> */
    private function entreesDHistoriqueValidation(): Collection
    {
        return AuditLog::where('auditable_type', (new Livreur)->getMorphClass())
            ->where('event_code', AuditEvent::VALIDATED->value)
            ->get();
    }

    /** Une période sans commission ne passe jamais « Calculée » toute seule : le statut est posé ici. */
    private function periodeOuverteAuxAjustements(string $organizationId, string $userId): PaiementPeriode
    {
        $periode = app(PeriodePaiementService::class)->getOrCreatePeriod($organizationId, TypePeriodePaiement::LIVREUR, now(), $userId);
        $periode->update(['statut' => StatutPeriodePaiement::CALCULEE->value]);

        return $periode;
    }

    private function creerPartLogistique(): CommissionLogistiquePart
    {
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id]);
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $transfert = TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'reference' => 'TRF-TEST-'.uniqid(),
            'site_source_id' => $this->site->id,
            'site_destination_id' => $this->site->id,
            'vehicule_id' => $vehicule->id,
            'statut' => 'reception',
            'date_arrivee_reelle' => now(),
            'created_by' => $this->user->id,
        ]);
        $commission = CommissionLogistique::create([
            'organization_id' => $this->org->id,
            'transfert_logistique_id' => $transfert->id,
            'vehicule_id' => $vehicule->id,
            'base_calcul' => 'forfait',
            'valeur_base' => 1000,
            'montant_total' => 1000,
            'montant_verse' => 0,
            'statut' => 'creee',
        ]);

        return CommissionLogistiquePart::create([
            'commission_logistique_id' => $commission->id,
            'type_beneficiaire' => 'livreur',
            'livreur_id' => $livreur->id,
            'beneficiaire_nom' => $livreur->nom_complet,
            'taux_commission' => 100,
            'montant_brut' => 1000,
            'frais_supplementaires' => 0,
            'montant_net' => 1000,
            'montant_verse' => 0,
            'statut' => 'creee',
            'earned_at' => now(),
            'periode' => PeriodeComptableService::codeForLivreur(now()),
        ]);
    }
}
