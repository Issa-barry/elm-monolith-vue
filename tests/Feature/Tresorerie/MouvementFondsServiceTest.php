<?php

namespace Tests\Feature\Tresorerie;

use App\Enums\StatutMouvementFonds;
use App\Enums\StatutPieceComptable;
use App\Exceptions\Tresorerie\TransitionMouvementFondsInvalideException;
use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\EcritureComptable;
use App\Models\MouvementFonds;
use App\Models\Organization;
use App\Models\PieceComptable;
use App\Models\Site;
use App\Services\Tresorerie\MouvementFondsService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class MouvementFondsServiceTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private MouvementFondsService $service;

    private Site $siege;

    private Site $agence;

    private CompteTresorerie $caisseSiege;

    private CompteTresorerie $caisseAgence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['tresorerie.create']);
        $this->service = app(MouvementFondsService::class);

        $this->siege = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Siège',
            'type' => 'siege',
            'localisation' => 'Conakry',
        ]);
        $this->agence = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Agence',
            'type' => 'agence',
            'localisation' => 'Conakry',
        ]);

        $compteCaisse = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();

        $this->caisseSiege = CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->siege->id,
            'compte_comptable_id' => $compteCaisse->id,
            'type' => 'caisse',
            'libelle' => 'Caisse Siège',
        ]);
        $this->caisseAgence = CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->agence->id,
            'compte_comptable_id' => $compteCaisse->id,
            'type' => 'caisse',
            'libelle' => 'Caisse Agence',
        ]);
    }

    private function creerMouvement(float $montant = 500_000): MouvementFonds
    {
        return $this->service->creerBrouillon($this->org->id, [
            'site_origine_id' => $this->siege->id,
            'site_destination_id' => $this->agence->id,
            'compte_tresorerie_origine_id' => $this->caisseSiege->id,
            'compte_tresorerie_destination_id' => $this->caisseAgence->id,
            'montant' => $montant,
        ], $this->user->id);
    }

    public function test_creation_genere_une_reference_unique(): void
    {
        $mouvement = $this->creerMouvement();

        $this->assertStringStartsWith('MVT-'.now()->year.'-', $mouvement->reference);
        $this->assertSame(StatutMouvementFonds::BROUILLON, $mouvement->statut);
    }

    public function test_refuse_meme_site_origine_et_destination(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->creerBrouillon($this->org->id, [
            'site_origine_id' => $this->siege->id,
            'site_destination_id' => $this->siege->id,
            'compte_tresorerie_origine_id' => $this->caisseSiege->id,
            'compte_tresorerie_destination_id' => $this->caisseSiege->id,
            'montant' => 100,
        ], $this->user->id);
    }

    public function test_envoyer_passe_en_statut_envoye_et_cree_une_piece_equilibree(): void
    {
        $mouvement = $this->creerMouvement(500_000);

        $envoye = $this->service->envoyer($mouvement, $this->user->id);

        $this->assertSame(StatutMouvementFonds::ENVOYE, $envoye->statut);
        $this->assertNotNull($envoye->piece_comptable_envoi_id);

        $piece = $envoye->pieceEnvoi;
        $this->assertSame(500_000.0, $piece->totalDebit());
        $this->assertSame(500_000.0, $piece->totalCredit());

        // La jambe trésorerie (crédit) doit être postée au site D'ORIGINE.
        $ligneTresorerie = EcritureComptable::where('piece_comptable_id', $piece->id)
            ->where('compte_comptable_id', $this->caisseSiege->compte_comptable_id)
            ->firstOrFail();
        $this->assertSame($this->siege->id, $ligneTresorerie->site_id);
        $this->assertSame(500_000.0, (float) $ligneTresorerie->credit);
    }

    public function test_recevoir_solde_le_compte_de_virements_internes(): void
    {
        $mouvement = $this->creerMouvement(500_000);
        $mouvement = $this->service->envoyer($mouvement, $this->user->id);
        $mouvement = $this->service->recevoir($mouvement, $this->user->id);

        $this->assertSame(StatutMouvementFonds::RECU, $mouvement->statut);
        $this->assertNotNull($mouvement->piece_comptable_reception_id);

        $compte58 = CompteComptable::where('organization_id', $this->org->id)->where('numero', '588000')->firstOrFail();
        $solde = EcritureComptable::where('compte_comptable_id', $compte58->id)
            ->whereHas('piece', fn ($q) => $q->whereIn('id', [$mouvement->piece_comptable_envoi_id, $mouvement->piece_comptable_reception_id]))
            ->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) as solde')
            ->value('solde');

        $this->assertEquals(0.0, (float) $solde);

        // La caisse de destination doit avoir été créditée du bon montant, au bon site.
        $ligneDestination = EcritureComptable::where('piece_comptable_id', $mouvement->piece_comptable_reception_id)
            ->where('compte_comptable_id', $this->caisseAgence->compte_comptable_id)
            ->firstOrFail();
        $this->assertSame($this->agence->id, $ligneDestination->site_id);
        $this->assertSame(500_000.0, (float) $ligneDestination->debit);
    }

    public function test_ne_peut_pas_envoyer_deux_fois(): void
    {
        $mouvement = $this->creerMouvement();
        $mouvement = $this->service->envoyer($mouvement, $this->user->id);

        $this->expectException(TransitionMouvementFondsInvalideException::class);
        $this->service->envoyer($mouvement, $this->user->id);
    }

    public function test_ne_peut_pas_recevoir_un_brouillon(): void
    {
        $mouvement = $this->creerMouvement();

        $this->expectException(TransitionMouvementFondsInvalideException::class);
        $this->service->recevoir($mouvement, $this->user->id);
    }

    public function test_annuler_un_brouillon(): void
    {
        $mouvement = $this->creerMouvement();

        $annule = $this->service->annuler($mouvement, $this->user->id, 'Erreur de saisie');

        $this->assertSame(StatutMouvementFonds::ANNULE, $annule->statut);
        $this->assertSame('Erreur de saisie', $annule->motif_annulation);
    }

    public function test_ne_peut_pas_annuler_un_mouvement_deja_envoye(): void
    {
        $mouvement = $this->creerMouvement();
        $mouvement = $this->service->envoyer($mouvement, $this->user->id);

        $this->expectException(TransitionMouvementFondsInvalideException::class);
        $this->service->annuler($mouvement, $this->user->id, 'Trop tard');
    }

    public function test_rejeter_contrepasse_la_piece_d_envoi(): void
    {
        $mouvement = $this->creerMouvement(500_000);
        $mouvement = $this->service->envoyer($mouvement, $this->user->id);
        $pieceEnvoiId = $mouvement->piece_comptable_envoi_id;

        $rejete = $this->service->rejeter($mouvement, $this->user->id, 'Fonds jamais reçus physiquement');

        $this->assertSame(StatutMouvementFonds::REJETE, $rejete->statut);

        $piece = PieceComptable::find($pieceEnvoiId);
        $this->assertTrue($piece->fresh()->statut === StatutPieceComptable::CONTREPASSEE);

        // La caisse du siège doit être revenue à son solde d'avant l'envoi (contrepassation).
        $solde = EcritureComptable::where('compte_comptable_id', $this->caisseSiege->compte_comptable_id)
            ->where('site_id', $this->siege->id)
            ->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) as solde')
            ->value('solde');
        $this->assertEquals(0.0, (float) $solde);
    }

    public function test_isole_les_organisations_a_la_creation(): void
    {
        $autreOrg = Organization::factory()->create();

        $this->expectException(ModelNotFoundException::class);
        $this->service->creerBrouillon($autreOrg->id, [
            'site_origine_id' => $this->siege->id,
            'site_destination_id' => $this->agence->id,
            'compte_tresorerie_origine_id' => $this->caisseSiege->id,
            'compte_tresorerie_destination_id' => $this->caisseAgence->id,
            'montant' => 100,
        ], $this->user->id);
    }
}
