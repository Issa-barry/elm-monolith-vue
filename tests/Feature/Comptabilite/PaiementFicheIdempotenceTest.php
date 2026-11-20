<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\AuditEvent;
use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use App\Features\ModuleFeature;
use App\Models\AuditLog;
use App\Models\CompteTresorerie;
use App\Models\Livreur;
use App\Models\PaiementFiche;
use App\Models\PaiementFichePaiement;
use App\Models\PaiementPeriode;
use App\Models\PieceComptable;
use App\Models\Site;
use App\Models\User;
use App\Notifications\CommissionPayeeNotification;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use App\Support\Paiements\OperationPaiement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Pennant\Feature;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Idempotence des paiements de fiche (ADR 0069), bouton Payer et paiement groupé : une même
 * opération (en-tête Idempotency-Key) n'enregistre jamais deux paiements, ni deux écritures, ni
 * deux mouvements de trésorerie, ni deux notifications ; rejouée, elle restitue son résultat ;
 * réutilisée avec d'autres paramètres, elle est refusée. La concurrence réelle (verrous MySQL) est
 * vérifiée à part : tests/Concurrence.
 */
class PaiementFicheIdempotenceTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, RefreshDatabase;

    private Site $agence;

    private CompteTresorerie $orange;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['comptabilite.read', 'comptabilite.payer']);
        Feature::for($this->org)->activate(ModuleFeature::COMPTABILITE);
        $this->agence = $this->user->sites()->wherePivot('is_default', true)->firstOrFail();
        $this->orange = $this->creerSupportAgence($this->agence->id, 'mobile_money', '561100', 'orange_money');
        $this->alimenterCaisse($this->orange, 2_000_000);
        Notification::fake();
        Queue::fake();
    }

    private function fiche(float $net = 300_000, string $statutPeriode = StatutPeriodePaiement::VALIDEE->value): PaiementFiche
    {
        $periode = PaiementPeriode::create([
            'organization_id' => $this->org->id,
            'reference' => 'PAY-TEST-'.Str::upper(Str::random(6)),
            'type' => TypePeriodePaiement::LIVREUR->value,
            'date_debut' => now()->startOfMonth()->toDateString(),
            'date_fin' => now()->startOfMonth()->addDays(14)->toDateString(),
            'statut' => $statutPeriode,
            'created_by' => $this->user->id,
        ]);
        $compte = User::factory()->create(['organization_id' => $this->org->id]);

        return PaiementFiche::create([
            'organization_id' => $this->org->id,
            'periode_id' => $periode->id,
            'reference' => 'FICHE-TEST-'.Str::upper(Str::random(6)),
            'beneficiaire_type' => 'livreur',
            'beneficiaire_id' => Livreur::factory()->create(['organization_id' => $this->org->id, 'user_id' => $compte->id])->id,
            'beneficiaire_nom' => 'Livreur '.Str::random(4),
            'site_id' => $this->agence->id,
            'montant_brut' => $net,
            'total_deductions' => 0,
            'montant_net' => $net,
            'montant_paye' => 0,
            'statut' => 'a_payer',
        ]);
    }

    private function payer(PaiementFiche $fiche, ?string $cle, array $donnees = []): TestResponse
    {
        if ($cle !== null) {
            $this->withHeader(OperationPaiement::ENTETE, $cle);
        }

        return $this->actingAs($this->user)->post(route('comptabilite.fiches.paiements.store', $fiche), array_merge([
            'montant' => 50_000,
            'mode_paiement' => 'mobile_money',
            'compte_tresorerie_id' => $this->orange->id,
            'reference_paiement' => 'OM-1001',
            'date_paiement' => now()->toDateString(),
        ], $donnees));
    }

    /** @param  list<PaiementFiche>  $fiches */
    private function payerLot(array $fiches, string $cle, array $donnees = []): TestResponse
    {
        return $this->actingAs($this->user)->withHeader(OperationPaiement::ENTETE, $cle)
            ->postJson(route('comptabilite.fiches.paiements.lot'), array_merge([
                'fiches' => array_map(fn (PaiementFiche $f) => ['id' => $f->id, 'montant' => $f->montant_restant], $fiches),
                'mode_paiement' => 'mobile_money',
                'compte_tresorerie_id' => $this->orange->id,
                'reference_paiement' => 'LOT-OM-2011',
                'date_paiement' => now()->toDateString(),
            ], $donnees));
    }

    private function solde(): float
    {
        return app(TresorerieDisponibiliteService::class)->soldePourSupport($this->orange->fresh(), now());
    }

    private function nombreHistoriques(PaiementFiche $fiche): int
    {
        return AuditLog::where('auditable_id', $fiche->id)->where('event_code', AuditEvent::PAID->value)->count();
    }

    private function assertUnSeulPaiement(PaiementFiche $fiche, float $montant): void
    {
        $paiements = PaiementFichePaiement::where('fiche_id', $fiche->id)->get();
        $this->assertCount(1, $paiements);
        $this->assertSame($montant, (float) $paiements->first()->montant);
        $this->assertSame(1, PieceComptable::where('source_id', $paiements->first()->id)->count(), 'Une seule écriture comptable.');
        $this->assertSame(1, $this->nombreHistoriques($fiche), 'Un seul historique « Paiement enregistré ».');
        $this->assertSame($montant, (float) $fiche->fresh()->montant_paye);
        Notification::assertSentToTimes(Livreur::find($fiche->beneficiaire_id)->user, CommissionPayeeNotification::class, 1);
    }

    // ── Bouton Payer ──────────────────────────────────────────────────────────

    public function test_double_envoi_dun_paiement_partiel_nenregistre_quun_paiement(): void
    {
        $fiche = $this->fiche();
        $cle = (string) Str::uuid();

        $this->payer($fiche, $cle)->assertSessionHasNoErrors()->assertSessionHas('success');
        // Même opération renvoyée (double clic, nouvelle tentative après une coupure) : résultat initial.
        $this->payer($fiche, $cle)->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertUnSeulPaiement($fiche, 50_000);
        $this->assertSame(1_950_000.0, $this->solde());
    }

    public function test_rejeu_apres_solde_complet_restitue_le_paiement_au_lieu_dune_erreur(): void
    {
        $fiche = $this->fiche(300_000);
        $cle = (string) Str::uuid();

        $this->payer($fiche, $cle, ['montant' => 300_000])->assertSessionHasNoErrors();
        // Reste à payer désormais nul : le rejeu ne doit ni payer ni échouer sur le plafond du montant.
        $this->payer($fiche, $cle, ['montant' => 300_000])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertUnSeulPaiement($fiche, 300_000);
        $this->assertSame(1_700_000.0, $this->solde());
    }

    public function test_cle_reutilisee_avec_dautres_parametres_refusee(): void
    {
        $fiche = $this->fiche();
        $autre = $this->fiche();
        $cle = (string) Str::uuid();
        $this->payer($fiche, $cle)->assertSessionHasNoErrors();

        foreach ([
            'montant' => [$fiche, ['montant' => 60_000]],
            'référence' => [$fiche, ['reference_paiement' => 'OM-9999']],
            'mode et compte' => [$fiche, ['mode_paiement' => 'especes', 'compte_tresorerie_id' => null, 'reference_paiement' => null]],
            'date' => [$fiche, ['date_paiement' => now()->subDay()->toDateString()]],
            'fiche et bénéficiaire' => [$autre, []],
        ] as $cas => [$cible, $donnees]) {
            $this->payer($cible, $cle, $donnees)->assertSessionHasErrors(['operation' => OperationPaiement::MESSAGE_CLE_REUTILISEE]);
        }

        $this->assertUnSeulPaiement($fiche, 50_000);
        $this->assertDatabaseMissing('paiement_fiche_paiements', ['fiche_id' => $autre->id]);
        $this->assertSame(1_950_000.0, $this->solde());
    }

    public function test_deux_operations_distinctes_sont_deux_paiements(): void
    {
        $fiche = $this->fiche();

        $this->payer($fiche, (string) Str::uuid())->assertSessionHasNoErrors();
        $this->payer($fiche, (string) Str::uuid())->assertSessionHasNoErrors();

        $this->assertSame(2, PaiementFichePaiement::where('fiche_id', $fiche->id)->count());
        $this->assertSame(100_000.0, (float) $fiche->fresh()->montant_paye);
    }

    public function test_paiement_sans_cle_dop_ou_cle_invalide_refuse(): void
    {
        $fiche = $this->fiche();

        $this->payer($fiche, null)->assertSessionHasErrors(['operation' => OperationPaiement::MESSAGE_CLE_ABSENTE]);
        $this->payer($fiche, 'court')->assertSessionHasErrors(['operation' => OperationPaiement::MESSAGE_CLE_ABSENTE]);

        $this->assertDatabaseCount('paiement_fiche_paiements', 0);
    }

    public function test_paiements_historiques_sans_cle_coexistent(): void
    {
        $fiche = $this->fiche();
        foreach ([10_000, 20_000] as $montant) {
            PaiementFichePaiement::create([
                'fiche_id' => $fiche->id,
                'organization_id' => $this->org->id,
                'montant' => $montant,
                'mode_paiement' => 'especes',
                'date_paiement' => now()->toDateString(),
            ]);
        }

        $this->payer($fiche, (string) Str::uuid())->assertSessionHasNoErrors();

        $this->assertSame(2, PaiementFichePaiement::where('fiche_id', $fiche->id)->whereNull('operation_id')->count());
        $this->assertSame(80_000.0, (float) $fiche->fresh()->montant_paye);
    }

    // ── Paiement groupé ───────────────────────────────────────────────────────

    public function test_lot_rejoue_restitue_le_resultat_initial(): void
    {
        $a = $this->fiche(300_000);
        $b = $this->fiche(200_000);
        $cle = (string) Str::uuid().'.0';
        $piecesAvant = PieceComptable::count();

        $initial = $this->payerLot([$a, $b], $cle)->assertOk()->assertJson(['payees' => 2, 'montant_total' => 500_000, 'rejeu' => false]);
        // Réponse perdue (coupure après l'enregistrement) puis renvoi identique, montants d'origine compris.
        $this->actingAs($this->user)->withHeader(OperationPaiement::ENTETE, $cle)->postJson(route('comptabilite.fiches.paiements.lot'), [
            'fiches' => [['id' => $a->id, 'montant' => 300_000], ['id' => $b->id, 'montant' => 200_000]],
            'mode_paiement' => 'mobile_money',
            'compte_tresorerie_id' => $this->orange->id,
            'reference_paiement' => 'LOT-OM-2011',
            'date_paiement' => now()->toDateString(),
        ])->assertOk()->assertJson([
            'payees' => $initial->json('payees'),
            'montant_total' => $initial->json('montant_total'),
            'deja_payees' => [],
            'rejeu' => true,
        ]);

        $this->assertUnSeulPaiement($a, 300_000);
        $this->assertUnSeulPaiement($b, 200_000);
        $this->assertSame($piecesAvant + 2, PieceComptable::count());
        $this->assertSame(1_500_000.0, $this->solde());
    }

    public function test_lot_rejoue_garde_les_fiches_deja_payees_hors_du_resultat(): void
    {
        $a = $this->fiche(300_000);
        $b = $this->fiche(100_000);
        $this->payerLot([$b], (string) Str::uuid().'.0')->assertOk();
        $cle = (string) Str::uuid().'.0';
        $lignes = [['id' => $a->id, 'montant' => 300_000], ['id' => $b->id, 'montant' => 100_000]];
        $envoyer = fn () => $this->actingAs($this->user)->withHeader(OperationPaiement::ENTETE, $cle)->postJson(route('comptabilite.fiches.paiements.lot'), [
            'fiches' => $lignes,
            'mode_paiement' => 'mobile_money',
            'compte_tresorerie_id' => $this->orange->id,
            'reference_paiement' => 'LOT-OM-2011',
            'date_paiement' => now()->toDateString(),
        ]);

        $envoyer()->assertOk()->assertJson(['payees' => 1, 'deja_payees' => [$b->beneficiaire_nom], 'rejeu' => false]);
        $envoyer()->assertOk()->assertJson(['payees' => 1, 'deja_payees' => [$b->beneficiaire_nom], 'rejeu' => true]);

        $this->assertUnSeulPaiement($a, 300_000);
        $this->assertUnSeulPaiement($b, 100_000);
    }

    public function test_cle_de_lot_reutilisee_avec_dautres_parametres_refusee(): void
    {
        $a = $this->fiche(300_000);
        $b = $this->fiche(200_000);
        $c = $this->fiche(100_000);
        $cle = (string) Str::uuid().'.0';
        $this->payerLot([$a, $b], $cle)->assertOk();

        foreach ([
            'autres fiches' => [[$a, $c], []],
            'fiche en moins' => [[$a], []],
            'autre référence' => [[$a, $b], ['reference_paiement' => 'LOT-AUTRE']],
            'autre date' => [[$a, $b], ['date_paiement' => now()->subDay()->toDateString()]],
        ] as $cas => [$fiches, $donnees]) {
            $this->payerLot($fiches, $cle, $donnees)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['operation' => OperationPaiement::MESSAGE_CLE_REUTILISEE]);
        }

        $this->assertDatabaseMissing('paiement_fiche_paiements', ['fiche_id' => $c->id]);
        $this->assertSame(2, PaiementFichePaiement::count());
        $this->assertSame(1_500_000.0, $this->solde());
    }

    public function test_cle_dun_paiement_unitaire_ne_sert_pas_a_un_lot(): void
    {
        $fiche = $this->fiche(300_000);
        $cle = (string) Str::uuid();
        $this->payer($fiche, $cle)->assertSessionHasNoErrors();

        $this->payerLot([$this->fiche(100_000)], $cle)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['operation' => OperationPaiement::MESSAGE_CLE_REUTILISEE]);

        $this->assertSame(1, PaiementFichePaiement::count());
    }

    public function test_lot_sans_cle_refuse(): void
    {
        $this->actingAs($this->user)->postJson(route('comptabilite.fiches.paiements.lot'), [
            'fiches' => [['id' => $this->fiche()->id, 'montant' => 300_000]],
            'mode_paiement' => 'mobile_money',
            'compte_tresorerie_id' => $this->orange->id,
            'reference_paiement' => 'LOT-OM-2011',
            'date_paiement' => now()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['operation' => OperationPaiement::MESSAGE_CLE_ABSENTE]);

        $this->assertDatabaseCount('paiement_fiche_paiements', 0);
    }

    public function test_lot_refuse_puis_corrige_avec_une_nouvelle_cle(): void
    {
        $a = $this->fiche(300_000);
        // Un lot refusé n'enregistre rien : sa clé ne lie aucun paiement.
        $cle = (string) Str::uuid().'.0';
        $this->payerLot([$a], $cle, ['reference_paiement' => null])->assertUnprocessable();
        $this->payerLot([$a], $cle)->assertOk()->assertJson(['payees' => 1, 'rejeu' => false]);

        $this->assertUnSeulPaiement($a, 300_000);
    }
}
