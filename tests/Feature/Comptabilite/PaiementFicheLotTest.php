<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutFichePaiement;
use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use App\Features\ModuleFeature;
use App\Http\Controllers\Comptabilite\PaiementFicheLotController;
use App\Http\Controllers\Comptabilite\PaiementFichePaiementController;
use App\Models\CompteTresorerie;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\PaiementFiche;
use App\Models\PaiementFichePaiement;
use App\Models\PaiementPeriode;
use App\Models\PieceComptable;
use App\Models\Site;
use App\Services\Comptabilite\EcritureComptableService;
use App\Services\Comptabilite\FicheComptabilisationService;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Paiement groupé (ADR 0068) : plusieurs fiches réglées hors application (dépôts Orange Money…)
 * marquées payées en une fois, chacune pour son reste entier, depuis un seul support. Un lot passe
 * entièrement ou pas du tout.
 */
class PaiementFicheLotTest extends TestCase
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

    private function fiche(?string $siteId, float $net = 300_000, string $type = 'livreur', string $statutPeriode = StatutPeriodePaiement::VALIDEE->value): PaiementFiche
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

        return PaiementFiche::create([
            'organization_id' => $this->org->id,
            'periode_id' => $periode->id,
            'reference' => 'FICHE-TEST-'.Str::upper(Str::random(6)),
            'beneficiaire_type' => $type,
            'beneficiaire_id' => Livreur::factory()->create(['organization_id' => $this->org->id])->id,
            'beneficiaire_nom' => 'Livreur '.Str::random(4),
            'site_id' => $siteId,
            'montant_brut' => $net,
            'total_deductions' => 0,
            'montant_net' => $net,
            'montant_paye' => 0,
            'statut' => StatutFichePaiement::A_PAYER->value,
        ]);
    }

    /** @param  list<PaiementFiche>  $fiches */
    private function payerLot(array $fiches, array $donnees, array $references = [])
    {
        return $this->actingAs($this->user)->withHeader('Idempotency-Key', (string) Str::ulid())->postJson(route('comptabilite.fiches.paiements.lot'), array_merge([
            'fiches' => array_map(fn (PaiementFiche $f) => array_filter([
                'id' => $f->id,
                'montant' => $f->montant_restant,
                'reference_paiement' => $references[$f->id] ?? null,
            ]), $fiches),
            'date_paiement' => now()->toDateString(),
        ], $donnees));
    }

    private function orange(float $solde = 2_000_000): CompteTresorerie
    {
        $orange = $this->creerSupportAgence($this->agence->id, 'mobile_money', '561100', 'orange_money');
        $this->alimenterCaisse($orange, $solde);

        return $orange;
    }

    private function solde(CompteTresorerie $support): float
    {
        return app(TresorerieDisponibiliteService::class)->soldePourSupport($support->fresh(), now());
    }

    private function mobileMoney(CompteTresorerie $orange, ?string $reference = 'LOT-OM-1911'): array
    {
        return array_filter([
            'mode_paiement' => 'mobile_money',
            'compte_tresorerie_id' => $orange->id,
            'reference_paiement' => $reference,
        ]);
    }

    // ── Paiement ──────────────────────────────────────────────────────────────

    public function test_paie_chaque_fiche_pour_son_reste_depuis_le_meme_support(): void
    {
        $orange = $this->orange();
        $a = $this->fiche($this->agence->id, 300_000);
        $b = $this->fiche($this->agence->id, 190_000);

        $this->payerLot([$a, $b], $this->mobileMoney($orange))
            ->assertOk()
            ->assertJson(['payees' => 2, 'montant_total' => 490_000, 'deja_payees' => []]);

        $this->assertSame(1_510_000.0, $this->solde($orange));
        foreach ([$a, $b] as $fiche) {
            $this->assertSame(StatutFichePaiement::PAYE, $fiche->fresh()->statut);
            $this->assertDatabaseHas('paiement_fiche_paiements', [
                'fiche_id' => $fiche->id,
                'compte_tresorerie_id' => $orange->id,
                'reference_paiement' => 'LOT-OM-1911',
                'moyen_paiement_detail' => 'orange_money',
            ]);
        }
    }

    public function test_reference_propre_a_une_ligne_prioritaire_sur_la_reference_commune(): void
    {
        $orange = $this->orange();
        $a = $this->fiche($this->agence->id);
        $b = $this->fiche($this->agence->id);

        $this->payerLot([$a, $b], $this->mobileMoney($orange), [$a->id => 'OM-A-001'])->assertOk();

        $this->assertSame('OM-A-001', PaiementFichePaiement::where('fiche_id', $a->id)->value('reference_paiement'));
        $this->assertSame('LOT-OM-1911', PaiementFichePaiement::where('fiche_id', $b->id)->value('reference_paiement'));
    }

    public function test_especes_debite_la_caisse_dediee_du_payeur(): void
    {
        $caisse = $this->equiperPayeurEspeces($this->user, $this->agence->id, 1_000_000);
        $a = $this->fiche($this->agence->id, 200_000);
        $b = $this->fiche($this->agence->id, 100_000);

        $this->payerLot([$a, $b], ['mode_paiement' => 'especes'])->assertOk()->assertJson(['payees' => 2]);

        $this->assertSame(700_000.0, $this->solde($caisse));
    }

    // ── Miroir du bouton Payer, mode par mode ─────────────────────────────────

    /** Moyens réellement proposés au paiement d'une fiche (MoyensEncaissementResolver, DecaissementSupportResolver). */
    public static function modesDePaiement(): array
    {
        return [
            'Espèces (caisse dédiée du payeur)' => ['especes', null, null, null],
            'Mobile Money Orange Money' => ['mobile_money', 'mobile_money', '561100', 'orange_money'],
            'Mobile Money MoMo' => ['mobile_money', 'mobile_money', '561200', 'momo'],
            'Virement (banque de l\'agence)' => ['virement', 'banque', '521000', null],
            'Chèque (banque de l\'agence)' => ['cheque', 'banque', '521000', null],
        ];
    }

    #[DataProvider('modesDePaiement')]
    public function test_chaque_mode_paye_comme_le_bouton_payer(string $mode, ?string $typeSupport, ?string $compte, ?string $operateur): void
    {
        $support = $typeSupport === null
            ? $this->equiperPayeurEspeces($this->user, $this->agence->id, 2_000_000)
            : $this->creerSupportAgence($this->agence->id, $typeSupport, $compte, $operateur);
        if ($typeSupport !== null) {
            $this->alimenterCaisse($support, 2_000_000);
        }
        $referenceRequise = $mode === 'mobile_money';
        $donnees = array_filter([
            'mode_paiement' => $mode,
            'compte_tresorerie_id' => $typeSupport === null ? null : $support->id,
            'reference_paiement' => $referenceRequise ? 'REF-0001' : null,
        ]);

        $unitaire = $this->fiche($this->agence->id, 300_000);
        $groupe = $this->fiche($this->agence->id, 300_000);

        $this->actingAs($this->user)->withHeader('Idempotency-Key', (string) Str::ulid())->post(route('comptabilite.fiches.paiements.store', $unitaire), [
            ...$donnees,
            'montant' => 300_000,
            'date_paiement' => now()->toDateString(),
        ])->assertSessionHasNoErrors();
        $this->payerLot([$groupe], $donnees)->assertOk()->assertJson(['payees' => 1]);

        $this->assertSame(1_400_000.0, $this->solde($support));
        [$attendu, $obtenu] = array_map(fn (PaiementFiche $f) => $this->traceDuPaiement($f), [$unitaire, $groupe]);
        $this->assertSame($attendu, $obtenu);
        $this->assertSame($support->id, $obtenu['compte_tresorerie_id']);
        $this->assertSame($mode, $obtenu['mode_paiement']);
        $this->assertSame($operateur, $obtenu['moyen_paiement_detail']);
        $this->assertSame($referenceRequise ? 'REF-0001' : null, $obtenu['reference_paiement']);
    }

    #[DataProvider('modesDePaiement')]
    public function test_reference_exigee_seulement_quand_le_bouton_payer_lexige(string $mode, ?string $typeSupport, ?string $compte, ?string $operateur): void
    {
        $support = $typeSupport === null
            ? $this->equiperPayeurEspeces($this->user, $this->agence->id, 2_000_000)
            : $this->creerSupportAgence($this->agence->id, $typeSupport, $compte, $operateur);
        if ($typeSupport !== null) {
            $this->alimenterCaisse($support, 2_000_000);
        }
        $sansReference = array_filter([
            'mode_paiement' => $mode,
            'compte_tresorerie_id' => $typeSupport === null ? null : $support->id,
        ]);

        $reponse = $this->payerLot([$this->fiche($this->agence->id)], $sansReference);

        if ($mode === 'mobile_money') {
            $reponse->assertUnprocessable()->assertJsonValidationErrors('reference_paiement');
            $this->assertDatabaseCount('paiement_fiche_paiements', 0);
        } else {
            $reponse->assertOk()->assertJson(['payees' => 1]);
        }
    }

    public function test_compte_qui_ne_correspond_pas_au_mode_refuse(): void
    {
        $orange = $this->orange();
        $banque = $this->creerSupportAgence($this->agence->id, 'banque', '521000');
        $this->alimenterCaisse($banque, 2_000_000);
        $fiche = $this->fiche($this->agence->id);

        // Virement depuis le compte Orange Money, Mobile Money depuis la banque : aucun des deux n'existe.
        $this->payerLot([$fiche], ['mode_paiement' => 'virement', 'compte_tresorerie_id' => $orange->id])
            ->assertUnprocessable()->assertJsonValidationErrors('compte_tresorerie_id');
        $this->payerLot([$fiche], $this->mobileMoney($banque))
            ->assertUnprocessable()->assertJsonValidationErrors('compte_tresorerie_id');

        $this->assertDatabaseCount('paiement_fiche_paiements', 0);
        $this->assertSame(2_000_000.0, $this->solde($orange));
        $this->assertSame(2_000_000.0, $this->solde($banque));
    }

    /** Ce qu'un paiement laisse derrière lui : la ligne de paiement et son écriture comptable. */
    private function traceDuPaiement(PaiementFiche $fiche): array
    {
        $paiement = PaiementFichePaiement::where('fiche_id', $fiche->id)->sole();
        $piece = app(EcritureComptableService::class)->pieceExistantePour(
            $this->org->id,
            $paiement,
            FicheComptabilisationService::evenementPaiementPour($fiche->beneficiaire_type),
        );
        $this->assertNotNull($piece, 'Le paiement doit être comptabilisé.');

        return [
            'site_id' => $paiement->site_id,
            'montant' => (float) $paiement->montant,
            'mode_paiement' => $paiement->mode_paiement,
            'moyen_paiement_detail' => $paiement->moyen_paiement_detail,
            'compte_tresorerie_id' => $paiement->compte_tresorerie_id,
            'reference_paiement' => $paiement->reference_paiement,
            'statut_fiche' => $fiche->fresh()->statut,
            'ecritures' => $piece->lignes()->orderByDesc('debit')->get()
                ->map(fn ($l) => [$l->compte_comptable_id, $l->site_id, (float) $l->debit, (float) $l->credit])
                ->all(),
        ];
    }

    public function test_fiche_deja_soldee_ignoree_pour_un_lot_renvoye(): void
    {
        $orange = $this->orange();
        $a = $this->fiche($this->agence->id, 300_000);
        $b = $this->fiche($this->agence->id, 100_000);
        $montantA = $a->montant_restant;

        $this->payerLot([$a], $this->mobileMoney($orange))->assertOk();

        // Lot renvoyé après une coupure : la fiche déjà soldée n'est pas payée deux fois.
        $this->actingAs($this->user)->withHeader('Idempotency-Key', (string) Str::ulid())->postJson(route('comptabilite.fiches.paiements.lot'), [
            'fiches' => [['id' => $a->id, 'montant' => $montantA], ['id' => $b->id, 'montant' => 100_000]],
            'date_paiement' => now()->toDateString(),
            ...$this->mobileMoney($orange),
        ])->assertOk()->assertJson(['payees' => 1, 'deja_payees' => [$a->beneficiaire_nom]]);

        $this->assertSame(1, PaiementFichePaiement::where('fiche_id', $a->id)->count());
        $this->assertSame(1_600_000.0, $this->solde($orange));
    }

    // ── Refus, sans aucun effet ───────────────────────────────────────────────

    public function test_solde_insuffisant_pour_le_total_refuse_tout_le_lot(): void
    {
        $orange = $this->orange(400_000);
        $a = $this->fiche($this->agence->id, 300_000);
        $b = $this->fiche($this->agence->id, 200_000);
        $piecesAvant = PieceComptable::count();

        $this->payerLot([$a, $b], $this->mobileMoney($orange))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['montant' => 'Solde insuffisant : 400 000 GNF disponible dans « '.$orange->libelle.' » pour ce paiement groupé de 500 000 GNF.']);

        $this->assertDatabaseCount('paiement_fiche_paiements', 0);
        $this->assertSame($piecesAvant, PieceComptable::count());
        $this->assertSame(400_000.0, $this->solde($orange));
    }

    public function test_reste_modifie_depuis_laffichage_refuse_tout_le_lot(): void
    {
        $orange = $this->orange();
        $a = $this->fiche($this->agence->id, 300_000);
        $b = $this->fiche($this->agence->id, 200_000);

        $this->actingAs($this->user)->withHeader('Idempotency-Key', (string) Str::ulid())->postJson(route('comptabilite.fiches.paiements.lot'), [
            'fiches' => [['id' => $a->id, 'montant' => 300_000], ['id' => $b->id, 'montant' => 250_000]],
            'date_paiement' => now()->toDateString(),
            ...$this->mobileMoney($orange),
        ])->assertUnprocessable()->assertJsonValidationErrors('fiches');

        $this->assertDatabaseCount('paiement_fiche_paiements', 0);
        $this->assertSame(2_000_000.0, $this->solde($orange));
    }

    public function test_echec_en_cours_de_lot_annule_les_paiements_deja_ecrits_du_lot(): void
    {
        $orange = $this->orange();
        $a = $this->fiche($this->agence->id, 300_000);
        $b = $this->fiche($this->agence->id, 200_000);
        $piecesAvant = PieceComptable::count();

        // La 1re fiche est écrite et comptabilisée, puis l'enregistrement de la 2e échoue.
        $echec = $a->id < $b->id ? $b : $a;
        PaiementFichePaiement::created(function (PaiementFichePaiement $p) use ($echec) {
            if ($p->fiche_id === $echec->id) {
                throw new \RuntimeException('comptabilisation impossible');
            }
        });

        $this->payerLot([$a, $b], $this->mobileMoney($orange))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Paiement non enregistré : comptabilisation impossible');

        $this->assertDatabaseCount('paiement_fiche_paiements', 0);
        $this->assertSame($piecesAvant, PieceComptable::count());
        $this->assertSame(2_000_000.0, $this->solde($orange));
        foreach ([$a, $b] as $fiche) {
            $this->assertSame(0.0, (float) $fiche->fresh()->montant_paye);
            $this->assertSame(StatutFichePaiement::A_PAYER, $fiche->fresh()->statut);
        }
    }

    public function test_agences_payeuses_differentes_refusees(): void
    {
        $orange = $this->orange();
        $kindia = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kindia', 'type' => 'depot', 'localisation' => 'Kindia']);
        $this->user->sites()->attach($kindia->id);

        $this->payerLot([$this->fiche($this->agence->id), $this->fiche($kindia->id)], $this->mobileMoney($orange))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fiches' => PaiementFicheLotController::MESSAGE_AGENCES_MULTIPLES]);

        $this->assertDatabaseCount('paiement_fiche_paiements', 0);
    }

    public function test_reference_obligatoire_pour_chaque_ligne_en_mobile_money(): void
    {
        $orange = $this->orange();
        $a = $this->fiche($this->agence->id);
        $b = $this->fiche($this->agence->id);

        $this->payerLot([$a, $b], $this->mobileMoney($orange, null), [$a->id => 'OM-A-001'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reference_paiement');

        $this->assertDatabaseCount('paiement_fiche_paiements', 0);
    }

    public function test_periode_non_validee_refusee(): void
    {
        $orange = $this->orange();

        $this->payerLot([
            $this->fiche($this->agence->id),
            $this->fiche($this->agence->id, statutPeriode: StatutPeriodePaiement::CALCULEE->value),
        ], $this->mobileMoney($orange))->assertUnprocessable()->assertJsonValidationErrors('fiches');

        $this->assertDatabaseCount('paiement_fiche_paiements', 0);
    }

    public function test_fiche_salarie_refusee(): void
    {
        $orange = $this->orange();

        $this->payerLot([$this->fiche($this->agence->id, type: 'salarie')], $this->mobileMoney($orange))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fiches' => PaiementFichePaiementController::MESSAGE_SALARIE]);
    }

    public function test_fiche_dune_agence_non_affectee_refusee(): void
    {
        $orange = $this->orange();
        $kindia = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kindia', 'type' => 'depot', 'localisation' => 'Kindia']);
        $this->user = $this->creerUtilisateurNonAdmin($this->agence, ['comptabilite.read', 'comptabilite.payer']);

        // Une fiche de son agence et une d'une agence où il n'est pas affecté : tout le lot est refusé.
        $this->payerLot([$this->fiche($this->agence->id), $this->fiche($kindia->id)], $this->mobileMoney($orange))
            ->assertForbidden()
            ->assertJsonPath('message', fn (string $m) => str_starts_with($m, "Vous n'avez pas le droit de payer la fiche de"));

        $this->assertDatabaseCount('paiement_fiche_paiements', 0);
    }

    public function test_fiche_dune_autre_organisation_introuvable(): void
    {
        $orange = $this->orange();
        $fiche = $this->fiche($this->agence->id);
        $fiche->forceFill(['organization_id' => Organization::factory()->create()->id])->saveQuietly();

        $this->payerLot([$fiche], $this->mobileMoney($orange))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fiches');

        $this->assertDatabaseCount('paiement_fiche_paiements', 0);
    }

    public function test_sans_permission_payer_refuse(): void
    {
        $orange = $this->orange();
        $fiche = $this->fiche($this->agence->id);
        $this->user->syncPermissions(['comptabilite.read']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->payerLot([$fiche], $this->mobileMoney($orange))->assertForbidden();

        $this->assertDatabaseCount('paiement_fiche_paiements', 0);
    }

    public function test_date_future_refusee(): void
    {
        $orange = $this->orange();

        $this->payerLot([$this->fiche($this->agence->id)], [
            ...$this->mobileMoney($orange),
            'date_paiement' => now()->addDay()->toDateString(),
        ])->assertUnprocessable()->assertJsonValidationErrors('date_paiement');
    }

    public function test_lot_plafonne(): void
    {
        $orange = $this->orange();

        $this->actingAs($this->user)->withHeader('Idempotency-Key', (string) Str::ulid())->postJson(route('comptabilite.fiches.paiements.lot'), [
            'fiches' => array_map(fn (int $i) => ['id' => (string) Str::uuid(), 'montant' => 1000], range(1, PaiementFicheLotController::LOT_MAX + 1)),
            'date_paiement' => now()->toDateString(),
            ...$this->mobileMoney($orange),
        ])->assertUnprocessable()->assertJsonValidationErrors('fiches');
    }
}
