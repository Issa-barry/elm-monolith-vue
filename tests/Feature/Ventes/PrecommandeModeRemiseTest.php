<?php

namespace Tests\Feature\Ventes;

use App\Enums\AuditEvent;
use App\Enums\NatureOperation;
use App\Enums\StatutCommandeVente;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\Site;
use App\Models\User;
use App\Models\VarianteStock;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\TestCase;

/**
 * Changement du mode de remise d'une précommande (ADR 0019, décision D16) : retrait ↔ livraison tant
 * que le chargement n'a pas démarré, jamais si le prix ou la nature de l'opération changeraient,
 * permission `ventes.changer_mode_remise`.
 */
class PrecommandeModeRemiseTest extends TestCase
{
    use HasAdminSetup, HasProduitVariante, RefreshDatabase;

    private const PRIX = 20000;

    private Organization $org;

    private User $user;

    private Site $site;

    private Produit $produit;

    private Client $client;

    private Vehicule $vehicule;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->user = $this->makeUserWithPermissions($this->org, [
            'ventes.read', 'ventes.precommander', 'ventes.preparer', 'ventes.changer_mode_remise',
        ]);
        $this->site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'depot', 'localisation' => 'Conakry']);
        $this->user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Pack 1500ml', 'type' => 'fabricable'], ['prix_vente' => self::PRIX, 'prix_usine' => 15000]);
        $this->client = Client::factory()->create(['organization_id' => $this->org->id, 'type' => 'revendeur']);
        $this->vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id, 'nom_vehicule' => 'Abarry', 'immatriculation' => 'AI3462', 'capacite_packs' => 100]);

        $this->stock($this->produit);
        Parametre::setPrecommandeAcompte($this->org->id, false, 0);
    }

    private function stock(Produit $produit): void
    {
        VarianteStock::updateOrCreate(
            ['produit_variante_id' => $produit->variantePrincipale()->first()->id, 'site_id' => $this->site->id],
            ['organization_id' => $this->org->id, 'qte_stock' => 100],
        );
    }

    private function precommande(?string $vehiculeId = null, ?Client $client = null, ?Produit $produit = null): CommandeVente
    {
        $produit ??= $this->produit;
        $this->actingAs($this->user)->post('/backoffice/precommandes', [
            'client_id' => ($client ?? $this->client)->id,
            'mode_remise' => $vehiculeId ? 'livraison' : 'retrait',
            'vehicule_id' => $vehiculeId,
            'date_remise_prevue' => today()->addDay()->toDateString(),
            'lignes' => [['produit_id' => $produit->id, 'qte' => 10, 'prix_vente' => (float) $produit->variantePrincipale()->first()->prix_vente]],
            'acompte_montant' => 0,
        ])->assertSessionHasNoErrors();

        return CommandeVente::where('est_precommande', true)->latest('created_at')->firstOrFail();
    }

    private function preparer(CommandeVente $commande, int $quantite = 10): void
    {
        $this->post("/backoffice/ventes/{$commande->id}/precommande/preparation/lancer")->assertSessionHasNoErrors();
        $this->post("/backoffice/ventes/{$commande->id}/precommande/preparation/valider", [
            'lignes' => $commande->lignes->map(fn ($l) => ['id' => $l->id, 'quantite' => $quantite])->all(),
        ])->assertSessionHasNoErrors();
    }

    private function changer(CommandeVente $commande, ?string $vehiculeId)
    {
        return $this->actingAs($this->user)->post(route('precommandes.mode_remise', $commande), ['vehicule_id' => $vehiculeId]);
    }

    public function test_retrait_prepare_passe_en_livraison_a_charger_sans_changer_le_prix(): void
    {
        $commande = $this->precommande();
        $this->preparer($commande, 8);
        $total = (float) $commande->fresh()->total_commande;

        $this->changer($commande, $this->vehicule->id)->assertSessionHasNoErrors();

        $commande->refresh();
        $this->assertSame($this->vehicule->id, $commande->vehicule_id);
        $this->assertSame(StatutCommandeVente::A_CHARGER, $commande->statut);
        $this->assertNotNull($commande->a_charger_at);
        $this->assertSame($total, (float) $commande->total_commande);
        $this->assertSame(8, (int) $commande->lignes()->first()->quantite_preparee);

        $activite = $commande->activites()->where('action', 'mode_remise_change')->firstOrFail();
        $this->assertSame(['mode' => 'livraison', 'vehicule' => 'Abarry (AI3462)'], $activite->details);
        $this->assertSame($this->user->id, $activite->user_id);
        // Historique : clés libellées par la fiche (« Mode de remise », « Véhicule »).
        $audit = AuditLog::where('auditable_id', $commande->id)->where('event_code', AuditEvent::UPDATED->value)->firstOrFail();
        $this->assertSame(['mode_remise' => 'Retrait', 'vehicule_nom' => null], array_intersect_key($audit->old_values, array_flip(['mode_remise', 'vehicule_nom'])));
        $this->assertSame(['mode_remise' => 'Livraison', 'vehicule_nom' => 'Abarry'], array_intersect_key($audit->new_values, array_flip(['mode_remise', 'vehicule_nom'])));
    }

    public function test_livraison_a_charger_repasse_en_retrait_preparee(): void
    {
        $commande = $this->precommande($this->vehicule->id);
        $this->preparer($commande);
        $this->assertSame(StatutCommandeVente::A_CHARGER, $commande->fresh()->statut);

        $this->changer($commande, null)->assertSessionHasNoErrors();

        $commande->refresh();
        $this->assertNull($commande->vehicule_id);
        $this->assertSame(StatutCommandeVente::PREPAREE, $commande->statut);
        $this->assertNull($commande->a_charger_at);
        $this->assertSame(['mode' => 'retrait', 'vehicule' => null], $commande->activites()->where('action', 'mode_remise_change')->firstOrFail()->details);
    }

    public function test_avant_preparation_le_statut_ne_change_pas(): void
    {
        $commande = $this->precommande();

        $this->changer($commande, $this->vehicule->id)->assertSessionHasNoErrors();

        $this->assertSame(StatutCommandeVente::RESERVEE, $commande->fresh()->statut);
        $this->assertSame($this->vehicule->id, $commande->fresh()->vehicule_id);
    }

    public function test_refuse_une_fois_le_chargement_demarre(): void
    {
        $commande = $this->precommande($this->vehicule->id);
        $this->preparer($commande);
        $commande->fresh()->update(['statut' => StatutCommandeVente::CHARGEMENT_EN_COURS]);

        $this->changer($commande, null)->assertStatus(422);

        $this->assertSame($this->vehicule->id, $commande->fresh()->vehicule_id);
    }

    public function test_refuse_si_le_prix_changerait(): void
    {
        // Client Externe sans véhicule : prix usine ; avec un véhicule de flotte : prix de vente.
        $externe = Client::factory()->create(['organization_id' => $this->org->id, 'type' => 'externe']);
        $materiel = $this->makeProduitAvecVariante($this->org, ['nom' => 'Rouleau', 'type' => 'materiel'], ['prix_vente' => 500, 'prix_usine' => 300, 'prix_achat' => 200]);
        $this->stock($materiel);
        $commande = $this->precommande(null, $externe, $materiel);
        $total = (float) $commande->total_commande;

        $this->changer($commande, $this->vehicule->id)->assertSessionHasErrors('vehicule_id');

        $this->assertNull($commande->fresh()->vehicule_id);
        $this->assertSame($total, (float) $commande->fresh()->total_commande);
        $this->assertFalse($commande->activites()->where('action', 'mode_remise_change')->exists());
    }

    public function test_refuse_si_la_nature_de_l_operation_changerait(): void
    {
        $distributeur = Client::factory()->create(['organization_id' => $this->org->id, 'type' => 'distributeur']);
        $logistique = Vehicule::factory()->create(['organization_id' => $this->org->id, 'livraison_logistique' => true, 'capacite_packs' => 100]);
        $commande = $this->precommande(null, $distributeur);
        $this->assertSame(NatureOperation::VENTE_STANDARD, $commande->nature_operation);

        $this->changer($commande, $logistique->id)->assertSessionHasErrors('vehicule_id');

        $this->assertNull($commande->fresh()->vehicule_id);
    }

    public function test_refuse_sans_permission_ou_hors_organisation(): void
    {
        $commande = $this->precommande();

        $sansDroit = $this->makeUserWithPermissions($this->org, ['ventes.read', 'ventes.preparer']);
        $sansDroit->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);
        $this->actingAs($sansDroit)->post(route('precommandes.mode_remise', $commande), ['vehicule_id' => $this->vehicule->id])->assertForbidden();

        $autreOrg = Organization::factory()->create();
        $etranger = $this->makeUserWithPermissions($autreOrg, ['ventes.read', 'ventes.changer_mode_remise']);
        $siteEtranger = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Kaloum', 'type' => 'depot', 'localisation' => 'Conakry']);
        $etranger->sites()->attach($siteEtranger->id, ['role' => 'employe', 'is_default' => true]);
        $this->actingAs($etranger)->post(route('precommandes.mode_remise', $commande), ['vehicule_id' => $this->vehicule->id])->assertStatus(403);

        $vehiculeEtranger = Vehicule::factory()->create(['organization_id' => $autreOrg->id]);
        $this->changer($commande, $vehiculeEtranger->id)->assertSessionHasErrors('vehicule_id');

        $this->assertNull($commande->fresh()->vehicule_id);
    }

    public function test_la_fiche_propose_le_changement_et_les_vehicules_seulement_avec_la_permission(): void
    {
        $commande = $this->precommande();

        $this->actingAs($this->user)->get("/backoffice/ventes/{$commande->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('precommande.can_changer_mode_remise', true)
                ->where('precommande.vehicules_livraison.0.id', $this->vehicule->id)
                ->where('precommande.vehicules_livraison.0.nom', 'Abarry (AI3462)'));

        $sansDroit = $this->makeUserWithPermissions($this->org, ['ventes.read']);
        $sansDroit->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);
        $this->actingAs($sansDroit)->get("/backoffice/ventes/{$commande->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('precommande.can_changer_mode_remise', false)
                ->where('precommande.vehicules_livraison', []));
    }
}
