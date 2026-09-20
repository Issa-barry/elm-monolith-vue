<?php

namespace Tests\Feature;

use App\Enums\StatutCommandeVente;
use App\Models\CommandeVente;
use App\Models\FactureVente;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Régression du 19/09/2026 : une vente directe encaissée en totalité restait affichée
 * « À encaisser » (libellé du statut FACTURATION) à côté de « Facture : Payée », tant que ses
 * commissions n'étaient pas versées. `statutAffichage()` dérive un statut d'écran cohérent avec la
 * facture, sans toucher au statut brut du workflow ni à `statut_label` (API, mobile).
 */
class CommandeVenteStatutAffichageTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read']);

        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Agence Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);
    }

    private function makeCommandeFacturation(bool $facturePayee): CommandeVente
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'statut' => StatutCommandeVente::FACTURATION,
        ]);

        $facture = FactureVente::factory();
        $facture = ($facturePayee ? $facture->payee() : $facture->impayee())->create([
            'organization_id' => $this->org->id,
            'commande_vente_id' => $commande->id,
            'montant_net' => 5000,
        ]);
        $commande->setRelation('facture', $facture);

        return $commande;
    }

    // ── Modèle ────────────────────────────────────────────────────────────────

    public function test_facturation_avec_facture_payee_n_est_plus_a_encaisser(): void
    {
        $commande = $this->makeCommandeFacturation(facturePayee: true);

        $this->assertSame([
            'value' => CommandeVente::STATUT_AFFICHAGE_COMMISSIONS_A_VERSER,
            'label' => 'Commissions à verser',
        ], $commande->statutAffichage());
    }

    public function test_facturation_avec_facture_non_soldee_reste_a_encaisser(): void
    {
        $commande = $this->makeCommandeFacturation(facturePayee: false);

        $this->assertSame([
            'value' => 'facturation',
            'label' => 'À encaisser',
        ], $commande->statutAffichage());
    }

    public function test_facturation_sans_facture_reste_a_encaisser(): void
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'statut' => StatutCommandeVente::FACTURATION,
        ]);

        $this->assertSame('À encaisser', $commande->statutAffichage()['label']);
    }

    public function test_un_autre_statut_avec_facture_payee_garde_son_libelle(): void
    {
        $commande = $this->makeCommandeFacturation(facturePayee: true);
        $commande->statut = StatutCommandeVente::LIVREE;

        $this->assertSame([
            'value' => 'livree',
            'label' => 'Livrée',
        ], $commande->statutAffichage());
    }

    // ── Fiche ─────────────────────────────────────────────────────────────────

    public function test_fiche_expose_commissions_a_verser_sans_toucher_au_statut_brut(): void
    {
        $commande = $this->makeCommandeFacturation(facturePayee: true);

        // commission_eligible_snapshot=true par défaut (factory) et aucune tentative de génération
        // réussie : cloturerSiComplete(), appelé par la fiche, ne clôture jamais silencieusement.
        $this->actingAs($this->user)
            ->get(route('ventes.show', $commande))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('commande.statut', 'facturation')
                ->where('commande.statut_label', 'À encaisser')
                ->where('commande.statut_affichage.value', 'commissions_a_verser')
                ->where('commande.statut_affichage.label', 'Commissions à verser')
                ->where('facture.statut', 'payee')
            );
    }

    public function test_fiche_garde_a_encaisser_tant_que_la_facture_n_est_pas_soldee(): void
    {
        $commande = $this->makeCommandeFacturation(facturePayee: false);

        $this->actingAs($this->user)
            ->get(route('ventes.show', $commande))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('commande.statut_affichage.value', 'facturation')
                ->where('commande.statut_affichage.label', 'À encaisser')
            );
    }

    // ── Liste ─────────────────────────────────────────────────────────────────

    public function test_liste_expose_le_statut_affichage_d_une_vente_encaissee(): void
    {
        $this->makeCommandeFacturation(facturePayee: true);

        $this->actingAs($this->user)
            ->get(route('ventes.index', ['periode' => 'all']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('commandes', 1)
                ->where('commandes.0.statut', 'facturation')
                ->where('commandes.0.statut_affichage.value', 'commissions_a_verser')
                ->where('commandes.0.statut_affichage.label', 'Commissions à verser')
            );
    }

    public function test_liste_expose_a_encaisser_pour_une_vente_non_soldee(): void
    {
        $this->makeCommandeFacturation(facturePayee: false);

        $this->actingAs($this->user)
            ->get(route('ventes.index', ['periode' => 'all']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('commandes', 1)
                ->where('commandes.0.statut_affichage.label', 'À encaisser')
            );
    }
}
