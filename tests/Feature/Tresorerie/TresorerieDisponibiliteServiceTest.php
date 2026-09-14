<?php

namespace Tests\Feature\Tresorerie;

use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\Site;
use App\Services\Tresorerie\MouvementFondsService;
use App\Services\Tresorerie\SoldeOuvertureTresorerieService;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class TresorerieDisponibiliteServiceTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private TresorerieDisponibiliteService $service;

    private Site $siege;

    private Site $agence;

    private CompteTresorerie $caisseSiege;

    private CompteTresorerie $caisseAgence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['tresorerie.create']);
        $this->service = app(TresorerieDisponibiliteService::class);

        $this->siege = Site::create(['organization_id' => $this->org->id, 'nom' => 'Siège', 'type' => 'siege', 'localisation' => 'Conakry']);
        $this->agence = Site::create(['organization_id' => $this->org->id, 'nom' => 'Agence', 'type' => 'agence', 'localisation' => 'Conakry']);

        $compteCaisse = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();
        $this->caisseSiege = CompteTresorerie::create([
            'organization_id' => $this->org->id, 'site_id' => $this->siege->id,
            'compte_comptable_id' => $compteCaisse->id, 'type' => 'caisse', 'libelle' => 'Caisse Siège',
        ]);
        $this->caisseAgence = CompteTresorerie::create([
            'organization_id' => $this->org->id, 'site_id' => $this->agence->id,
            'compte_comptable_id' => $compteCaisse->id, 'type' => 'caisse', 'libelle' => 'Caisse Agence',
        ]);
    }

    private function validerSoldeOuverture(CompteTresorerie $compte, float $montant, string $date = '2026-08-01'): void
    {
        $service = app(SoldeOuvertureTresorerieService::class);
        $solde = $service->enregistrer($this->org->id, $compte, [
            'date_situation' => $date,
            'montant' => $montant,
        ], $this->user->id);
        $service->valider($solde, $this->user->id);
    }

    /**
     * Reflète exactement l'exemple d'origine (audit du 13/09/2026) : un
     * mouvement Matoto -> Kouria reçu doit apparaître dans la caisse de
     * destination via situationParSupport(), pas seulement via
     * disponiblePourSite() — même source de vérité (grand livre), deux
     * granularités différentes.
     */
    public function test_situation_par_support_reflete_un_mouvement_recu(): void
    {
        $mvtService = app(MouvementFondsService::class);
        $mouvement = $mvtService->creerBrouillon($this->org->id, [
            'site_origine_id' => $this->siege->id,
            'site_destination_id' => $this->agence->id,
            'compte_tresorerie_origine_id' => $this->caisseSiege->id,
            'montant' => 361_000,
        ], $this->user->id);
        $mouvement = $mvtService->envoyer($mouvement, $this->user->id);
        $mvtService->recevoir($mouvement, $this->user->id, $this->caisseAgence->id);

        $situation = $this->service->situationParSupport($this->org->id, Carbon::now());

        $ligneAgence = $situation->firstWhere('compte_tresorerie_id', $this->caisseAgence->id);
        $this->assertNotNull($ligneAgence);
        $this->assertSame(361_000.0, $ligneAgence['solde']);
        $this->assertSame($this->agence->id, $ligneAgence['site_id']);

        $ligneSiege = $situation->firstWhere('compte_tresorerie_id', $this->caisseSiege->id);
        $this->assertSame(-361_000.0, $ligneSiege['solde']);
    }

    public function test_situation_par_support_inclut_le_solde_d_ouverture(): void
    {
        $this->validerSoldeOuverture($this->caisseAgence, 500_000, '2026-08-01');

        $situation = $this->service->situationParSupport($this->org->id, Carbon::parse('2026-08-15'));

        $ligne = $situation->firstWhere('compte_tresorerie_id', $this->caisseAgence->id);
        $this->assertSame(500_000.0, $ligne['solde']);
    }

    public function test_situation_par_support_ignore_les_ecritures_posterieures_a_la_date(): void
    {
        $this->validerSoldeOuverture($this->caisseAgence, 200_000, '2026-08-01');

        // Une date de situation antérieure au solde d'ouverture ne doit rien compter.
        $situation = $this->service->situationParSupport($this->org->id, Carbon::parse('2026-07-31'));

        $ligne = $situation->firstWhere('compte_tresorerie_id', $this->caisseAgence->id);
        $this->assertSame(0.0, $ligne['solde']);
    }

    public function test_situation_par_support_ignore_les_supports_inactifs(): void
    {
        $this->caisseAgence->update(['actif' => false]);

        $situation = $this->service->situationParSupport($this->org->id, Carbon::now());

        $this->assertNull($situation->firstWhere('compte_tresorerie_id', $this->caisseAgence->id));
    }
}
