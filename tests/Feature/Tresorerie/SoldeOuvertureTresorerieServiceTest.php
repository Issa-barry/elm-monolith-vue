<?php

namespace Tests\Feature\Tresorerie;

use App\Enums\StatutSoldeOuverture;
use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\PieceComptable;
use App\Services\Tresorerie\SoldeOuvertureTresorerieService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class SoldeOuvertureTresorerieServiceTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private SoldeOuvertureTresorerieService $service;

    private CompteTresorerie $compteTresorerie;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser([]);
        $this->service = app(SoldeOuvertureTresorerieService::class);

        $site = $this->user->sites()->first();
        $compteCaisse = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();
        $this->compteTresorerie = CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'compte_comptable_id' => $compteCaisse->id,
            'type' => 'caisse',
            'libelle' => 'Caisse',
        ]);
    }

    public function test_enregistrer_cree_un_brouillon(): void
    {
        $solde = $this->service->enregistrer($this->org->id, $this->compteTresorerie, [
            'date_situation' => '2026-08-01',
            'montant' => 2_500_000,
        ], $this->user->id);

        $this->assertSame(StatutSoldeOuverture::BROUILLON, $solde->statut);
        $this->assertNull($solde->piece_comptable_id);
    }

    public function test_refuse_un_deuxieme_solde_pour_le_meme_support(): void
    {
        $this->service->enregistrer($this->org->id, $this->compteTresorerie, ['date_situation' => '2026-08-01', 'montant' => 1_000_000], $this->user->id);

        $this->expectException(\RuntimeException::class);
        $this->service->enregistrer($this->org->id, $this->compteTresorerie, ['date_situation' => '2026-08-01', 'montant' => 500_000], $this->user->id);
    }

    public function test_valider_produit_une_piece_comptable_equilibree(): void
    {
        $solde = $this->service->enregistrer($this->org->id, $this->compteTresorerie, [
            'date_situation' => '2026-08-01',
            'montant' => 2_500_000,
        ], $this->user->id);

        $valide = $this->service->valider($solde, $this->user->id);

        $this->assertSame(StatutSoldeOuverture::VALIDE, $valide->statut);
        $this->assertNotNull($valide->piece_comptable_id);
        $this->assertSame(2_500_000.0, $valide->piece->totalDebit());
        $this->assertSame(2_500_000.0, $valide->piece->totalCredit());
    }

    public function test_valider_est_idempotent(): void
    {
        $solde = $this->service->enregistrer($this->org->id, $this->compteTresorerie, ['date_situation' => '2026-08-01', 'montant' => 1_000_000], $this->user->id);

        $premiere = $this->service->valider($solde, $this->user->id);
        $deuxieme = $this->service->valider($premiere, $this->user->id);

        $this->assertSame($premiere->piece_comptable_id, $deuxieme->piece_comptable_id);
        $this->assertSame(1, PieceComptable::where('source_id', $solde->id)->count());
    }

    public function test_montant_zero_est_valide_sans_piece(): void
    {
        $solde = $this->service->enregistrer($this->org->id, $this->compteTresorerie, ['date_situation' => '2026-08-01', 'montant' => 0], $this->user->id);

        $valide = $this->service->valider($solde, $this->user->id);

        $this->assertSame(StatutSoldeOuverture::VALIDE, $valide->statut);
        $this->assertNull($valide->piece_comptable_id);
    }
}
