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
use Illuminate\Validation\ValidationException;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class MouvementFondsServiceTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, RefreshDatabase;

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

        // Alimentation généreuse : la plupart des tests de ce fichier portent sur le workflow
        // (envoyer/recevoir/contester...), pas sur le solde — garantirSoldeSuffisant() (règle du
        // 22/09/2026, cf. tests dédiés plus bas) ne doit pas les faire échouer par manque de fonds.
        $this->alimenterCaisse($this->caisseSiege, 50_000_000);
    }

    private function creerMouvement(float $montant = 500_000): MouvementFonds
    {
        return $this->service->creerBrouillon($this->org->id, [
            'site_origine_id' => $this->siege->id,
            'site_destination_id' => $this->agence->id,
            'compte_tresorerie_origine_id' => $this->caisseSiege->id,
            'montant' => $montant,
        ], $this->user->id);
    }

    public function test_creation_genere_une_reference_unique(): void
    {
        $mouvement = $this->creerMouvement();

        $this->assertStringStartsWith('MVT-'.now()->year.'-', $mouvement->reference);
        $this->assertSame(StatutMouvementFonds::BROUILLON, $mouvement->statut);
    }

    /**
     * Le support de destination n'est plus imposé à la création : c'est le
     * destinataire qui le choisit au moment de recevoir() (cf. docblock de
     * MouvementFondsService, revue produit du 2026-09-13).
     */
    public function test_creation_sans_support_destination(): void
    {
        $mouvement = $this->creerMouvement();

        $this->assertNull($mouvement->compte_tresorerie_destination_id);
    }

    public function test_refuse_meme_site_origine_et_destination(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->creerBrouillon($this->org->id, [
            'site_origine_id' => $this->siege->id,
            'site_destination_id' => $this->siege->id,
            'compte_tresorerie_origine_id' => $this->caisseSiege->id,
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
        $mouvement = $this->service->recevoir($mouvement, $this->user->id, $this->caisseAgence->id);

        $this->assertSame(StatutMouvementFonds::RECU, $mouvement->statut);
        $this->assertNotNull($mouvement->piece_comptable_reception_id);
        // Le support choisi à la réception est bien celui persisté sur le mouvement.
        $this->assertSame($this->caisseAgence->id, $mouvement->compte_tresorerie_destination_id);

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
        $this->service->recevoir($mouvement, $this->user->id, $this->caisseAgence->id);
    }

    /** Le support choisi à la réception doit appartenir au site de destination du mouvement. */
    public function test_recevoir_refuse_un_support_d_un_autre_site(): void
    {
        $mouvement = $this->creerMouvement(500_000);
        $mouvement = $this->service->envoyer($mouvement, $this->user->id);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->recevoir($mouvement, $this->user->id, $this->caisseSiege->id);
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

    public function test_contester_ne_contrepasse_rien(): void
    {
        $mouvement = $this->creerMouvement(500_000);
        $mouvement = $this->service->envoyer($mouvement, $this->user->id);
        $pieceEnvoiId = $mouvement->piece_comptable_envoi_id;

        $conteste = $this->service->contester($mouvement, $this->user->id, 'Fonds jamais reçus selon le destinataire');

        $this->assertSame(StatutMouvementFonds::CONTESTE, $conteste->statut);

        // Aucune contrepassation à ce stade : une contestation n'est pas une preuve
        // que l'argent est physiquement revenu à l'origine (revue Codex du 2026-08-22).
        $piece = PieceComptable::find($pieceEnvoiId);
        $this->assertSame(StatutPieceComptable::VALIDEE, $piece->fresh()->statut);
    }

    public function test_confirmer_retour_contrepasse_la_piece_d_envoi(): void
    {
        $mouvement = $this->creerMouvement(500_000);
        $mouvement = $this->service->envoyer($mouvement, $this->user->id);
        $mouvement = $this->service->contester($mouvement, $this->user->id, 'Fonds jamais reçus');
        $pieceEnvoiId = $mouvement->piece_comptable_envoi_id;

        $retourne = $this->service->confirmerRetour($mouvement, $this->user->id, 'Convoyeur a rapporté les fonds au siège');

        $this->assertSame(StatutMouvementFonds::RETOURNE, $retourne->statut);

        $piece = PieceComptable::find($pieceEnvoiId);
        $this->assertTrue($piece->fresh()->statut === StatutPieceComptable::CONTREPASSEE);

        // La caisse du siège doit être revenue à son solde d'avant l'envoi (contrepassation) —
        // 50 000 000 (alimentation de setUp), pas 0 : ce test ne part plus d'une caisse à sec
        // depuis la règle du 22/09/2026 (garantirSoldeSuffisant()).
        $solde = EcritureComptable::where('compte_comptable_id', $this->caisseSiege->compte_comptable_id)
            ->where('site_id', $this->siege->id)
            ->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) as solde')
            ->value('solde');
        $this->assertEquals(50_000_000.0, (float) $solde);
    }

    public function test_confirmer_retour_refuse_sans_contestation_prealable(): void
    {
        $mouvement = $this->creerMouvement(500_000);
        $mouvement = $this->service->envoyer($mouvement, $this->user->id);

        $this->expectException(TransitionMouvementFondsInvalideException::class);
        $this->service->confirmerRetour($mouvement, $this->user->id, 'Sans contestation préalable');
    }

    public function test_recevoir_leve_une_contestation(): void
    {
        $mouvement = $this->creerMouvement(500_000);
        $mouvement = $this->service->envoyer($mouvement, $this->user->id);
        $mouvement = $this->service->contester($mouvement, $this->user->id, 'Erreur initiale du destinataire');

        $recu = $this->service->recevoir($mouvement, $this->user->id, $this->caisseAgence->id);

        $this->assertSame(StatutMouvementFonds::RECU, $recu->statut);
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

    // ── Solde suffisant à l'envoi (règle du 22/09/2026) ─────────────────────────────────────
    //
    // Une caisse/support ne doit jamais pouvoir envoyer un montant supérieur à son solde
    // disponible — vrai pour un mouvement entre agences comme pour un versement de caisse
    // dédiée (déjà couvert par VersementCaisseAgentServiceTest, même garantirSoldeSuffisant()).

    /** Caisse fraîche, jamais alimentée — indépendante de $this->caisseSiege (financée en setUp). */
    private function nouvelleCaisseOrigine(string $libelle = 'Caisse test'): CompteTresorerie
    {
        $site = Site::create(['organization_id' => $this->org->id, 'nom' => $libelle.' — Site', 'type' => 'agence', 'localisation' => 'Conakry']);
        $compteCaisse = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();

        return CompteTresorerie::create([
            'organization_id' => $this->org->id, 'site_id' => $site->id,
            'compte_comptable_id' => $compteCaisse->id, 'type' => 'caisse', 'libelle' => $libelle,
        ]);
    }

    private function creerMouvementDepuis(CompteTresorerie $origine, float $montant): MouvementFonds
    {
        return $this->service->creerBrouillon($this->org->id, [
            'site_origine_id' => $origine->site_id,
            'site_destination_id' => $this->agence->id,
            'compte_tresorerie_origine_id' => $origine->id,
            'montant' => $montant,
        ], $this->user->id);
    }

    public function test_envoyer_refuse_une_caisse_a_solde_nul(): void
    {
        $origine = $this->nouvelleCaisseOrigine();
        $mouvement = $this->creerMouvementDepuis($origine, 500_000);

        $this->assertErreurValidationSur('montant', fn () => $this->service->envoyer($mouvement, $this->user->id));
        $this->assertSame(StatutMouvementFonds::BROUILLON, $mouvement->fresh()->statut);
        $this->assertNull($mouvement->fresh()->piece_comptable_envoi_id);
    }

    public function test_envoyer_refuse_un_solde_inferieur_au_montant(): void
    {
        $origine = $this->nouvelleCaisseOrigine();
        $this->alimenterCaisse($origine, 500_000);
        $mouvement = $this->creerMouvementDepuis($origine, 1_000_000);

        try {
            $this->service->envoyer($mouvement, $this->user->id);
            $this->fail('ValidationException attendue');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('montant', $e->errors());
            // Le message cite le disponible ET le montant demandé — explicite pour l'utilisateur.
            $this->assertStringContainsString('500 000', $e->errors()['montant'][0]);
            $this->assertStringContainsString('1 000 000', $e->errors()['montant'][0]);
        }
        $this->assertSame(StatutMouvementFonds::BROUILLON, $mouvement->fresh()->statut);
    }

    public function test_envoyer_accepte_un_solde_exactement_egal_au_montant(): void
    {
        $origine = $this->nouvelleCaisseOrigine();
        $this->alimenterCaisse($origine, 1_000_000);
        $mouvement = $this->creerMouvementDepuis($origine, 1_000_000);

        $envoye = $this->service->envoyer($mouvement, $this->user->id);

        $this->assertSame(StatutMouvementFonds::ENVOYE, $envoye->statut);
    }

    public function test_envoyer_accepte_un_solde_superieur_au_montant(): void
    {
        $origine = $this->nouvelleCaisseOrigine();
        $this->alimenterCaisse($origine, 1_500_000);
        $mouvement = $this->creerMouvementDepuis($origine, 1_000_000);

        $envoye = $this->service->envoyer($mouvement, $this->user->id);

        $this->assertSame(StatutMouvementFonds::ENVOYE, $envoye->statut);
    }

    public function test_envoyer_refuse_une_caisse_desactivee_apres_la_creation_du_brouillon(): void
    {
        $origine = $this->nouvelleCaisseOrigine();
        $this->alimenterCaisse($origine, 1_000_000);
        $mouvement = $this->creerMouvementDepuis($origine, 500_000);

        // Désactivée APRÈS coup : le brouillon existait déjà quand la caisse était encore utilisable.
        $origine->update(['actif' => false]);

        $this->assertErreurValidationSur('compte_tresorerie_id', fn () => $this->service->envoyer($mouvement, $this->user->id));
    }

    /**
     * Défense en profondeur : creerBrouillon() refuse déjà une caisse jamais validée
     * (cf. SupportTresorerieValidationServiceTest::test_un_mouvement_entre_agences_refuse_un_support_en_brouillon).
     * Ce test contourne ce premier gate (création directe en base) pour prouver qu'envoyer()
     * la refuse LUI AUSSI, pas seulement au premier passage.
     */
    public function test_envoyer_refuse_une_caisse_jamais_validee(): void
    {
        $site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Site brouillon', 'type' => 'agence', 'localisation' => 'Conakry']);
        $compteCaisse = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();
        // 'actif' => false explicite : sans quoi CompteTresorerie::boot() la réputerait
        // automatiquement validée (cf. son docblock) — ici, jamais passée par
        // SupportTresorerieValidationService::valider(), elle reste en brouillon (valide_le = null).
        $origine = CompteTresorerie::create([
            'organization_id' => $this->org->id, 'site_id' => $site->id,
            'compte_comptable_id' => $compteCaisse->id, 'type' => 'caisse', 'libelle' => 'Caisse en brouillon',
            'actif' => false,
        ]);
        $mouvement = MouvementFonds::create([
            'organization_id' => $this->org->id,
            'nature' => 'inter_sites',
            'site_origine_id' => $origine->site_id,
            'site_destination_id' => $this->agence->id,
            'compte_tresorerie_origine_id' => $origine->id,
            'montant' => 100_000,
            'statut' => StatutMouvementFonds::BROUILLON->value,
        ]);

        $this->assertErreurValidationSur('compte_tresorerie_id', fn () => $this->service->envoyer($mouvement, $this->user->id));
    }

    /**
     * Défense en profondeur : creerBrouillon() refuse déjà un montant nul/négatif à la création,
     * et le montant est immuable ensuite (pas de route de modification d'un brouillon). Ce test
     * contourne ce premier gate pour prouver qu'envoyer() le refuse LUI AUSSI.
     */
    public function test_envoyer_refuse_un_montant_nul_ou_negatif(): void
    {
        foreach ([0, -50_000] as $montant) {
            $mouvement = MouvementFonds::create([
                'organization_id' => $this->org->id,
                'nature' => 'inter_sites',
                'site_origine_id' => $this->siege->id,
                'site_destination_id' => $this->agence->id,
                'compte_tresorerie_origine_id' => $this->caisseSiege->id,
                'montant' => $montant,
                'statut' => StatutMouvementFonds::BROUILLON->value,
            ]);

            $this->assertErreurValidationSur('montant', fn () => $this->service->envoyer($mouvement, $this->user->id));
        }
    }

    /**
     * Protection contre le double envoi d'un même solde : chaque appel relit le solde RÉEL sous
     * verrou (lockForUpdate sur la caisse), jamais une valeur mise en cache par le premier appel.
     * Même mécanisme, même preuve que VersementCaisseAgentServiceTest::
     * test_deux_versements_successifs_relisent_le_solde_deja_diminue() pour l'autre nature.
     */
    public function test_deux_envois_successifs_relisent_le_solde_deja_diminue(): void
    {
        $origine = $this->nouvelleCaisseOrigine();
        $this->alimenterCaisse($origine, 800_000);

        $premier = $this->creerMouvementDepuis($origine, 500_000);
        $this->service->envoyer($premier, $this->user->id);

        // Il ne reste que 300 000 : un second envoi de 500 000 depuis la même caisse est refusé,
        // preuve que le solde relu tient compte du premier envoi déjà comptabilisé.
        $second = $this->creerMouvementDepuis($origine, 500_000);
        $this->assertErreurValidationSur('montant', fn () => $this->service->envoyer($second, $this->user->id));
        $this->assertSame(StatutMouvementFonds::BROUILLON, $second->fresh()->statut);

        // Le solde restant (300 000) suffit en revanche pour un envoi plus modeste.
        $troisieme = $this->creerMouvementDepuis($origine, 300_000);
        $envoye = $this->service->envoyer($troisieme, $this->user->id);
        $this->assertSame(StatutMouvementFonds::ENVOYE, $envoye->statut);
    }
}
