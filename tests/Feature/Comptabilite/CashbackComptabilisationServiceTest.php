<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\EvenementComptable;
use App\Enums\StatutPieceComptable;
use App\Models\CashbackTransaction;
use App\Models\Client;
use App\Models\Organization;
use App\Models\PieceComptable;
use App\Models\TiersComptable;
use App\Models\User;
use App\Services\CashbackService;
use App\Services\Comptabilite\PlanComptableBootstrapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Le versement de cashback était le seul flux qui bloquait la suppression de
 * JournalTresorerie (audit du 2026-08-22) : aucune écriture dans
 * compta_ecritures n'existait pour ce paiement réel de trésorerie. Ces tests
 * verrouillent le comportement une fois CashbackComptabilisationService
 * raccordé à CashbackVersement::booted().
 */
class CashbackComptabilisationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(): Organization
    {
        $org = Organization::factory()->create();
        app(PlanComptableBootstrapService::class)->bootstrap($org->id);

        return $org;
    }

    private function makeTransactionVersable(Organization $org, int $montant = 20_000): CashbackTransaction
    {
        $client = Client::factory()->create(['organization_id' => $org->id]);

        return CashbackTransaction::create([
            'organization_id' => $org->id,
            'client_id' => $client->id,
            'type' => CashbackTransaction::TYPE_GAIN,
            'montant' => $montant,
            'montant_verse' => 0,
            'statut' => CashbackTransaction::STATUT_VALIDE,
        ]);
    }

    public function test_versement_cashback_produit_une_piece_comptable_equilibree(): void
    {
        $org = $this->makeOrg();
        $transaction = $this->makeTransactionVersable($org, 20_000);
        $user = $this->makeUser($org);

        app(CashbackService::class)->verser($transaction, $user, 20_000, 'especes', '2026-06-10');

        $piece = PieceComptable::where('organization_id', $org->id)
            ->where('type_evenement', EvenementComptable::VERSEMENT_CASHBACK->value)
            ->first();

        $this->assertNotNull($piece);
        $this->assertSame(StatutPieceComptable::VALIDEE, $piece->statut);
        $this->assertEquals(20_000.0, $piece->totalDebit());
        $this->assertEquals(20_000.0, $piece->totalCredit());

        $lignes = $piece->lignes()->with('compte')->get()->keyBy(fn ($l) => $l->compte->numero);
        $this->assertEquals(20_000.0, (float) $lignes['658100']->debit); // charge cashback
        $this->assertEquals(20_000.0, (float) $lignes['571000']->credit); // caisse

        $tiers = TiersComptable::where('tiersable_type', $transaction->client->getMorphClass())
            ->where('tiersable_id', $transaction->client_id)
            ->first();
        $this->assertNotNull($tiers);
        $this->assertSame('client', $tiers->type);
    }

    public function test_versement_partiel_puis_total_produit_deux_pieces_distinctes(): void
    {
        $org = $this->makeOrg();
        $transaction = $this->makeTransactionVersable($org, 30_000);
        $user = $this->makeUser($org);
        $cashback = app(CashbackService::class);

        $cashback->verser($transaction, $user, 10_000, 'especes', '2026-06-10');
        $cashback->verser($transaction->fresh(), $user, 20_000, 'mobile_money', '2026-06-11');

        $pieces = PieceComptable::where('organization_id', $org->id)
            ->where('type_evenement', EvenementComptable::VERSEMENT_CASHBACK->value)
            ->get();

        $this->assertCount(2, $pieces);
        $this->assertEqualsCanonicalizing([10_000.0, 20_000.0], $pieces->map(fn ($p) => $p->totalDebit())->all());
    }

    private function makeUser(Organization $org): User
    {
        return User::factory()->create([
            'organization_id' => $org->id,
            'password' => Hash::make('password'),
        ]);
    }
}
