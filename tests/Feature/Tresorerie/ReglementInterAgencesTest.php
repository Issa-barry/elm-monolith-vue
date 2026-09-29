<?php

namespace Tests\Feature\Tresorerie;

use App\Enums\NatureMouvementFonds;
use App\Enums\StatutCommandeVente;
use App\Models\CommandeVente;
use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\EcritureComptable;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\MouvementFonds;
use App\Models\MouvementFondsEncaissement;
use App\Models\Organization;
use App\Models\Site;
use App\Models\TiersComptable;
use App\Services\AnnulationExceptionnelleService;
use App\Services\Tresorerie\DetteInterAgencesService;
use App\Services\Tresorerie\MouvementFondsService;
use App\Services\Tresorerie\ReglementInterAgencesService;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Règlement inter-agences (ADR 0012, lot 1) : la dette née d'un encaissement reçu pour le compte
 * d'une autre agence ne se solde que par un mouvement de fonds de nature « règlement », lié à ces
 * encaissements précis, dont le montant est calculé — jamais saisi. Un encaissement n'est jamais
 * engagé dans deux règlements actifs ; un mouvement « Entre agences » ordinaire ne solde rien.
 * Le compte de liaison (181) du grand livre suit exactement la dette calculée.
 */
class ReglementInterAgencesTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, RefreshDatabase;

    private Site $agenceA;

    private Site $agenceB;

    private Site $agenceC;

    private CompteTresorerie $orangeB;

    private CompteTresorerie $orangeC;

    private CompteTresorerie $caisseA;

    private CompteTresorerie $caisseB;

    private ReglementInterAgencesService $reglements;

    private MouvementFondsService $mouvements;

    private DetteInterAgencesService $dettes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['tresorerie.create', 'ventes.annuler_exceptionnel']);
        $this->agenceA = $this->user->sites()->firstOrFail();
        $this->agenceB = $this->creerSite('Agence Kindia');
        $this->agenceC = $this->creerSite('Agence Labé');

        $this->orangeB = $this->creerSupportAgence($this->agenceB->id, 'mobile_money', '561100', 'orange_money');
        $this->orangeC = $this->creerSupportAgence($this->agenceC->id, 'mobile_money', '561100', 'orange_money');
        $this->caisseA = $this->creerSupportAgence($this->agenceA->id, 'caisse', '571000');
        $this->caisseB = $this->creerSupportAgence($this->agenceB->id, 'caisse', '571000');

        $this->reglements = app(ReglementInterAgencesService::class);
        $this->mouvements = app(MouvementFondsService::class);
        $this->dettes = app(DetteInterAgencesService::class);
    }

    private function creerSite(string $nom, ?Organization $org = null): Site
    {
        return Site::create([
            'organization_id' => ($org ?? $this->org)->id,
            'nom' => $nom,
            'type' => 'depot',
            'localisation' => $nom,
        ]);
    }

    /** Encaissement Mobile Money d'une commande de `$agenceCommande`, reçu par l'agence du support. */
    private function encaissement(float $montant, ?Site $agenceCommande = null, ?CompteTresorerie $support = null): EncaissementVente
    {
        $agenceCommande ??= $this->agenceA;
        $support ??= $this->orangeB;

        $commande = CommandeVente::factory()->create([
            'organization_id' => $agenceCommande->organization_id,
            'site_id' => $agenceCommande->id,
            'statut' => StatutCommandeVente::LIVREE,
            'total_commande' => $montant,
        ]);
        $facture = FactureVente::factory()->create([
            'organization_id' => $agenceCommande->organization_id,
            'commande_vente_id' => $commande->id,
            'site_id' => $agenceCommande->id,
            'montant_net' => $montant,
        ]);

        return EncaissementVente::create([
            'facture_vente_id' => $facture->id,
            'site_encaissement_id' => $support->site_id,
            'montant' => $montant,
            'date_encaissement' => now()->toDateString(),
            'mode_paiement' => 'mobile_money',
            'compte_tresorerie_id' => $support->id,
            'reference_paiement' => 'OM-'.uniqid(),
            'created_by' => $this->user->id,
        ]);
    }

    /** @param  list<EncaissementVente>  $encaissements */
    private function regler(array $encaissements, ?Site $debiteur = null, ?Site $creancier = null, ?CompteTresorerie $origine = null): MouvementFonds
    {
        return $this->reglements->creerBrouillon(
            $this->org->id,
            ($debiteur ?? $this->agenceB)->id,
            ($creancier ?? $this->agenceA)->id,
            array_map(fn (EncaissementVente $e) => $e->id, $encaissements),
            ($origine ?? $this->orangeB)->id,
            $this->user->id,
        );
    }

    private function soldeLiaison(Site $site, Site $contrepartie): float
    {
        $compte = CompteComptable::where('organization_id', $this->org->id)->where('numero', '181000')->firstOrFail();
        $tiers = TiersComptable::where('organization_id', $this->org->id)
            ->where('tiersable_type', $contrepartie->getMorphClass())
            ->where('tiersable_id', $contrepartie->id)
            ->value('id');

        return round((float) EcritureComptable::where('compte_comptable_id', $compte->id)
            ->where('site_id', $site->id)
            ->where('tiers_comptable_id', $tiers)
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as solde')
            ->value('solde'), 2);
    }

    private function statutLigne(EncaissementVente $encaissement): string
    {
        return $this->dettes->lignes($this->org->id)->firstWhere('encaissement_id', $encaissement->id)['statut'];
    }

    private function soldeBversA(): array
    {
        return $this->dettes->soldes($this->org->id)
            ->first(fn ($s) => $s['site_debiteur_id'] === $this->agenceB->id && $s['site_creancier_id'] === $this->agenceA->id);
    }

    // ── Création ─────────────────────────────────────────────────────────────

    public function test_le_montant_est_la_somme_des_encaissements_selectionnes(): void
    {
        $x = $this->encaissement(200_000);
        $y = $this->encaissement(150_000);
        $this->encaissement(150_000); // non sélectionné

        $reglement = $this->regler([$x, $y]);

        $this->assertSame(NatureMouvementFonds::REGLEMENT_AGENCES, $reglement->nature);
        $this->assertSame(350_000.0, (float) $reglement->montant);
        $this->assertSame($this->agenceB->id, $reglement->site_origine_id);
        $this->assertSame($this->agenceA->id, $reglement->site_destination_id);
        $this->assertCount(2, $reglement->lignesReglement);
        $this->assertSame(DetteInterAgencesService::RESERVE, $this->statutLigne($x));

        $solde = $this->soldeBversA();
        $this->assertSame(500_000.0, $solde['a_verser'], 'réservé : l\'argent est encore chez B');
        $this->assertSame(500_000.0, $solde['a_recevoir']);
    }

    public function test_un_encaissement_ne_peut_pas_entrer_dans_deux_reglements_actifs(): void
    {
        $x = $this->encaissement(200_000);
        $this->regler([$x]);

        try {
            $this->regler([$x, $this->encaissement(50_000)]);
            $this->fail('Un second règlement du même encaissement devait être refusé.');
        } catch (ValidationException $e) {
            $this->assertSame([ReglementInterAgencesService::MESSAGE_DEJA_ENGAGE], $e->errors()['encaissements']);
        }

        $this->assertSame(1, MouvementFonds::where('nature', NatureMouvementFonds::REGLEMENT_AGENCES->value)->count());
    }

    public function test_la_base_interdit_elle_meme_un_double_rapprochement(): void
    {
        $x = $this->encaissement(200_000);
        $reglement = $this->regler([$x]);

        $this->expectException(QueryException::class);

        MouvementFondsEncaissement::create([
            'organization_id' => $this->org->id,
            'mouvement_fonds_id' => $reglement->id,
            'encaissement_vente_id' => $x->id,
            'encaissement_actif_id' => $x->id,
            'montant' => 200_000,
        ]);
    }

    public function test_un_reglement_ne_couvre_qu_un_seul_sens(): void
    {
        $bVersA = $this->encaissement(100_000);
        $cVersA = $this->encaissement(100_000, $this->agenceA, $this->orangeC);

        $this->assertErreurValidationSur('encaissements', fn () => $this->regler([$bVersA, $cVersA]));
        $this->assertErreurValidationSur('encaissements', fn () => $this->regler([$bVersA], $this->agenceB, $this->agenceC));
    }

    public function test_un_encaissement_recu_par_l_agence_de_la_commande_n_a_rien_a_reverser(): void
    {
        $memeAgence = $this->encaissement(100_000, $this->agenceB, $this->orangeB);

        $this->assertErreurValidationSur('encaissements', fn () => $this->regler([$memeAgence]));
    }

    public function test_un_encaissement_d_une_autre_organisation_est_introuvable(): void
    {
        $autreOrg = Organization::factory()->create();
        $siteEtranger = $this->creerSite('Ailleurs', $autreOrg);
        $etranger = $this->encaissement(100_000, $siteEtranger, $this->orangeB);

        $this->assertErreurValidationSur('encaissements', fn () => $this->regler([$etranger]));
    }

    public function test_aucun_encaissement_selectionne_est_refuse(): void
    {
        $this->assertErreurValidationSur('encaissements', fn () => $this->regler([]));
    }

    // ── Workflow et écritures ────────────────────────────────────────────────

    public function test_envoi_puis_reception_soldent_la_dette_et_la_liaison(): void
    {
        $x = $this->encaissement(200_000);
        $y = $this->encaissement(300_000);
        $disponibilite = app(TresorerieDisponibiliteService::class);

        $this->assertSame(-500_000.0, $this->soldeLiaison($this->agenceB, $this->agenceA));
        $this->assertSame(500_000.0, $this->soldeLiaison($this->agenceA, $this->agenceB));

        $reglement = $this->mouvements->envoyer($this->regler([$x, $y]), $this->user->id);

        $this->assertSame(DetteInterAgencesService::EN_COURS, $this->statutLigne($x));
        $this->assertSame(0.0, $this->soldeBversA()['a_verser'], 'l\'argent a quitté B');
        $this->assertSame(500_000.0, $this->soldeBversA()['a_recevoir'], 'A ne l\'a pas encore reçu');
        $this->assertSame(0.0, $this->soldeLiaison($this->agenceB, $this->agenceA));
        $this->assertSame(0.0, $disponibilite->soldePourSupport($this->orangeB));

        $this->mouvements->recevoir($reglement, $this->user->id, $this->caisseA->id);

        $this->assertSame(DetteInterAgencesService::VERSE, $this->statutLigne($y));
        $this->assertSame(0.0, $this->soldeBversA()['a_recevoir']);
        $this->assertSame(500_000.0, $this->soldeBversA()['verse']);
        $this->assertSame(0.0, $this->soldeLiaison($this->agenceA, $this->agenceB));
        $this->assertSame(500_000.0, $disponibilite->soldePourSupport($this->caisseA));

        // Le règlement ne passe jamais par le compte de transit des mouvements ordinaires.
        $transit = CompteComptable::where('organization_id', $this->org->id)->where('numero', '588000')->firstOrFail();
        $this->assertSame(0, EcritureComptable::where('compte_comptable_id', $transit->id)->count());
    }

    public function test_un_mouvement_entre_agences_ordinaire_ne_solde_aucune_dette(): void
    {
        $this->encaissement(500_000);

        $ordinaire = $this->mouvements->creerBrouillon($this->org->id, [
            'site_origine_id' => $this->agenceB->id,
            'site_destination_id' => $this->agenceA->id,
            'compte_tresorerie_origine_id' => $this->orangeB->id,
            'montant' => 500_000,
        ], $this->user->id);
        $this->mouvements->recevoir($this->mouvements->envoyer($ordinaire, $this->user->id), $this->user->id, $this->caisseA->id);

        $this->assertSame(500_000.0, $this->soldeBversA()['a_verser']);
        $this->assertSame(-500_000.0, $this->soldeLiaison($this->agenceB, $this->agenceA));
    }

    public function test_annuler_un_reglement_en_brouillon_libere_ses_encaissements(): void
    {
        $x = $this->encaissement(200_000);
        $premier = $this->regler([$x]);

        $this->mouvements->annuler($premier, $this->user->id, 'Erreur de sélection');

        $this->assertSame(DetteInterAgencesService::A_VERSER, $this->statutLigne($x));
        $this->assertNotNull(MouvementFondsEncaissement::where('mouvement_fonds_id', $premier->id)->first(), 'la ligne reste pour l\'historique');

        $second = $this->regler([$x]);
        $this->assertSame(200_000.0, (float) $second->montant);
    }

    public function test_un_retour_confirme_remet_la_dette_et_la_tresorerie_de_l_agence_debitrice(): void
    {
        $x = $this->encaissement(200_000);
        $reglement = $this->mouvements->envoyer($this->regler([$x]), $this->user->id);

        $this->mouvements->contester($reglement, $this->user->id, 'Rien reçu');
        $this->assertSame(DetteInterAgencesService::EN_COURS, $this->statutLigne($x), 'une contestation laisse l\'argent en route');

        $this->mouvements->confirmerRetour($reglement->fresh(), $this->user->id, 'Fonds rapportés à Kindia');

        $this->assertSame(DetteInterAgencesService::A_VERSER, $this->statutLigne($x));
        $this->assertSame(-200_000.0, $this->soldeLiaison($this->agenceB, $this->agenceA));
        $this->assertSame(200_000.0, app(TresorerieDisponibiliteService::class)->soldePourSupport($this->orangeB));
    }

    public function test_le_solde_du_support_d_origine_est_controle_a_l_envoi(): void
    {
        $x = $this->encaissement(200_000);
        // Le règlement part d'un support de B qui n'a rien reçu.
        $reglement = $this->regler([$x], origine: $this->caisseB);

        $this->assertErreurValidationSur('montant', fn () => $this->mouvements->envoyer($reglement, $this->user->id));
    }

    // ── Garde-fous d'annulation ──────────────────────────────────────────────

    public function test_un_encaissement_engage_dans_un_reglement_ne_peut_plus_etre_supprime(): void
    {
        $x = $this->encaissement(200_000);
        $reglement = $this->regler([$x]);

        $this->assertErreurValidationSur('encaissement', fn () => $x->fresh()->delete());

        $this->mouvements->recevoir($this->mouvements->envoyer($reglement, $this->user->id), $this->user->id, $this->caisseA->id);

        $this->assertErreurValidationSur('encaissement', fn () => $x->fresh()->delete());
        $this->assertNotNull($x->fresh());
    }

    public function test_la_route_de_suppression_refuse_un_encaissement_regle(): void
    {
        $x = $this->encaissement(200_000);
        $this->regler([$x]);

        $this->actingAs($this->user)->delete(route('encaissements.destroy', $x))->assertSessionHasErrors('encaissement');

        $this->assertNotNull($x->fresh());
    }

    public function test_l_annulation_exceptionnelle_est_bloquee_apres_reglement(): void
    {
        $x = $this->encaissement(200_000);
        $reglement = $this->mouvements->envoyer($this->regler([$x]), $this->user->id);

        $recap = app(AnnulationExceptionnelleService::class)->recapitulatif($x->facture->commande);

        $this->assertContains($x->messageReglementActif($x->ligneReglementActive()), $recap['blocages']);
        $this->assertStringContainsString($reglement->reference, implode(' ', $recap['blocages']));
    }

    // ── Cohérence avec le grand livre ────────────────────────────────────────

    public function test_la_liaison_du_grand_livre_suit_la_dette_calculee(): void
    {
        $this->encaissement(100_000);
        $envoye = $this->encaissement(250_000);
        $this->encaissement(80_000, $this->agenceA, $this->orangeC);
        $this->mouvements->envoyer($this->regler([$envoye]), $this->user->id);

        foreach ($this->dettes->soldes($this->org->id) as $solde) {
            $debiteur = Site::find($solde['site_debiteur_id']);
            $creancier = Site::find($solde['site_creancier_id']);

            $this->assertSame(-$solde['a_verser'], $this->soldeLiaison($debiteur, $creancier), "à verser {$debiteur->nom} → {$creancier->nom}");
            $this->assertSame($solde['a_recevoir'], $this->soldeLiaison($creancier, $debiteur), "à recevoir {$creancier->nom} ← {$debiteur->nom}");
        }
    }
}
