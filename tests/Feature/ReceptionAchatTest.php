<?php

namespace Tests\Feature;

use App\Enums\StatutCommandeAchat;
use App\Features\ModuleFeature;
use App\Models\CommandeAchat;
use App\Models\EntrepriseTierce;
use App\Models\Fournisseur;
use App\Models\MouvementStock;
use App\Models\Produit;
use App\Models\ReceptionAchatLigne;
use App\Models\RegleValidationRole;
use App\Models\Site;
use App\Models\User;
use App\Models\VarianteStock;
use App\Services\MouvementStockMotifService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Réceptions de bons de commande fournisseurs dans Logistique → Réceptions (ADR 0021) : partielles,
 * multiples, bornées par le reliquat, stock sur l'agence de la commande, périmètre d'agences.
 */
class ReceptionAchatTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $agenceCommande;

    private Site $autreAgence;

    private Produit $produit;

    private User $magasinier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['achats.read']);
        Feature::for($this->org)->activate(ModuleFeature::ACHATS);

        $this->agenceCommande = Site::factory()->for($this->org)->create(['nom' => 'Matoto']);
        $this->autreAgence = Site::factory()->for($this->org)->create(['nom' => 'Kaloum']);
        $this->produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Bouteille 500 ml', 'type' => 'materiel'], ['prix_achat' => 900]);
        $this->magasinier = $this->makeMagasinier([$this->agenceCommande]);
    }

    private function makeMagasinier(array $sites): User
    {
        foreach (['receptions.read', 'receptions.create'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $role = Role::firstOrCreate(['name' => 'magasinier', 'guard_name' => 'web']);
        $role->givePermissionTo(['receptions.read', 'receptions.create']);

        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole($role);
        foreach ($sites as $i => $site) {
            $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => $i === 0]);
        }

        return $user;
    }

    private function commandeValidee(int $qte = 100, float $prix = 1000, StatutCommandeAchat $statut = StatutCommandeAchat::VALIDEE): CommandeAchat
    {
        $entreprise = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'FOURNISSEUR']);
        $fournisseur = Fournisseur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $entreprise->id, 'is_active' => true]);

        $commande = CommandeAchat::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->agenceCommande->id,
            'fournisseur_id' => $fournisseur->id,
            'total_commande' => $qte * $prix,
            'statut' => $statut,
            'validee_at' => $statut === StatutCommandeAchat::A_VALIDER ? null : now(),
        ]);
        $commande->lignes()->create([
            'variante_id' => $this->produit->variantes()->first()->id,
            'qte' => $qte,
            'prix_achat_snapshot' => $prix,
            'total_ligne' => $qte * $prix,
            'libelle_snapshot' => 'Bouteille 500 ml',
        ]);

        return $commande;
    }

    private function receptionner(CommandeAchat $commande, int $qte, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->magasinier)->post(route('achats.receptions.store', $commande), [
            'date_reception' => now()->toDateString(),
            'lignes' => [['id' => $commande->lignes()->first()->id, 'qte_recue' => $qte]],
        ]);
    }

    private function stock(Site $site): int
    {
        return (int) VarianteStock::where('produit_variante_id', $this->produit->variantes()->first()->id)
            ->where('site_id', $site->id)
            ->value('qte_stock');
    }

    public function test_reception_partielle_alimente_le_stock_de_l_agence_de_la_commande(): void
    {
        $commande = $this->commandeValidee(100);

        $this->receptionner($commande, 60)->assertSessionHasNoErrors();

        $this->assertSame(StatutCommandeAchat::PARTIELLEMENT_RECEPTIONNEE, $commande->fresh()->statut);
        $this->assertSame(60, $commande->lignes()->first()->qte_recue);
        $this->assertSame(60, $this->stock($this->agenceCommande));
        $this->assertSame(0, $this->stock($this->autreAgence));

        $ligne = ReceptionAchatLigne::firstOrFail();
        $mouvement = MouvementStock::findOrFail($ligne->mouvement_stock_id);
        $this->assertSame(ReceptionAchatLigne::class, $mouvement->source_type);
        $this->assertSame($this->agenceCommande->id, $mouvement->site_id);
        $this->assertSame(1000.0, (float) $ligne->cout_unitaire);
        $this->assertStringStartsWith('RCA-', $ligne->reception->reference);
    }

    public function test_plusieurs_receptions_jusqu_a_receptionnee(): void
    {
        $commande = $this->commandeValidee(100);

        $this->receptionner($commande, 60)->assertSessionHasNoErrors();
        $this->receptionner($commande, 40)->assertSessionHasNoErrors();

        $this->assertSame(StatutCommandeAchat::RECEPTIONNEE, $commande->fresh()->statut);
        $this->assertSame(2, $commande->receptions()->count());
        $this->assertSame(100, $this->stock($this->agenceCommande));

        $this->receptionner($commande, 1)->assertSessionHasErrors('reception');
    }

    public function test_depassement_du_reliquat_refuse_sans_aucun_effet(): void
    {
        $commande = $this->commandeValidee(100);
        $this->receptionner($commande, 60);

        $this->receptionner($commande, 41)->assertSessionHasErrors('lignes.0.qte_recue');

        $this->assertSame(60, $commande->lignes()->first()->qte_recue);
        $this->assertSame(60, $this->stock($this->agenceCommande));
        $this->assertSame(1, $commande->receptions()->count());
    }

    public function test_une_reception_ne_peut_pas_preceder_la_date_d_achat(): void
    {
        $commande = $this->commandeValidee(10);
        $commande->update(['date_achat' => now()->toDateString()]);

        $this->actingAs($this->magasinier)->post(route('achats.receptions.store', $commande), [
            'date_reception' => now()->subDay()->toDateString(),
            'lignes' => [['id' => $commande->lignes()->first()->id, 'qte_recue' => 5]],
        ])->assertSessionHasErrors('date_reception');
        $this->assertSame(0, $this->stock($this->agenceCommande));

        // Le jour de l'achat : acceptée.
        $this->receptionner($commande, 5)->assertSessionHasNoErrors();
        $this->assertSame(5, $this->stock($this->agenceCommande));
    }

    public function test_une_commande_non_validee_ne_peut_pas_etre_receptionnee(): void
    {
        $commande = $this->commandeValidee(10, 1000, StatutCommandeAchat::A_VALIDER);

        $this->receptionner($commande, 5)->assertSessionHasErrors('reception');
        $this->assertSame(0, $this->stock($this->agenceCommande));
    }

    public function test_reception_refusee_hors_des_agences_de_l_utilisateur_ou_sans_permission(): void
    {
        $commande = $this->commandeValidee(10);

        $this->receptionner($commande, 5, $this->makeMagasinier([$this->autreAgence]))->assertForbidden();
        $this->receptionner($commande, 5, $this->makeUserWithPermissions($this->org, ['receptions.read']))->assertForbidden();
        $this->assertSame(0, $this->stock($this->agenceCommande));
    }

    public function test_prix_achat_mis_a_jour_sauf_s_il_atteint_le_prix_de_vente(): void
    {
        $commande = $this->commandeValidee(10, 1200);
        $this->receptionner($commande, 5)->assertSessionHasNoErrors();
        $this->assertSame(1200, (int) $this->produit->variantes()->first()->prix_achat);

        $revente = $this->makeProduitAvecVariante($this->org, ['nom' => 'Pack revendu', 'type' => 'achat_vente'], ['prix_achat' => 1500, 'prix_vente' => 2000]);
        $commande = $this->commandeValidee(10, 2500);
        $commande->lignes()->update(['variante_id' => $revente->variantes()->first()->id]);

        $this->receptionner($commande, 5)->assertSessionHasNoErrors()->assertSessionHas('warning');
        $this->assertSame(1500, (int) $revente->variantes()->first()->prix_achat);
    }

    public function test_cloture_du_reliquat(): void
    {
        $commande = $this->commandeValidee(100);
        $this->receptionner($commande, 60);
        $this->user->givePermissionTo(Permission::firstOrCreate(['name' => 'achats.annuler', 'guard_name' => 'web']));
        RegleValidationRole::create(['organization_id' => $this->org->id, 'domaine' => RegleValidationRole::DOMAINE_ACHATS, 'role_name' => 'admin_entreprise', 'perimetre' => 'toutes_agences']);

        $this->actingAs($this->user)
            ->patch(route('achats.cloturer', $commande), ['motif_cloture' => 'Fournisseur en rupture'])
            ->assertSessionHasNoErrors();

        $this->assertSame(StatutCommandeAchat::CLOTUREE, $commande->fresh()->statut);
        $this->receptionner($commande, 1)->assertSessionHasErrors('reception');
    }

    public function test_motif_de_stock_reception_achat_avec_reference(): void
    {
        $commande = $this->commandeValidee(10);
        $this->receptionner($commande, 5);

        $mouvement = MouvementStockMotifService::annoter(MouvementStock::all())->first();

        $this->assertSame(MouvementStockMotifService::KEY_RECEPTION_ACHAT, $mouvement->motif_type);
        $this->assertSame('Réception achat — '.$commande->reference, $mouvement->motif_label);
    }

    public function test_le_stock_entre_sur_l_agence_de_la_commande_meme_si_le_receptionnaire_a_une_autre_agence_par_defaut(): void
    {
        $commande = $this->commandeValidee(10);
        $multiAgences = $this->makeMagasinier([$this->autreAgence, $this->agenceCommande]);

        $this->receptionner($commande, 4, $multiAgences)->assertSessionHasNoErrors();

        $this->assertSame(4, $this->stock($this->agenceCommande));
        $this->assertSame(0, $this->stock($this->autreAgence));
    }

    public function test_le_super_administrateur_ne_receptionne_que_dans_les_agences_auxquelles_il_est_rattache(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $superAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $superAdmin->assignRole('super_admin');
        $superAdmin->sites()->attach($this->autreAgence->id, ['role' => 'employe', 'is_default' => true]);
        $commande = $this->commandeValidee(10);

        $this->receptionner($commande, 5, $superAdmin)->assertForbidden();
        $this->assertSame(0, $this->stock($this->agenceCommande));

        $superAdmin->sites()->attach($this->agenceCommande->id, ['role' => 'employe', 'is_default' => false]);
        $this->receptionner($commande, 5, $superAdmin->fresh())->assertSessionHasNoErrors();
        $this->assertSame(5, $this->stock($this->agenceCommande));
    }

    public function test_ecran_logistique_liste_les_commandes_validees_des_agences_de_l_utilisateur(): void
    {
        $this->commandeValidee(10);
        $this->commandeValidee(10, 1000, StatutCommandeAchat::A_VALIDER);

        $this->actingAs($this->magasinier)
            ->get(route('logistique.receptions-fournisseurs.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('commandes.total', 1)->where('commandes.data.0.peut_receptionner', true));

        $this->actingAs($this->makeMagasinier([$this->autreAgence]))
            ->get(route('logistique.receptions-fournisseurs.index'))
            ->assertInertia(fn ($page) => $page->where('commandes.total', 0));
    }
}
