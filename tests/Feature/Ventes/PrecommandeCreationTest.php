<?php

namespace Tests\Feature\Ventes;

use App\Enums\StatutCommandeVente;
use App\Enums\StatutFactureVente;
use App\Enums\StatutReservationStock;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CompteComptable;
use App\Models\EcritureComptable;
use App\Models\EncaissementVente;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\Site;
use App\Models\StockReservation;
use App\Models\User;
use App\Models\VarianteStock;
use App\Services\CommandeVenteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\TestCase;

/**
 * Création d'une précommande (ADR 0019, docs/precommandes.md § 7.2) : réservation stricte, facture
 * « Créée », acompte paramétrable crédité en avance client (419100) — tout ou rien.
 */
class PrecommandeCreationTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasProduitVariante, RefreshDatabase;

    private Organization $org;

    private User $user;

    private Site $site;

    private Produit $produit;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->user = $this->makeUserWithPermissions($this->org, ['ventes.read', 'ventes.precommander']);

        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Matoto',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack Bouteille de 1500ml', 'type' => 'fabricable'],
            ['prix_vente' => 20000, 'prix_usine' => 15000],
        );
        $this->client = Client::factory()->create(['organization_id' => $this->org->id, 'type' => 'externe']);

        $this->seedStock(100);
        $this->creerCaisseActivePour($this->user, $this->site->id);
        // Choix explicite de l'organisation (D6) : sans lui, aucune précommande n'est possible.
        Parametre::setPrecommandeAcompte($this->org->id, false, 0);
    }

    private function seedStock(int $qte): void
    {
        VarianteStock::updateOrCreate(
            ['produit_variante_id' => $this->varianteId(), 'site_id' => $this->site->id],
            ['organization_id' => $this->org->id, 'qte_stock' => $qte],
        );
    }

    private function varianteId(): string
    {
        return $this->produit->variantePrincipale()->first()->id;
    }

    /** @return array<string, mixed> */
    private function payload(int $qte = 10, float $acompte = 0, array $overrides = []): array
    {
        return array_merge([
            'client_id' => $this->client->id,
            'mode_remise' => 'retrait',
            'date_remise_prevue' => today()->addDays(3)->toDateString(),
            'lignes' => [['produit_id' => $this->produit->id, 'qte' => $qte, 'prix_vente' => 20000]],
            'acompte_montant' => $acompte,
            'mode_paiement' => 'especes',
        ], $overrides);
    }

    private function exigerAcompte(int $pct): void
    {
        Parametre::setPrecommandeAcompte($this->org->id, true, $pct);
    }

    private function assertRienEnregistre(): void
    {
        $this->assertSame(0, CommandeVente::count());
        $this->assertSame(0, EncaissementVente::count());
        $this->assertSame(0, StockReservation::count());
        $this->assertSame(0, (int) VarianteStock::where('produit_variante_id', $this->varianteId())->value('qte_reservee'));
    }

    // ── Cas nominal ───────────────────────────────────────────────────────────

    public function test_precommande_avec_acompte_reserve_le_stock_et_garde_la_facture_creee(): void
    {
        $this->exigerAcompte(30);

        $this->actingAs($this->user)
            ->post('/backoffice/precommandes', $this->payload(10, 60000))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $commande = CommandeVente::with('facture.encaissements')->sole();
        $this->assertTrue($commande->est_precommande);
        $this->assertSame(StatutCommandeVente::RESERVEE, $commande->statut);
        $this->assertSame(today()->addDays(3)->toDateString(), $commande->date_remise_prevue->toDateString());
        $this->assertNull($commande->vehicule_id);
        $this->assertSame(200000.0, (float) $commande->total_commande);

        // Stock réservé, jamais sorti.
        $this->assertDatabaseHas('variante_stocks', [
            'produit_variante_id' => $this->varianteId(),
            'site_id' => $this->site->id,
            'qte_stock' => 100,
            'qte_reservee' => 10,
        ]);
        $this->assertSame(StatutReservationStock::ACTIVE, StockReservation::sole()->statut);

        // Facture « Créée » malgré l'acompte : jamais « Partiel » ni « Payée » avant la remise.
        $this->assertSame(StatutFactureVente::CREEE, $commande->facture->fresh()->statut_facture);
        $acompte = $commande->facture->encaissements->sole();
        $this->assertTrue($acompte->est_acompte);
        $this->assertSame(60000.0, (float) $acompte->montant);
        $this->assertSame($this->site->id, $acompte->site_encaissement_id);

        // Écriture : crédit sur les avances clients (419100), jamais sur le compte client.
        $compte = fn (string $numero) => CompteComptable::where('organization_id', $this->org->id)->where('numero', $numero)->firstOrFail();
        $this->assertSame(60000.0, (float) EcritureComptable::where('compte_comptable_id', $compte('419100')->id)->sum('credit'));
        $this->assertSame(0.0, (float) EcritureComptable::where('compte_comptable_id', $compte('411000')->id)->sum('credit'));

        // Aucune commission tant que rien n'est remis.
        $this->assertSame(0, $commande->commissions()->count());
    }

    public function test_acompte_non_obligatoire_permet_une_precommande_sans_paiement(): void
    {
        Parametre::setPrecommandeAcompte($this->org->id, false, 0);

        $this->actingAs($this->user)
            ->post('/backoffice/precommandes', $this->payload(10, 0, ['mode_paiement' => null]))
            ->assertSessionHasNoErrors();

        $commande = CommandeVente::sole();
        $this->assertSame(StatutCommandeVente::RESERVEE, $commande->statut);
        $this->assertSame(0, EncaissementVente::count());
        $this->assertSame(10, (int) VarianteStock::where('produit_variante_id', $this->varianteId())->value('qte_reservee'));
    }

    public function test_jamais_configure_la_creation_est_bloquee(): void
    {
        Parametre::where('organization_id', $this->org->id)
            ->where('cle', Parametre::CLE_VENTES_PRECOMMANDE_ACOMPTE_OBLIGATOIRE)
            ->delete();
        Parametre::clearCache($this->org->id);

        $this->actingAs($this->user)
            ->get('/backoffice/precommandes/create')
            ->assertRedirect(route('precommandes.index'))
            ->assertSessionHas('error', CommandeVenteService::MESSAGE_PRECOMMANDE_NON_CONFIGUREE);

        $this->actingAs($this->user)
            ->post('/backoffice/precommandes', $this->payload(5, 0, ['mode_paiement' => null]))
            ->assertRedirect(route('precommandes.index'))
            ->assertSessionHas('error', CommandeVenteService::MESSAGE_PRECOMMANDE_NON_CONFIGUREE);

        $this->assertRienEnregistre();

        $this->actingAs($this->user)
            ->get('/backoffice/precommandes')
            ->assertInertia(fn (Assert $page) => $page
                ->where('can_creer_precommande', false)
                ->where('raison_blocage_precommande', CommandeVenteService::MESSAGE_PRECOMMANDE_NON_CONFIGUREE));
    }

    // ── Refus : rien n'est enregistré ─────────────────────────────────────────

    public function test_acompte_inferieur_au_minimum_est_refuse_sans_rien_enregistrer(): void
    {
        $this->exigerAcompte(30);

        $this->actingAs($this->user)
            ->post('/backoffice/precommandes', $this->payload(10, 59999))
            ->assertSessionHasErrors('acompte_montant');

        $this->assertRienEnregistre();
    }

    public function test_acompte_superieur_au_total_est_refuse(): void
    {
        $this->actingAs($this->user)
            ->post('/backoffice/precommandes', $this->payload(10, 200001))
            ->assertSessionHasErrors('acompte_montant');

        $this->assertRienEnregistre();
    }

    public function test_stock_insuffisant_est_refuse_meme_si_la_vente_sans_stock_est_autorisee(): void
    {
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);

        $this->actingAs($this->user)
            ->post('/backoffice/precommandes', $this->payload(101, 0, ['mode_paiement' => null]))
            ->assertSessionHasErrors('lignes');

        $this->assertRienEnregistre();
    }

    public function test_especes_sans_caisse_dediee_annule_toute_la_precommande(): void
    {
        $autre = $this->makeUserWithPermissions($this->org, ['ventes.read', 'ventes.precommander']);
        $autre->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($autre)
            ->post('/backoffice/precommandes', $this->payload(10, 50000))
            ->assertSessionHasErrors('mode_paiement');

        $this->assertRienEnregistre();
    }

    public function test_retrait_refuse_un_vehicule_et_livraison_en_exige_un(): void
    {
        $this->actingAs($this->user)
            ->post('/backoffice/precommandes', $this->payload(10, 0, ['mode_remise' => 'livraison', 'mode_paiement' => null]))
            ->assertSessionHasErrors('vehicule_id');

        $this->assertRienEnregistre();
    }

    public function test_client_et_date_sont_obligatoires_et_la_date_ne_peut_pas_etre_passee(): void
    {
        $this->actingAs($this->user)
            ->post('/backoffice/precommandes', $this->payload(10, 0, [
                'client_id' => null,
                'date_remise_prevue' => today()->subDay()->toDateString(),
                'mode_paiement' => null,
            ]))
            ->assertSessionHasErrors(['client_id', 'date_remise_prevue']);

        $this->assertRienEnregistre();
    }

    public function test_client_d_une_autre_organisation_est_refuse(): void
    {
        $autreOrg = Organization::factory()->create();
        $clientEtranger = Client::factory()->create(['organization_id' => $autreOrg->id, 'type' => 'externe']);

        $this->actingAs($this->user)
            ->post('/backoffice/precommandes', $this->payload(10, 0, ['client_id' => $clientEtranger->id, 'mode_paiement' => null]))
            ->assertSessionHasErrors('client_id');

        $this->assertRienEnregistre();
    }

    // ── Autorisations ─────────────────────────────────────────────────────────

    public function test_sans_permission_precommander_creation_et_formulaire_refuses(): void
    {
        $vendeur = $this->makeUserWithPermissions($this->org, ['ventes.read', 'ventes.create']);
        $vendeur->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($vendeur)->get('/backoffice/precommandes/create')->assertForbidden();
        $this->actingAs($vendeur)->post('/backoffice/precommandes', $this->payload(10))->assertForbidden();

        $this->assertRienEnregistre();
    }

    public function test_formulaire_transmet_le_contexte_precommande(): void
    {
        $this->exigerAcompte(40);

        $this->actingAs($this->user)
            ->get('/backoffice/precommandes/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ventes/Create')
                ->where('precommande.acompte_obligatoire', true)
                ->where('precommande.acompte_min_pct', 40)
                ->where('precommande.peut_encaisser_especes', true)
                ->has('produits', 1));
    }

    public function test_formulaire_de_vente_n_a_pas_de_contexte_precommande(): void
    {
        $vendeur = $this->makeUserWithPermissions($this->org, ['ventes.read', 'ventes.create']);
        $vendeur->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($vendeur)
            ->get('/backoffice/ventes/create')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Ventes/Create')->missing('precommande'));
    }

    // ── Liste dédiée ──────────────────────────────────────────────────────────

    public function test_liste_precommandes_ne_montre_que_les_precommandes(): void
    {
        $this->actingAs($this->user)->post('/backoffice/precommandes', $this->payload(10, 0, ['mode_paiement' => null]));
        CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'client_id' => $this->client->id,
            'vehicule_id' => null,
        ]);

        $this->actingAs($this->user)
            ->get('/backoffice/precommandes')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Ventes/Index')
                ->where('liste', 'precommandes')
                ->where('can_precommander', true)
                ->where('can_creer_precommande', true)
                ->has('commandes', 1)
                ->where('commandes.0.est_precommande', true)
                ->where('commandes.0.en_retard', false));
    }

    // ── Paramètres ────────────────────────────────────────────────────────────

    public function test_parametre_acompte_obligatoire_exige_un_taux_strictement_positif(): void
    {
        $admin = $this->makeUserWithPermissions($this->org, ['parametres.update']);
        $admin->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $base = [
            'autoriser_saisie_dessous_qte_max' => false,
            'controle_impayes_actif' => false,
            'seuil_impayes_max' => 0,
            'declencheur_commission_vente' => Parametre::getDeclencheurCommissionVente($this->org->id)->value,
        ];

        Parametre::where('organization_id', $this->org->id)->where('cle', 'like', 'ventes_precommande_%')->delete();
        Parametre::clearCache($this->org->id);

        // Enregistrer d'autres paramètres ne configure jamais les précommandes à la place de l'organisation.
        $this->actingAs($admin)->put('/settings/ventes', $base)->assertSessionHasNoErrors();
        $this->assertFalse(Parametre::isPrecommandeConfiguree($this->org->id));

        $this->actingAs($admin)
            ->put('/settings/ventes', [...$base, 'precommande_acompte_obligatoire' => true, 'precommande_acompte_min_pct' => 0])
            ->assertSessionHasErrors('precommande_acompte_min_pct');
        $this->assertFalse(Parametre::isPrecommandeConfiguree($this->org->id));

        $this->actingAs($admin)
            ->put('/settings/ventes', [...$base, 'precommande_acompte_obligatoire' => true, 'precommande_acompte_min_pct' => 45])
            ->assertSessionHasNoErrors();
        $this->assertTrue(Parametre::isPrecommandeAcompteObligatoire($this->org->id));
        $this->assertSame(45, Parametre::getPrecommandeAcompteMinPct($this->org->id));

        $this->actingAs($admin)
            ->put('/settings/ventes', [...$base, 'precommande_acompte_obligatoire' => false, 'precommande_acompte_min_pct' => 0])
            ->assertSessionHasNoErrors();
        $this->assertFalse(Parametre::isPrecommandeAcompteObligatoire($this->org->id));
    }
}
