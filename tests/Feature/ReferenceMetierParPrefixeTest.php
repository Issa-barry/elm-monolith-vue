<?php

namespace Tests\Feature;

use App\Enums\StatutCommission;
use App\Features\ModuleFeature;
use App\Models\Categorie;
use App\Models\CommandeVente;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Chantier du 31/08/2026 : les références métier utilisent désormais un préfixe par processus
 * (VTE-/DST-/TRF-, cf. ReferenceNumeroService et NatureOperation::prefixeReference()) au lieu du
 * seul CMD- partagé entre vente et distribution. Ce fichier couvre les points explicitement
 * demandés qui ne sont pas déjà couverts par tests/Unit/ReferenceNumeroServiceTest.php
 * (indépendance par organisation/préfixe, reset journalier, absence de doublon — testés là au
 * niveau du générateur) : parcours web/PDV/transfert réels, lisibilité et recherche des
 * anciennes références, affichage dans les commissions.
 */
class ReferenceMetierParPrefixeTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $defaultSite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser([
            'ventes.read', 'ventes.create',
            'logistique.read', 'logistique.create',
            'comptabilite.read',
        ]);

        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);
        $this->defaultSite = Site::where('organization_id', $this->org->id)->firstOrFail();

        Feature::for($this->org)->activate(ModuleFeature::VENTES);
        Feature::for($this->org)->activate(ModuleFeature::PDV);
        Feature::for($this->org)->activate(ModuleFeature::LOGISTIQUE);
    }

    private function defaultCategorie(): Categorie
    {
        return Categorie::firstOrCreate(['organization_id' => $this->org->id, 'nom' => 'Défaut']);
    }

    // ── PDV → VTE- ────────────────────────────────────────────────────────────

    public function test_creation_via_pdv_genere_reference_vte(): void
    {
        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Sachet PDV', 'categorie_id' => $this->defaultCategorie()->id],
            ['prix_vente' => 1000],
        );

        $this->actingAs($this->user)
            ->post(route('pdv.checkout'), [
                'mode' => 'Vente rapide',
                'lignes' => [
                    ['produit_id' => $produit->id, 'quantite' => 3],
                ],
            ])
            ->assertRedirect();

        $commande = CommandeVente::where('organization_id', $this->org->id)->latest()->first();

        $this->assertNotNull($commande);
        $this->assertSame('vente_standard', $commande->nature_operation->value);
        $this->assertMatchesRegularExpression('/^VTE-\d{6}-\d{3}$/', $commande->reference);
    }

    // ── Transfert logistique (web) → TRF- ────────────────────────────────────

    public function test_creation_transfert_logistique_web_genere_reference_trf(): void
    {
        $siteDestination = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Destination',
            'type' => 'depot',
            'localisation' => 'Kindia',
        ]);
        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Sachet Transfert', 'categorie_id' => $this->defaultCategorie()->id],
            ['prix_vente' => 1000],
        );
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'livraison_logistique' => true,
        ]);

        $this->actingAs($this->user)
            ->post(route('logistique.store'), [
                'site_source_id' => $this->defaultSite->id,
                'site_destination_id' => $siteDestination->id,
                'vehicule_id' => $vehicule->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'quantite_demandee' => 5, 'notes' => ''],
                ],
            ])
            ->assertSessionDoesntHaveErrors();

        $transfert = TransfertLogistique::where('organization_id', $this->org->id)->latest()->first();

        $this->assertNotNull($transfert);
        $this->assertMatchesRegularExpression('/^TRF-\d{6}-\d{3}$/', $transfert->reference);
        // code_confirmation reste un code distinct (affiché au chauffeur) — n'entre plus dans
        // la référence elle-même, mais continue d'être généré normalement.
        $this->assertNotEmpty($transfert->code_confirmation);
    }

    // ── Transfert logistique (API backoffice) → TRF- ─────────────────────────

    public function test_creation_transfert_logistique_api_genere_reference_trf(): void
    {
        $siteDestination = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Destination API',
            'type' => 'depot',
            'localisation' => 'Kindia',
        ]);
        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Sachet API', 'categorie_id' => $this->defaultCategorie()->id],
            ['prix_vente' => 1000],
        );

        $response = $this->actingAs($this->user)
            ->postJson(route('api.backoffice.logistique.transferts.store'), [
                'site_source_id' => $this->defaultSite->id,
                'site_destination_id' => $siteDestination->id,
                'lignes' => [
                    ['produit_id' => $produit->id, 'quantite_demandee' => 4],
                ],
            ])
            ->assertCreated();

        $reference = $response->json('reference');
        $this->assertMatchesRegularExpression('/^TRF-\d{6}-\d{3}$/', $reference);

        $transfert = TransfertLogistique::where('organization_id', $this->org->id)->latest()->first();
        $this->assertSame($reference, $transfert->reference);
    }

    // ── Anciennes références CMD-*/TR-* : jamais renommées, toujours lisibles ─

    public function test_ancienne_reference_cmd_reste_lisible_et_recherchable(): void
    {
        $ancienne = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'reference' => 'CMD-010125-007',
        ]);

        // Lisible : la fiche détail affiche la référence historique sans y toucher.
        $this->actingAs($this->user)
            ->get(route('ventes.show', $ancienne))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('commande.reference', 'CMD-010125-007')
            );

        // Recherchable : le provider de recherche générique trouve toujours l'ancien format.
        $response = $this->getJson(route('api.search.global', ['q' => 'CMD-010125']))
            ->assertOk();
        $refs = array_column($response->json('results.commandes.items') ?? [], 'title');
        $this->assertContains('CMD-010125-007', $refs);
    }

    public function test_recherche_par_reference_trouve_ancien_et_nouveau_format(): void
    {
        CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'reference' => 'CMD-020125-001',
        ]);
        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Sachet Recherche', 'categorie_id' => $this->defaultCategorie()->id],
            ['prix_vente' => 1000],
        );
        $this->actingAs($this->user)->post(route('pdv.checkout'), [
            'mode' => 'Vente rapide',
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ]);
        $nouvelle = CommandeVente::where('organization_id', $this->org->id)->latest()->first();
        $this->assertStringStartsWith('VTE-', $nouvelle->reference);

        $resultsCmd = $this->getJson(route('api.search.global', ['q' => 'CMD-020125']))
            ->assertOk()->json('results.commandes.items') ?? [];
        $this->assertContains('CMD-020125-001', array_column($resultsCmd, 'title'));

        $resultsVte = $this->getJson(route('api.search.global', ['q' => $nouvelle->reference]))
            ->assertOk()->json('results.commandes.items') ?? [];
        $this->assertContains($nouvelle->reference, array_column($resultsVte, 'title'));
    }

    // ── Affichage de la référence dans les commissions ───────────────────────

    public function test_la_reference_vte_saffiche_sans_alteration_dans_le_detail_commission(): void
    {
        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Sachet Commission', 'categorie_id' => $this->defaultCategorie()->id],
            ['prix_vente' => 1000],
        );
        $this->actingAs($this->user)->post(route('pdv.checkout'), [
            'mode' => 'Vente rapide',
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 2]],
        ]);
        $commande = CommandeVente::where('organization_id', $this->org->id)->latest()->first();
        $this->assertStringStartsWith('VTE-', $commande->reference);

        $processus = CommissionProcessus::firstOrCreate(
            ['organization_id' => $this->org->id, 'code' => CommissionProcessus::CODE_VENTE],
            [
                'libelle' => 'Vente',
                'declencheur' => 'chargement_valide',
                'strategie_ancrage_site' => 'operation',
                'statut' => 'actif',
            ],
        );
        $enveloppe = CommissionEnveloppe::create([
            'organization_id' => $this->org->id,
            'source_type' => CommandeVente::class,
            'source_id' => $commande->id,
            'processus_id' => $processus->id,
            'cible_type' => 'equipe_livraison',
            'cible_id' => (string) Str::ulid(),
            'montant_total' => 5000,
            'earned_at' => now(),
            'statut' => StatutCommission::IMPAYE->value,
        ]);
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $enveloppe->parts()->create([
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_LIVREUR,
            'beneficiaire_id' => $livreur->id,
            'montant_brut' => 5000,
            'montant_net' => 5000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE->value,
        ]);

        $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.livreur', $livreur->id))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('commission_details.0.reference', $commande->reference)
            );
    }

    // ── Aucune collision multi-préfixes le même jour, même organisation ─────

    public function test_plusieurs_creations_le_meme_jour_ne_collisionnent_jamais(): void
    {
        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Sachet Volume', 'categorie_id' => $this->defaultCategorie()->id],
            ['prix_vente' => 1000],
        );

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($this->user)->post(route('pdv.checkout'), [
                'mode' => 'Vente rapide',
                'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
            ]);
        }

        $references = CommandeVente::where('organization_id', $this->org->id)
            ->orderBy('numero')
            ->pluck('reference');

        $this->assertCount(3, $references);
        $this->assertSame($references->unique()->count(), $references->count(), 'Aucune référence ne doit être dupliquée.');
        $this->assertSame(
            ['VTE-'.now()->format('dmy').'-001', 'VTE-'.now()->format('dmy').'-002', 'VTE-'.now()->format('dmy').'-003'],
            $references->values()->all(),
        );
    }

    // ── Isolation multi-organisations au niveau feature (complète la couverture
    //    unitaire de ReferenceNumeroServiceTest) ──────────────────────────────

    public function test_deux_organisations_ne_partagent_jamais_leur_sequence_vente(): void
    {
        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Sachet OrgA', 'categorie_id' => $this->defaultCategorie()->id],
            ['prix_vente' => 1000],
        );
        $this->actingAs($this->user)->post(route('pdv.checkout'), [
            'mode' => 'Vente rapide',
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ]);
        $commandeOrgA = CommandeVente::where('organization_id', $this->org->id)->latest()->first();

        // Deuxième organisation, entièrement indépendante — même jour, même préfixe.
        $orgB = Organization::factory()->create();
        $userB = $this->makeUserWithPermissions($orgB, ['ventes.read', 'ventes.create']);
        $siteB = Site::create(['organization_id' => $orgB->id, 'nom' => 'Site B', 'type' => 'depot', 'localisation' => 'Kindia']);
        $userB->sites()->attach($siteB->id, ['role' => 'employe', 'is_default' => true]);
        Feature::for($orgB)->activate(ModuleFeature::PDV);
        Parametre::setVentesAutoriserStockNegatif($orgB->id, true);
        $produitB = $this->makeProduitAvecVariante(
            $orgB,
            ['nom' => 'Sachet OrgB', 'categorie_id' => Categorie::create(['organization_id' => $orgB->id, 'nom' => 'Défaut'])->id],
            ['prix_vente' => 1000],
        );

        $this->actingAs($userB)->post(route('pdv.checkout'), [
            'mode' => 'Vente rapide',
            'lignes' => [['produit_id' => $produitB->id, 'quantite' => 1]],
        ]);
        $commandeOrgB = CommandeVente::where('organization_id', $orgB->id)->latest()->first();

        $this->assertStringEndsWith('-001', $commandeOrgA->reference, 'Organisation A doit démarrer à 001.');
        $this->assertStringEndsWith('-001', $commandeOrgB->reference, 'Organisation B ne doit pas hériter du compteur de A — doit aussi démarrer à 001.');
        // Les deux organisations produisent littéralement la même chaîne de référence le même
        // jour (même préfixe, même compteur repartant à 001 chacune) — autorisé depuis que
        // l'unicité de `reference` est scopée par organisation (organization_id, reference),
        // plus globale sur la seule colonne reference.
        $this->assertSame($commandeOrgA->reference, $commandeOrgB->reference);
    }

    /** Sanité : la table de séquence historique n'est plus jamais écrite. */
    public function test_lancien_compteur_commande_sequences_nest_plus_alimente(): void
    {
        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Sachet Legacy', 'categorie_id' => $this->defaultCategorie()->id],
            ['prix_vente' => 1000],
        );

        $this->actingAs($this->user)->post(route('pdv.checkout'), [
            'mode' => 'Vente rapide',
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ]);

        $this->assertSame(0, DB::table('commande_sequences')->count());
        $this->assertGreaterThan(0, DB::table('reference_sequences')->count());
    }
}
