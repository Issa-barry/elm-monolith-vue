<?php

namespace Tests\Feature\Tresorerie;

use App\Enums\EvenementComptable;
use App\Enums\NatureMouvementFonds;
use App\Enums\StatutMouvementFonds;
use App\Models\CompteTresorerie;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\MouvementFonds;
use App\Models\PieceComptable;
use App\Models\Site;
use App\Models\User;
use App\Services\Tresorerie\CaisseAgentService;
use App\Services\Tresorerie\MouvementFondsService;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Lot 2 de l'ADR 0027 — transfert du compte Mobile Money dédié d'un agent vers le Mobile Money de
 * son agence : même circuit que le versement de caisse (Envoyé → Reçu, ou Contesté → Retour),
 * destination du même opérateur fixée à l'envoi, référence de transaction obligatoire et unique,
 * frais de l'opérateur saisis à chaque transfert (0 accepté) et comptabilisés à part : le montant
 * est ce que l'agence reçoit, le compte de l'agent sort montant + frais.
 */
class TransfertMobileMoneyAgentTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, RefreshDatabase;

    private MouvementFondsService $service;

    private TresorerieDisponibiliteService $disponibilite;

    private Site $site;

    private User $agent;

    private CompteTresorerie $compteAgent;

    private CompteTresorerie $compteAgence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser([
            'tresorerie.read', 'tresorerie.verser', 'tresorerie.envoyer', 'tresorerie.recevoir',
            'tresorerie.rejeter', 'tresorerie.confirmer_retour', 'tresorerie.gerer_soldes_ouverture',
        ]);
        $this->service = app(MouvementFondsService::class);
        $this->disponibilite = app(TresorerieDisponibiliteService::class);
        $this->site = $this->user->sites()->first();

        $this->agent = $this->creerAgent($this->site);
        $this->compteAgent = $this->creerCompteMobileMoneyActif($this->site->id, $this->agent->id, 'orange_money');
        $this->alimenterCaisse($this->compteAgent, 500_000);
        $this->compteAgence = $this->creerSupportAgence($this->site->id, 'mobile_money', '561100', 'orange_money');
    }

    private function transferer(float $montant = 495_000, float $frais = 5_000, ?string $reference = 'OM-TR-001', ?CompteTresorerie $source = null, ?CompteTresorerie $destination = null): MouvementFonds
    {
        return $this->service->transfererMobileMoneyAgent(
            $this->org->id,
            $source ?? $this->compteAgent,
            ($destination ?? $this->compteAgence)->id,
            $montant,
            $frais,
            $reference,
            null,
            $this->agent->id,
        );
    }

    /** @return array<string, array{debit: float, credit: float}> lignes de la pièce, par numéro de compte */
    private function lignes(MouvementFonds $mouvement, EvenementComptable $evenement): array
    {
        $piece = PieceComptable::where('source_type', $mouvement->getMorphClass())
            ->where('source_id', $mouvement->id)
            ->where('type_evenement', $evenement->value)
            ->firstOrFail();

        return $piece->lignes()->with('compte')->get()
            ->mapWithKeys(fn ($l) => [$l->compte->numero => ['debit' => (float) $l->debit, 'credit' => (float) $l->credit]])
            ->all();
    }

    private function solde(CompteTresorerie $support): float
    {
        return $this->disponibilite->soldePourSupport($support);
    }

    // ── Parcours nominal ─────────────────────────────────────────────────────

    public function test_le_transfert_sort_montant_et_frais_du_compte_de_l_agent_et_credite_l_agence_a_la_reception(): void
    {
        $mouvement = $this->transferer(495_000, 5_000, '  om-tr-001 ');

        $this->assertSame(NatureMouvementFonds::TRANSFERT_MOBILE_MONEY, $mouvement->nature);
        $this->assertSame(StatutMouvementFonds::ENVOYE, $mouvement->statut);
        $this->assertSame('OM-TR-001', $mouvement->reference_externe, 'référence normalisée');
        $this->assertSame('mobile_money', $mouvement->moyen_transfert);
        $this->assertEquals(495_000, $mouvement->montant);
        $this->assertEquals(5_000, $mouvement->frais);
        $this->assertSame($this->compteAgence->id, $mouvement->compte_tresorerie_destination_id, 'destination fixée à l\'envoi');
        $this->assertSame($this->site->id, $mouvement->site_destination_id);
        $this->assertSame(500_000.0, $mouvement->sortieTotale());

        // Envoi : le montant part en transit ; frais : charge de l'agence, pièce distincte.
        $sousCompte = $this->compteAgent->compte->numero;
        $envoi = $this->lignes($mouvement, EvenementComptable::MOUVEMENT_FONDS_ENVOYE);
        $this->assertSame(495_000.0, $envoi[$sousCompte]['credit']);
        $this->assertSame(495_000.0, $envoi['588000']['debit']);
        $frais = $this->lignes($mouvement, EvenementComptable::FRAIS_TRANSFERT_FONDS);
        $this->assertSame(5_000.0, $frais[$sousCompte]['credit']);
        $this->assertSame(5_000.0, $frais['631800']['debit']);

        $this->assertSame(0.0, $this->solde($this->compteAgent), '500 000 sortis : 495 000 transférés + 5 000 de frais');
        $this->assertSame(0.0, $this->solde($this->compteAgence), 'l\'agence n\'est créditée qu\'à sa confirmation');
        $this->assertSame(0.0, $this->disponibilite->disponiblePourSite($this->org->id, $this->site->id, Carbon::now()));

        $recu = $this->service->recevoir($mouvement, $this->user->id, $this->compteAgence->id);

        $this->assertSame(StatutMouvementFonds::RECU, $recu->statut);
        $this->assertSame(495_000.0, $this->solde($this->compteAgence), 'l\'agence reçoit le montant du transfert, entier');
        $this->assertSame(495_000.0, $this->disponibilite->disponiblePourSite($this->org->id, $this->site->id, Carbon::now()));
        $reception = $this->lignes($recu, EvenementComptable::MOUVEMENT_FONDS_RECU);
        $this->assertSame(495_000.0, $reception['561100']['debit']);
        $this->assertSame(495_000.0, $reception['588000']['credit']);
    }

    public function test_des_frais_nuls_ne_creent_aucune_piece_de_frais(): void
    {
        $mouvement = $this->transferer(200_000, 0);

        $this->assertEquals(0, $mouvement->frais);
        $this->assertSame(0, PieceComptable::where('source_id', $mouvement->id)->where('type_evenement', EvenementComptable::FRAIS_TRANSFERT_FONDS->value)->count());
        $this->assertSame(300_000.0, $this->solde($this->compteAgent));
    }

    public function test_le_solde_du_compte_doit_couvrir_le_montant_et_les_frais(): void
    {
        $this->assertErreurValidationSur('montant', fn () => $this->transferer(500_000, 1));
        $this->assertErreurValidationSur('montant', fn () => $this->transferer(0, 0));
        $this->assertErreurValidationSur('frais', fn () => $this->transferer(100_000, -1));

        $this->assertSame(0, MouvementFonds::count());
        $this->assertSame(500_000.0, $this->solde($this->compteAgent));

        // À la limite exacte, le transfert passe.
        $this->assertSame(StatutMouvementFonds::ENVOYE, $this->transferer(499_999, 1)->statut);
    }

    // ── Origine et destination ───────────────────────────────────────────────

    public function test_la_destination_est_un_compte_mobile_money_de_l_agence_du_meme_operateur(): void
    {
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kouria', 'type' => 'agence', 'localisation' => 'Coyah']);
        $autreAgent = $this->creerAgent($this->site, 'Fatou', 'Camara');
        $inactif = $this->creerSupportAgence($this->site->id, 'mobile_money', '561400', 'kulu');
        $inactif->update(['actif' => false]);

        $refusees = [
            'autre opérateur' => $this->creerSupportAgence($this->site->id, 'mobile_money', '561200', 'momo'),
            'autre agence' => $this->creerSupportAgence($autreSite->id, 'mobile_money', '561100', 'orange_money'),
            'caisse de l\'agence' => $this->creerSupportAgence($this->site->id, 'caisse', '571000', null, 'Caisse agence'),
            'banque de l\'agence' => $this->creerSupportAgence($this->site->id, 'banque', '521000', null, 'Banque agence'),
            'compte d\'un autre agent' => $this->creerCompteMobileMoneyActif($this->site->id, $autreAgent->id, 'orange_money'),
            'compte désactivé' => $inactif,
        ];

        foreach ($refusees as $destination) {
            $this->assertErreurValidationSur('compte_tresorerie_destination_id', fn () => $this->transferer(destination: $destination));
        }

        $this->assertSame(0, MouvementFonds::count());
    }

    public function test_seul_un_compte_mobile_money_dedie_actif_peut_etre_transfere(): void
    {
        $caisse = $this->creerCaisseActive($this->site->id, $this->agent->id);
        $this->alimenterCaisse($caisse, 100_000);

        $this->assertErreurValidationSur('compte_tresorerie_id', fn () => $this->transferer(50_000, 0, 'R1', $caisse));
        $this->assertErreurValidationSur('compte_tresorerie_id', fn () => $this->transferer(50_000, 0, 'R2', $this->compteAgence));

        $brouillon = app(CaisseAgentService::class)->creerCompteMobileMoney($this->org->id, $this->site->id, $this->creerAgent($this->site, 'Fatou', 'Camara')->id, 'orange_money');
        $this->assertErreurValidationSur('compte_tresorerie_id', fn () => $this->transferer(50_000, 0, 'R3', $brouillon));

        $this->assertSame(0, MouvementFonds::count());
    }

    // ── Référence de transaction ─────────────────────────────────────────────

    public function test_la_reference_est_obligatoire_et_ne_sert_qu_une_fois(): void
    {
        foreach ([null, '', '   '] as $vide) {
            $this->assertErreurValidationSur('reference_externe', fn () => $this->transferer(100_000, 0, $vide));
        }

        $this->transferer(100_000, 0, 'OM-TR-001');

        // Même référence, autre casse et espaces : c'est la même transaction.
        $this->assertErreurValidationSur('reference_externe', fn () => $this->transferer(100_000, 0, ' om-tr-001 '));
        $this->assertSame(1, MouvementFonds::count());

        $this->assertSame(StatutMouvementFonds::ENVOYE, $this->transferer(100_000, 0, 'OM-TR-002')->statut);
    }

    public function test_la_reference_d_un_encaissement_ne_peut_pas_justifier_un_transfert(): void
    {
        $facture = FactureVente::factory()->create(['organization_id' => $this->org->id, 'site_id' => $this->site->id, 'montant_net' => 500_000]);
        EncaissementVente::create([
            'facture_vente_id' => $facture->id,
            'montant' => 100_000,
            'date_encaissement' => now()->toDateString(),
            'mode_paiement' => 'mobile_money',
            'operateur_mobile_money' => 'orange_money',
            'compte_tresorerie_id' => $this->compteAgence->id,
            'reference_paiement' => 'PAIEMENT-CLIENT-1',
            'created_by' => $this->agent->id,
        ]);

        $this->assertErreurValidationSur('reference_externe', fn () => $this->transferer(100_000, 0, 'paiement-client-1'));
        $this->assertSame(0, MouvementFonds::count());
    }

    public function test_la_cle_de_reference_est_propre_a_l_organisation(): void
    {
        $this->assertSame($this->org->id.'|OM-TR-001', MouvementFonds::cleReferenceTransfert($this->org->id, ' om-tr-001 '));
        $this->assertNotSame(
            MouvementFonds::cleReferenceTransfert($this->org->id, 'OM-TR-001'),
            MouvementFonds::cleReferenceTransfert('autre-organisation', 'OM-TR-001'),
        );
        $this->assertNull(MouvementFonds::cleReferenceTransfert($this->org->id, '  '));
        $this->assertSame($this->org->id.'|OM-TR-001', $this->transferer(100_000, 0, 'OM-TR-001')->cle_reference_transfert);
    }

    // ── Contestation et retour ───────────────────────────────────────────────

    public function test_un_transfert_conteste_puis_retourne_rend_le_montant_mais_pas_les_frais(): void
    {
        $mouvement = $this->transferer(495_000, 5_000);

        $conteste = $this->service->contester($mouvement, $this->user->id, 'Rien reçu sur le compte de l\'agence');
        $this->assertSame(StatutMouvementFonds::CONTESTE, $conteste->statut);
        $this->assertSame(0.0, $this->solde($this->compteAgent), 'une contestation seule ne rend rien');

        $retourne = $this->service->confirmerRetour($conteste, $this->user->id, 'Transfert annulé par l\'opérateur');

        $this->assertSame(StatutMouvementFonds::RETOURNE, $retourne->statut);
        $this->assertSame(495_000.0, $this->solde($this->compteAgent), 'le montant revient, les frais restent une charge');
        $this->assertSame(0.0, $this->solde($this->compteAgence));

        $pieceFrais = PieceComptable::where('source_id', $mouvement->id)
            ->where('type_evenement', EvenementComptable::FRAIS_TRANSFERT_FONDS->value)
            ->firstOrFail();
        $this->assertSame('validee', $pieceFrais->statut->value, 'la pièce des frais n\'est jamais contrepassée');
    }

    // ── Effets sur le reste de la trésorerie ─────────────────────────────────

    public function test_le_montant_envoye_est_suivi_en_cours_et_bloque_la_desactivation_du_compte(): void
    {
        $mouvement = $this->transferer(300_000, 0);

        $enCours = $this->disponibilite->versementsEnCours($this->org->id)->keyBy('compte_tresorerie_id');
        $this->assertSame(300_000.0, $enCours[$this->compteAgent->id]['montant']);
        $this->assertSame(1, $enCours[$this->compteAgent->id]['nombre']);

        $this->assertErreurValidationSur('actif', fn () => app(CaisseAgentService::class)
            ->mettreAJour($this->compteAgent, ['libelle' => $this->compteAgent->libelle, 'actif' => false]));

        $this->service->recevoir($mouvement, $this->user->id, $this->compteAgence->id);
        $this->assertCount(0, $this->disponibilite->versementsEnCours($this->org->id));
    }

    public function test_un_transfert_ne_cree_aucun_reglement_inter_agences(): void
    {
        $mouvement = $this->transferer(100_000, 0);

        $this->assertFalse($mouvement->isReglementAgences());
        $this->assertCount(0, $mouvement->lignesReglement);
        $this->assertArrayNotHasKey('181000', $this->lignes($mouvement, EvenementComptable::MOUVEMENT_FONDS_ENVOYE));
    }

    // ── Point d'entrée HTTP et droits ────────────────────────────────────────

    /** @param  array<string, mixed>  $surcharge */
    private function poster(User $par, array $surcharge = [], ?CompteTresorerie $compte = null)
    {
        return $this->actingAs($par)
            ->from(route('comptabilite.tresorerie.supports.index'))
            ->post(route('comptabilite.tresorerie.supports.transferer', $compte ?? $this->compteAgent), array_merge([
                'compte_tresorerie_destination_id' => $this->compteAgence->id,
                'montant' => '495 000',
                'frais' => '5 000',
                'reference_externe' => 'OM-TR-HTTP',
            ], $surcharge));
    }

    public function test_le_titulaire_enregistre_son_transfert_et_les_frais_sont_obligatoires(): void
    {
        $titulaire = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.verser'], 'Aminata', 'Titulaire');
        $compte = $this->creerCompteMobileMoneyActif($this->site->id, $titulaire->id, 'orange_money');
        $this->alimenterCaisse($compte, 500_000);

        // Frais absents ou vides : refusés, jamais supposés nuls.
        $this->poster($titulaire, ['frais' => null], $compte)->assertSessionHasErrors('frais');
        $this->poster($titulaire, ['frais' => ''], $compte)->assertSessionHasErrors('frais');
        $this->poster($titulaire, ['reference_externe' => ''], $compte)->assertSessionHasErrors('reference_externe');
        $this->assertSame(0, MouvementFonds::count());

        $this->poster($titulaire, [], $compte)
            ->assertRedirect(route('comptabilite.tresorerie.supports.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $mouvement = MouvementFonds::firstOrFail();
        $this->assertEquals(495_000, $mouvement->montant, 'montant formaté « 495 000 » accepté');
        $this->assertEquals(5_000, $mouvement->frais);
        $this->assertSame('OM-TR-HTTP', $mouvement->reference_externe);
        $this->assertSame($titulaire->id, $mouvement->sent_by);
    }

    public function test_des_frais_a_zero_saisis_explicitement_sont_acceptes(): void
    {
        $titulaire = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.verser'], 'Aminata', 'Titulaire');
        $compte = $this->creerCompteMobileMoneyActif($this->site->id, $titulaire->id, 'orange_money');
        $this->alimenterCaisse($compte, 100_000);

        $this->poster($titulaire, ['montant' => 100_000, 'frais' => 0], $compte)->assertSessionHasNoErrors();

        $this->assertEquals(0, MouvementFonds::firstOrFail()->frais);
        $this->assertSame(0.0, $this->solde($compte));
    }

    public function test_seuls_le_titulaire_ou_un_responsable_peuvent_enregistrer_le_transfert(): void
    {
        $autreAgent = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.verser'], 'Fatou', 'Autre');
        $lecteur = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.read'], 'Ibrahima', 'Lecteur');
        $responsable = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.verser', 'tresorerie.envoyer'], 'Mariam', 'Responsable');

        $this->poster($autreAgent)->assertForbidden();
        $this->poster($lecteur)->assertForbidden();
        $this->assertSame(0, MouvementFonds::count());

        $this->poster($responsable)->assertSessionHasNoErrors();
        $this->assertSame($responsable->id, MouvementFonds::firstOrFail()->sent_by);

        // Jamais une caisse en espèces par cette route.
        $caisse = $this->creerCaisseActive($this->site->id, $responsable->id);
        $this->poster($responsable, ['reference_externe' => 'OM-TR-CAISSE'], $caisse)->assertForbidden();
    }

    public function test_l_ecran_supports_propose_le_transfert_et_l_agence_est_prevenue(): void
    {
        $sansDestination = $this->creerCompteMobileMoneyActif($this->site->id, $this->agent->id, 'momo');
        $this->alimenterCaisse($sansDestination, 100_000);

        $this->actingAs($this->user)
            ->get(route('comptabilite.tresorerie.supports.index'))
            ->assertInertia(function (Assert $page) use ($sansDestination) {
                $props = $page->toArray()['props'];
                $comptes = collect($props['comptes'])->keyBy('id');

                $this->assertTrue($comptes[$this->compteAgent->id]['peut_transferer']);
                $this->assertFalse($comptes[$this->compteAgent->id]['peut_verser']);
                $this->assertFalse($comptes[$sansDestination->id]['peut_transferer'], 'aucun compte MoMo dans l\'agence');
                $this->assertFalse($comptes[$this->compteAgence->id]['peut_transferer']);
                $this->assertSame([$this->compteAgence->id], array_column($props['destinations_transfert'], 'id'));
                $this->assertSame('orange_money', $props['destinations_transfert'][0]['operateur_mobile_money']);
                $this->assertSame(0, $props['mouvements_fonds_a_confirmer']);
            });

        $this->transferer(300_000, 2_000);

        $this->actingAs($this->user)
            ->get(route('comptabilite.tresorerie.supports.index'))
            ->assertInertia(function (Assert $page) {
                $props = $page->toArray()['props'];
                $ligne = collect($props['comptes'])->firstWhere('id', $this->compteAgent->id);

                $this->assertEquals(300_000, $ligne['en_cours_versement']);
                $this->assertSame(1, $ligne['versements_en_cours']);
                $this->assertEquals(198_000, $ligne['solde']);
                $this->assertSame(1, $props['mouvements_fonds_a_confirmer'], 'l\'agence doit confirmer la réception');
            });
    }
}
