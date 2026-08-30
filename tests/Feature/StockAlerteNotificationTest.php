<?php

namespace Tests\Feature;

use App\Enums\StockStatut;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Site;
use App\Models\User;
use App\Models\VarianteStock;
use App\Notifications\StockAlerteNotification;
use App\Services\MouvementStockService;
use App\Services\ProduitSeuilAlerteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\Concerns\HasProduitVariante;
use Tests\TestCase;

/**
 * Alerte email/base de données envoyée aux administrateurs (super_admin/admin_entreprise) d'une
 * organisation quand un couple produit × site FRANCHIT un seuil d'alerte de stock — décision
 * produit du 30/08/2026 : les admins doivent voir l'alerte quelle que soit leur agence de
 * rattachement, jamais filtrée par site (cf. StockAlerteNotification, MouvementStockService::
 * alerterSiFranchissementSeuil()).
 */
class StockAlerteNotificationTest extends TestCase
{
    use HasProduitVariante, RefreshDatabase;

    private Organization $org;

    private Site $siteAlerte;

    private Site $autreSite;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'employe', 'guard_name' => 'web']);

        $this->org = Organization::factory()->create();
        $this->siteAlerte = Site::factory()->for($this->org)->create(['nom' => 'Site Alerte']);
        $this->autreSite = Site::factory()->for($this->org)->create(['nom' => 'Autre agence']);
    }

    /**
     * L'admin n'est rattaché à AUCUN site (ni siteAlerte ni autreSite) : il doit tout de même
     * recevoir l'alerte — jamais restreint à ses propres agences.
     */
    private function makeDestinataire(string $role): User
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole($role);

        return $user;
    }

    private function seedProduitEtStock(int $qte, bool $alerteActive = true, int $seuil = 10): string
    {
        $produit = $this->makeProduitAvecVariante($this->org, [
            'nom' => 'Bidon 20L',
            'alerte_stock_active' => $alerteActive,
        ]);
        $varianteId = $produit->variantePrincipale()->first()->id;

        VarianteStock::updateOrCreate(
            ['produit_variante_id' => $varianteId, 'site_id' => $this->siteAlerte->id],
            ['organization_id' => $this->org->id, 'qte_stock' => $qte],
        );

        app(ProduitSeuilAlerteService::class)->definir($produit, $this->siteAlerte->id, $seuil);

        return $varianteId;
    }

    public function test_alerte_super_admin_et_admin_meme_sils_ne_sont_pas_de_lagence_concernee(): void
    {
        Notification::fake();

        $superAdmin = $this->makeDestinataire('super_admin');
        $admin = $this->makeDestinataire('admin_entreprise');
        $employe = $this->makeDestinataire('employe');

        $varianteId = $this->seedProduitEtStock(qte: 20, seuil: 10);

        // 20 → 5 : franchit le seuil (10), doit alerter.
        MouvementStockService::appliquer(
            varianteId: $varianteId,
            siteId: $this->siteAlerte->id,
            orgId: $this->org->id,
            type: 'sortie',
            quantite: 15,
        );

        Notification::assertSentTo(
            $superAdmin,
            StockAlerteNotification::class,
            fn ($n) => $n->toArray($superAdmin)['statut'] === StockStatut::STOCK_FAIBLE->value,
        );
        Notification::assertSentTo($admin, StockAlerteNotification::class);
        Notification::assertNotSentTo($employe, StockAlerteNotification::class);
    }

    public function test_ne_renvoie_pas_lalerte_tant_que_le_produit_reste_sous_le_seuil(): void
    {
        Notification::fake();

        $admin = $this->makeDestinataire('admin_entreprise');
        $varianteId = $this->seedProduitEtStock(qte: 20, seuil: 10);

        // Franchissement (20 → 5), puis deux mouvements qui restent sous le seuil (5 → 4 → 3).
        MouvementStockService::appliquer($varianteId, $this->siteAlerte->id, $this->org->id, 'sortie', 15);
        MouvementStockService::appliquer($varianteId, $this->siteAlerte->id, $this->org->id, 'sortie', 1);
        MouvementStockService::appliquer($varianteId, $this->siteAlerte->id, $this->org->id, 'sortie', 1);

        Notification::assertSentTimes(StockAlerteNotification::class, 1);
        Notification::assertSentTo($admin, StockAlerteNotification::class);
    }

    public function test_realerte_apres_un_retour_a_disponible_puis_un_nouveau_franchissement(): void
    {
        Notification::fake();

        $admin = $this->makeDestinataire('admin_entreprise');
        $varianteId = $this->seedProduitEtStock(qte: 20, seuil: 10);

        MouvementStockService::appliquer($varianteId, $this->siteAlerte->id, $this->org->id, 'sortie', 15); // 20 → 5 (alerte)
        MouvementStockService::appliquer($varianteId, $this->siteAlerte->id, $this->org->id, 'entree', 20); // 5 → 25 (disponible)
        MouvementStockService::appliquer($varianteId, $this->siteAlerte->id, $this->org->id, 'sortie', 18); // 25 → 7 (alerte)

        Notification::assertSentTimes(StockAlerteNotification::class, 2);
        Notification::assertSentTo($admin, StockAlerteNotification::class);
    }

    public function test_rupture_alerte_meme_si_alerte_stock_active_est_desactivee_sur_le_produit(): void
    {
        Notification::fake();

        $admin = $this->makeDestinataire('admin_entreprise');
        // alerte_stock_active = false : STOCK-ALERTE-004, la rupture reste un fait de
        // disponibilité toujours calculé, indépendant du choix "être alerté".
        $varianteId = $this->seedProduitEtStock(qte: 5, alerteActive: false, seuil: 10);

        MouvementStockService::appliquer($varianteId, $this->siteAlerte->id, $this->org->id, 'sortie', 5); // 5 → 0

        Notification::assertSentTo(
            $admin,
            StockAlerteNotification::class,
            fn ($n) => $n->toArray($admin)['statut'] === StockStatut::RUPTURE->value,
        );
    }

    public function test_pas_dalerte_stock_faible_si_alerte_stock_active_est_desactivee_sur_le_produit(): void
    {
        Notification::fake();

        $admin = $this->makeDestinataire('admin_entreprise');
        $varianteId = $this->seedProduitEtStock(qte: 20, alerteActive: false, seuil: 10);

        MouvementStockService::appliquer($varianteId, $this->siteAlerte->id, $this->org->id, 'sortie', 15); // 20 → 5, reste > 0

        Notification::assertNothingSentTo($admin);
    }

    /**
     * Interrupteur d'organisation existant Paramètres > "Alertes de stock faible"
     * (Parametre::CLE_NOTIFICATIONS_STOCK_ACTIVES) : désactivé, plus aucun email ne part, quel
     * que soit le produit/site.
     */
    public function test_aucune_alerte_si_le_parametre_organisation_est_desactive(): void
    {
        Notification::fake();

        $admin = $this->makeDestinataire('admin_entreprise');
        Parametre::create([
            'organization_id' => $this->org->id,
            'cle' => Parametre::CLE_NOTIFICATIONS_STOCK_ACTIVES,
            'valeur' => '0',
            'type' => Parametre::TYPE_BOOLEAN,
            'groupe' => Parametre::GROUPE_GENERAL,
        ]);

        $varianteId = $this->seedProduitEtStock(qte: 20, seuil: 10);

        MouvementStockService::appliquer($varianteId, $this->siteAlerte->id, $this->org->id, 'sortie', 15);

        Notification::assertNothingSentTo($admin);
    }
}
