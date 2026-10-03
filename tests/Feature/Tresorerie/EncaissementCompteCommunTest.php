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
use App\Models\TiersComptable;
use App\Models\User;
use App\Services\Tresorerie\AgenceEncaissementResolver;
use App\Services\Tresorerie\DetteInterAgencesService;
use App\Services\Tresorerie\MoyensEncaissementResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Encaisser sur un compte commun (ADR 0016, lot 0.3).
 *
 * L'agence qui encaisse reste tracée (`site_encaissement_id`) ; l'argent est détenu par l'agence
 * du compte choisi (`site_detenteur_id`) : écritures, dette et règlements inter-agences la suivent.
 * Le compte commun n'est proposé qu'à l'encaissement, dans ses agences utilisatrices, à côté de
 * leurs propres comptes — l'agent choisit celui sur lequel le client a payé.
 */
class EncaissementCompteCommunTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, RefreshDatabase;

    private Site $siege;

    private Site $cba;

    private Site $kouria;

    private User $agentCba;

    private CompteTresorerie $orangeCba;

    private CompteTresorerie $communKulu;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['factures.encaisser', 'ventes.read', 'ventes.update']);
        $this->siege = $this->user->sites()->firstOrFail();
        $this->cba = Site::create(['organization_id' => $this->org->id, 'nom' => 'Cba', 'type' => 'usine', 'localisation' => 'Cba']);
        $this->kouria = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kouria', 'type' => 'usine', 'localisation' => 'Kouria']);

        $this->agentCba = $this->creerUtilisateurNonAdmin($this->cba, ['factures.encaisser', AgenceEncaissementResolver::PERMISSION], 'Awa', 'Cba');

        $this->orangeCba = $this->creerSupportAgence($this->cba->id, 'mobile_money', '561100', 'orange_money');
        $this->communKulu = $this->creerSupportAgence($this->siege->id, 'mobile_money', '561400', 'kulu', 'Kulu Organisation');
        $this->communKulu->update(['commun' => true, 'numero' => '+224 620 00 00 00']);
        $this->communKulu->agencesUtilisatrices()->sync([$this->siege->id, $this->cba->id]);
    }

    private function facture(Site $site, float $montant = 500_000): FactureVente
    {
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

    private function encaisser(FactureVente $facture, User $auteur, CompteTresorerie $support, string $siteEncaissementId)
    {
        return $this->actingAs($auteur)->post(route('encaissements.store', $facture), [
            'montant' => 100_000,
            'date_encaissement' => now()->toDateString(),
            'mode_paiement' => 'mobile_money',
            'compte_tresorerie_id' => $support->id,
            'reference_paiement' => 'KU-'.uniqid(),
            'site_encaissement_id' => $siteEncaissementId,
        ]);
    }

    private function piece(EncaissementVente $encaissement, EvenementComptable $evenement): ?PieceComptable
    {
        return PieceComptable::where('source_type', $encaissement->getMorphClass())
            ->where('source_id', $encaissement->id)
            ->where('type_evenement', $evenement->value)
            ->first();
    }

    /** @return array<string, array{debit: float, credit: float, site_id: ?string, tiers: ?string}> */
    private function lignes(PieceComptable $piece): array
    {
        return $piece->lignes()->with('compte')->get()
            ->mapWithKeys(fn ($l) => [$l->compte->numero => [
                'debit' => (float) $l->debit,
                'credit' => (float) $l->credit,
                'site_id' => $l->site_id,
                'tiers' => $l->tiers_comptable_id ? TiersComptable::find($l->tiers_comptable_id)?->tiersable_id : null,
            ]])
            ->all();
    }

    public function test_le_compte_commun_est_propose_a_cote_des_comptes_de_l_agence_utilisatrice(): void
    {
        $moyens = collect(app(MoyensEncaissementResolver::class)->pourSite($this->org->id, $this->cba->id, avecComptesCommuns: true));

        $this->assertEqualsCanonicalizing([$this->orangeCba->id, $this->communKulu->id], $moyens->pluck('compte_tresorerie_id')->unique()->all());

        $kulu = $moyens->firstWhere('compte_tresorerie_id', $this->communKulu->id);
        $this->assertSame("Kulu — compte commun ({$this->siege->nom})", $kulu['label']);
        $this->assertSame('+224 620 00 00 00', $kulu['numero']);
        $this->assertSame($this->siege->id, $kulu['site_detenteur_id']);

        $orange = $moyens->firstWhere('compte_tresorerie_id', $this->orangeCba->id);
        $this->assertSame($this->cba->id, $orange['site_detenteur_id']);
    }

    public function test_le_compte_commun_n_est_jamais_propose_hors_de_ses_agences_ni_pour_payer(): void
    {
        $resolver = app(MoyensEncaissementResolver::class);

        $this->assertSame([], $resolver->pourSite($this->org->id, $this->kouria->id, avecComptesCommuns: true));
        // Décaissement (paiement de fiche) : comptes propres seulement, même dans la détentrice.
        $this->assertNotContains($this->communKulu->id, collect($resolver->pourSite($this->org->id, $this->cba->id))->pluck('compte_tresorerie_id')->all());
        $this->assertNotContains($this->communKulu->id, collect($resolver->pourSite($this->org->id, $this->siege->id))->pluck('compte_tresorerie_id')->all());
    }

    public function test_commande_du_siege_payee_sur_son_compte_commun_par_cba_ne_cree_aucune_dette(): void
    {
        $this->encaisser($this->facture($this->siege), $this->agentCba, $this->communKulu, $this->cba->id)
            ->assertSessionHasNoErrors();

        $encaissement = EncaissementVente::firstOrFail();
        $this->assertSame($this->cba->id, $encaissement->site_encaissement_id);
        $this->assertSame($this->siege->id, $encaissement->site_detenteur_id);
        $this->assertFalse($encaissement->estPourAutreAgence());

        // Une seule pièce, chez le siège qui détient l'argent : trésorerie Kulu / client.
        $lignes = $this->lignes($this->piece($encaissement, EvenementComptable::ENCAISSEMENT_VENTE_RECU));
        $this->assertSame(100_000.0, $lignes['561400']['debit']);
        $this->assertSame($this->siege->id, $lignes['561400']['site_id']);
        $this->assertSame(100_000.0, $lignes['411000']['credit']);
        $this->assertArrayNotHasKey('181000', $lignes);
        $this->assertNull($this->piece($encaissement, EvenementComptable::ENCAISSEMENT_VENTE_POUR_COMPTE));

        $this->assertCount(0, app(DetteInterAgencesService::class)->lignes($this->org->id));
    }

    public function test_commande_de_cba_payee_sur_le_compte_commun_du_siege_cree_une_dette_du_siege(): void
    {
        $this->encaisser($this->facture($this->cba), $this->agentCba, $this->communKulu, $this->cba->id)
            ->assertSessionHasNoErrors();

        $encaissement = EncaissementVente::firstOrFail();
        $this->assertTrue($encaissement->estPourAutreAgence());

        $recu = $this->lignes($this->piece($encaissement, EvenementComptable::ENCAISSEMENT_VENTE_RECU));
        $this->assertSame($this->siege->id, $recu['561400']['site_id']);
        $this->assertSame(100_000.0, $recu['181000']['credit']);
        $this->assertSame($this->cba->id, $recu['181000']['tiers']);

        $pourCompte = $this->lignes($this->piece($encaissement, EvenementComptable::ENCAISSEMENT_VENTE_POUR_COMPTE));
        $this->assertSame($this->cba->id, $pourCompte['181000']['site_id']);
        $this->assertSame($this->siege->id, $pourCompte['181000']['tiers']);
        $this->assertSame(100_000.0, $pourCompte['411000']['credit']);

        $ligne = app(DetteInterAgencesService::class)->lignes($this->org->id)->sole();
        $this->assertSame($this->siege->id, $ligne['site_debiteur_id']);
        $this->assertSame($this->cba->id, $ligne['site_creancier_id']);
        $this->assertSame('Cba', $ligne['site_encaissement_nom']);
    }

    public function test_commande_de_cba_payee_sur_le_compte_propre_de_cba_reste_sans_dette(): void
    {
        $this->encaisser($this->facture($this->cba), $this->agentCba, $this->orangeCba, $this->cba->id)
            ->assertSessionHasNoErrors();

        $encaissement = EncaissementVente::firstOrFail();
        $this->assertSame($this->cba->id, $encaissement->site_detenteur_id);
        $this->assertFalse($encaissement->estPourAutreAgence());
    }

    public function test_un_compte_commun_est_refuse_dans_une_agence_qui_ne_l_utilise_pas(): void
    {
        $agentKouria = $this->creerUtilisateurNonAdmin($this->kouria, ['factures.encaisser'], 'Ibrahima', 'Kouria');

        $this->encaisser($this->facture($this->kouria), $agentKouria, $this->communKulu, $this->kouria->id)
            ->assertSessionHasErrors('compte_tresorerie_id');

        $this->assertSame(0, EncaissementVente::count());
    }

    public function test_la_fenetre_de_paiement_propose_le_compte_commun_dans_l_agence_de_l_agent(): void
    {
        $facture = $this->facture($this->cba);
        $agences = app(AgenceEncaissementResolver::class)->pourEcran($this->agentCba, [$facture])[$facture->id];

        $cba = collect($agences['agences'])->firstWhere('site_id', $this->cba->id);
        $this->assertContains($this->communKulu->id, collect($cba['moyens'])->pluck('compte_tresorerie_id')->all());
    }
}
