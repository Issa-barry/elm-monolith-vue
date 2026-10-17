<?php

namespace Tests\Feature\Tresorerie;

use App\Enums\EvenementComptable;
use App\Enums\StatutCommandeVente;
use App\Models\CommandeVente;
use App\Models\CompteTresorerie;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\PieceComptable;
use App\Models\Site;
use App\Models\User;
use App\Services\Tresorerie\AgenceEncaissementResolver;
use App\Services\Tresorerie\CaisseAgentService;
use App\Services\Tresorerie\DecaissementSupportResolver;
use App\Services\Tresorerie\DetteInterAgencesService;
use App\Services\Tresorerie\MouvementFondsService;
use App\Services\Tresorerie\MoyensEncaissementResolver;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Lot 3 de l'ADR 0027 — un encaissement Mobile Money passe OBLIGATOIREMENT par le compte Mobile Money
 * de l'agent qui encaisse : jamais directement par celui de l'agence, sans repli sur lui. Un agent sans
 * compte actif pour l'opérateur ne peut pas encaisser en Mobile Money. Les paiements sortants de
 * l'agence gardent les comptes de l'agence, et la dette inter-agences ne dépend pas du support qui
 * reçoit l'argent.
 */
class EncaissementMobileMoneyCompteAgentTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, RefreshDatabase;

    private Site $site;

    private User $encaisseur;

    private CompteTresorerie $compteAgent;

    private CompteTresorerie $compteAgence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['factures.encaisser', 'ventes.read', 'ventes.update', 'tresorerie.read', 'tresorerie.recevoir']);
        $this->site = $this->user->sites()->firstOrFail();

        $this->encaisseur = $this->creerUtilisateurNonAdmin($this->site, ['factures.encaisser', 'ventes.read', 'ventes.update', 'tresorerie.verser'], 'Moussa', 'Sidibé');
        $this->compteAgent = $this->creerCompteMobileMoneyActif($this->site->id, $this->encaisseur->id, 'orange_money');
        $this->compteAgence = $this->creerSupportAgence($this->site->id, 'mobile_money', '561100', 'orange_money');
    }

    private function facture(?Site $site = null, float $montant = 500_000): FactureVente
    {
        $site ??= $this->site;
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'statut' => StatutCommandeVente::LIVREE,
            'total_commande' => $montant,
        ]);

        return FactureVente::factory()->create([
            'organization_id' => $this->org->id,
            'commande_vente_id' => $commande->id,
            'site_id' => $site->id,
            'montant_net' => $montant,
        ]);
    }

    /** @param  array<string, mixed>  $surcharge */
    private function encaisser(FactureVente $facture, array $surcharge = [], ?User $auteur = null)
    {
        return $this->actingAs($auteur ?? $this->encaisseur)->post(route('encaissements.store', $facture), array_merge([
            'montant' => 500_000,
            'date_encaissement' => now()->toDateString(),
            'mode_paiement' => 'mobile_money',
            'compte_tresorerie_id' => $this->compteAgent->id,
            'reference_paiement' => 'OM-CLIENT-001',
        ], $surcharge));
    }

    private function solde(CompteTresorerie $support): float
    {
        return app(TresorerieDisponibiliteService::class)->soldePourSupport($support);
    }

    // ── Encaissement sur le compte de l'agent ────────────────────────────────

    public function test_l_encaissement_credite_le_compte_mobile_money_de_l_agent_jamais_celui_de_l_agence(): void
    {
        $facture = $this->facture();

        $this->encaisser($facture)->assertSessionHasNoErrors();

        $encaissement = EncaissementVente::where('facture_vente_id', $facture->id)->firstOrFail();
        $this->assertSame($this->compteAgent->id, $encaissement->compte_tresorerie_id);
        $this->assertSame('orange_money', $encaissement->operateur_mobile_money->value);

        $piece = PieceComptable::where('source_id', $encaissement->id)->where('type_evenement', EvenementComptable::ENCAISSEMENT_VENTE_RECU->value)->firstOrFail();
        $debit = $piece->lignes()->with('compte')->where('debit', '>', 0)->firstOrFail();
        $this->assertSame('561100001', $debit->compte->numero, 'sous-compte de l\'agent');

        $this->assertSame(500_000.0, $this->solde($this->compteAgent));
        $this->assertSame(0.0, $this->solde($this->compteAgence), 'aucun encaissement ne crédite directement le compte de l\'agence');
    }

    public function test_le_compte_mobile_money_de_l_agence_est_refuse_meme_envoye_de_force(): void
    {
        $this->encaisser($this->facture(), ['compte_tresorerie_id' => $this->compteAgence->id])
            ->assertSessionHasErrors(['compte_tresorerie_id' => MoyensEncaissementResolver::MESSAGE_SANS_COMPTE_MOBILE_MONEY]);

        $this->assertSame(0, EncaissementVente::count());
        $this->assertSame(0.0, $this->solde($this->compteAgence));
    }

    public function test_un_agent_sans_compte_actif_pour_l_operateur_ne_peut_pas_encaisser_en_mobile_money(): void
    {
        $sansCompte = $this->creerUtilisateurNonAdmin($this->site, ['factures.encaisser', 'ventes.read', 'ventes.update'], 'Fatou', 'Camara');
        $autreAgent = $this->creerUtilisateurNonAdmin($this->site, ['factures.encaisser', 'ventes.read', 'ventes.update'], 'Ibrahima', 'Bah');
        $momo = $this->creerCompteMobileMoneyActif($this->site->id, $this->encaisseur->id, 'momo');
        $this->creerSupportAgence($this->site->id, 'mobile_money', '561200', 'momo');

        // Sans aucun compte : ni celui de l'agence, ni celui d'un collègue.
        $this->encaisser($this->facture(), ['compte_tresorerie_id' => $this->compteAgence->id], $sansCompte)
            ->assertSessionHasErrors(['compte_tresorerie_id' => MoyensEncaissementResolver::MESSAGE_SANS_COMPTE_MOBILE_MONEY]);
        $this->encaisser($this->facture(), ['compte_tresorerie_id' => $this->compteAgent->id], $autreAgent)
            ->assertSessionHasErrors('compte_tresorerie_id');

        // Le compte d'un autre opérateur ne sert pas pour celui-ci : c'est l'opérateur du compte qui compte.
        $this->encaisser($this->facture(), ['compte_tresorerie_id' => $momo->id, 'reference_paiement' => 'MOMO-1'])
            ->assertSessionHasNoErrors();
        $this->assertSame('momo', EncaissementVente::firstOrFail()->operateur_mobile_money->value);

        $this->assertSame(1, EncaissementVente::count());
    }

    public function test_un_compte_desactive_ou_d_une_autre_agence_est_refuse(): void
    {
        $kouria = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kouria', 'type' => 'agence', 'localisation' => 'Coyah']);
        $this->encaisseur->sites()->attach($kouria->id, ['role' => 'employe', 'is_default' => false]);
        $compteKouria = $this->creerCompteMobileMoneyActif($kouria->id, $this->encaisseur->id, 'orange_money');

        // Facture de l'agence de l'agent, compte rattaché à Kouria : refusé.
        $this->encaisser($this->facture(), ['compte_tresorerie_id' => $compteKouria->id])->assertSessionHasErrors('compte_tresorerie_id');

        app(CaisseAgentService::class)->mettreAJour($this->compteAgent, ['libelle' => $this->compteAgent->libelle, 'actif' => false]);
        $this->encaisser($this->facture())->assertSessionHasErrors('compte_tresorerie_id');

        $this->assertSame(0, EncaissementVente::count());
    }

    public function test_la_reference_d_un_transfert_ne_peut_pas_justifier_un_encaissement(): void
    {
        $this->alimenterCaisse($this->compteAgent, 100_000);
        app(MouvementFondsService::class)->transfererMobileMoneyAgent($this->org->id, $this->compteAgent, $this->compteAgence->id, 100_000, 0, 'OM-TR-001', null, $this->encaisseur->id);

        $this->encaisser($this->facture(), ['reference_paiement' => ' om-tr-001 '])->assertSessionHasErrors('reference_paiement');
        $this->assertSame(0, EncaissementVente::count());
    }

    // ── Ce que proposent les écrans ──────────────────────────────────────────

    public function test_la_fenetre_d_encaissement_propose_les_comptes_de_l_agent_et_les_banques_de_l_agence(): void
    {
        $banque = $this->creerSupportAgence($this->site->id, 'banque', '521000', null, 'Banque agence');
        $facture = $this->facture();

        $agences = app(AgenceEncaissementResolver::class)->pourEcran($this->encaisseur, [$facture])[$facture->id]['agences'];
        $moyens = collect($agences)->firstWhere('site_id', $this->site->id)['moyens'];
        $comptes = array_column($moyens, 'compte_tresorerie_id');

        $this->assertContains($this->compteAgent->id, $comptes);
        $this->assertContains($banque->id, $comptes);
        $this->assertNotContains($this->compteAgence->id, $comptes, 'le Mobile Money de l\'agence n\'est plus proposé à l\'encaissement');
        $this->assertArrayNotHasKey('numero', collect($moyens)->firstWhere('compte_tresorerie_id', $this->compteAgent->id), 'aucun numéro de téléphone exposé');

        $sansCompte = $this->creerUtilisateurNonAdmin($this->site, ['factures.encaisser'], 'Fatou', 'Camara');
        $moyensSansCompte = collect(app(AgenceEncaissementResolver::class)->pourEcran($sansCompte, [$facture])[$facture->id]['agences'])
            ->firstWhere('site_id', $this->site->id)['moyens'];
        $this->assertSame(['virement', 'cheque'], array_column($moyensSansCompte, 'mode_paiement'));
    }

    public function test_les_paiements_sortants_de_l_agence_gardent_ses_comptes_mobile_money(): void
    {
        $options = app(DecaissementSupportResolver::class)->optionsPourSites($this->org->id, [$this->site->id], $this->user);
        $comptes = array_column($options[$this->site->id]['moyens'], 'compte_tresorerie_id');

        $this->assertContains($this->compteAgence->id, $comptes);
        $this->assertNotContains($this->compteAgent->id, $comptes, 'on ne paie jamais depuis le compte d\'un agent');
    }

    // ── Parcours complet et dette inter-agences ──────────────────────────────

    public function test_parcours_complet_encaissement_agent_transfert_vers_l_agence_et_dette_inter_agences_inchangee(): void
    {
        $kouria = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kouria', 'type' => 'agence', 'localisation' => 'Coyah']);
        $this->encaisseur->givePermissionTo('factures.encaisser_autre_agence');
        $factureKouria = $this->facture($kouria);

        // Commande de Kouria encaissée à l'agence de l'agent, sur son compte.
        $this->encaisser($factureKouria, ['site_encaissement_id' => $this->site->id])->assertSessionHasNoErrors();
        $this->assertSame(500_000.0, $this->solde($this->compteAgent));

        $dettes = app(DetteInterAgencesService::class);
        $avant = $dettes->lignes($this->org->id)->firstWhere('site_creancier_id', $kouria->id);
        $this->assertSame(DetteInterAgencesService::A_VERSER, $avant['statut']);
        $this->assertSame(500_000.0, $avant['montant']);

        // Transfert vers l'agence : 495 000 reçus, 5 000 de frais.
        $mouvements = app(MouvementFondsService::class);
        $transfert = $mouvements->transfererMobileMoneyAgent($this->org->id, $this->compteAgent, $this->compteAgence->id, 495_000, 5_000, 'OM-TR-500', null, $this->encaisseur->id);
        $mouvements->recevoir($transfert, $this->user->id, $this->compteAgence->id);

        $this->assertSame(0.0, $this->solde($this->compteAgent));
        $this->assertSame(495_000.0, $this->solde($this->compteAgence));
        $this->assertSame(495_000.0, app(TresorerieDisponibiliteService::class)->disponiblePourSite($this->org->id, $this->site->id, Carbon::now()));

        // La dette reste entière, toujours « à verser » : le transfert ne la solde pas, et les frais ne la diminuent pas.
        $apres = $dettes->lignes($this->org->id)->firstWhere('site_creancier_id', $kouria->id);
        $this->assertSame(DetteInterAgencesService::A_VERSER, $apres['statut']);
        $this->assertSame(500_000.0, $apres['montant']);
    }
}
