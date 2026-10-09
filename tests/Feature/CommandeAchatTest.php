<?php

namespace Tests\Feature;

use App\Enums\StatutCommandeAchat;
use App\Features\ModuleFeature;
use App\Models\CommandeAchat;
use App\Models\EntrepriseTierce;
use App\Models\Fournisseur;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\RegleValidationRole;
use App\Models\Site;
use App\Models\User;
use App\Notifications\CommandeAchatNotification;
use App\Services\Achats\CommandeAchatService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Bon de commande fournisseur (ADR 0021) : création directe « à valider », validation par
 * permission + plafond du rôle, séparation créateur/modificateur ≠ validateur (super administrateur
 * compris), snapshot à la validation, périmètre de lecture, notifications après commit.
 */
class CommandeAchatTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $site;

    private Produit $produit;

    private Fournisseur $fournisseur;

    private const MOTIF_CREATEUR = 'Vous avez créé ce bon de commande : votre rôle ne permet pas de valider vos propres bons, il doit être validé par une autre personne.';

    private const MOTIF_MODIFICATEUR = 'Vous avez modifié ce bon de commande en dernier : votre rôle ne permet pas de valider vos propres bons, il doit être validé par une autre personne.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['achats.read', 'achats.create', 'achats.update', 'achats.delete', 'achats.annuler']);
        Feature::for($this->org)->activate(ModuleFeature::ACHATS);

        $this->site = Site::where('organization_id', $this->org->id)->firstOrFail();
        $this->produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Bouteille 500 ml', 'type' => 'materiel'], ['prix_achat' => 1000, 'sku' => 'BT-500']);
        $this->fournisseur = $this->makeFournisseur($this->org);

        // Périmètre « Peut acheter pour » de l'acheteur de référence (rôle admin_entreprise) :
        // toutes les agences, sans plafond (il crée et voit, ne valide pas).
        $this->regle('admin_entreprise', null);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeFournisseur(Organization $org): Fournisseur
    {
        $entreprise = EntrepriseTierce::create(['organization_id' => $org->id, 'raison_sociale' => 'FOURNISSEUR TEST']);

        return Fournisseur::create(['organization_id' => $org->id, 'entreprise_tierce_id' => $entreprise->id, 'is_active' => true]);
    }

    private function varianteId(?Produit $produit = null): string
    {
        return ($produit ?? $this->produit)->variantes()->firstOrFail()->id;
    }

    /** Utilisateur avec un rôle dédié portant les permissions données, rattaché à des agences. */
    private function makeUtilisateur(string $role, array $permissions, array $sites = [], ?Organization $org = null): User
    {
        $org ??= $this->org;
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $r = Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $r->givePermissionTo($permissions);

        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole($r);
        foreach ($sites ?: [$this->site] as $i => $site) {
            $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => $i === 0]);
        }

        return $user;
    }

    private function makeValidateur(string $role = 'responsable_achat', ?float $plafond = 5_000_000, bool $illimite = false, string $perimetre = 'toutes_agences', array $sites = []): User
    {
        $user = $this->makeUtilisateur($role, ['achats.read', 'achats.valider']);
        $this->regle($role, $plafond, $illimite, $perimetre, $sites);

        return $user;
    }

    private function regle(string $role, ?float $plafond, bool $illimite = false, string $perimetre = 'toutes_agences', array $sites = []): RegleValidationRole
    {
        return RegleValidationRole::create([
            'organization_id' => $this->org->id,
            'domaine' => RegleValidationRole::DOMAINE_ACHATS,
            'role_name' => $role,
            'plafond' => $illimite ? null : $plafond,
            'plafond_illimite' => $illimite,
            'perimetre' => $perimetre,
            'sites' => $sites ?: null,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'site_id' => $this->site->id,
            'fournisseur_id' => $this->fournisseur->id,
            'note' => 'Réassort',
            'lignes' => [['variante_id' => $this->varianteId(), 'qte' => 10, 'prix_achat' => 1000]],
        ], $overrides);
    }

    /** Bon créé via l'application par $this->user (créateur), montant = qte × 1000. */
    private function creerCommande(int $qte = 10): CommandeAchat
    {
        $this->actingAs($this->user)
            ->post(route('achats.store'), $this->payload(['lignes' => [['variante_id' => $this->varianteId(), 'qte' => $qte, 'prix_achat' => 1000]]]))
            ->assertSessionHasNoErrors();

        return CommandeAchat::orderByDesc('numero')->firstOrFail();
    }

    // ── Accès ─────────────────────────────────────────────────────────────────

    public function test_index_returns_200_for_authorized_user(): void
    {
        $this->actingAs($this->user)->get(route('achats.index'))->assertOk();
    }

    public function test_index_redirects_unauthenticated_user(): void
    {
        $this->get(route('achats.index'))->assertRedirect(route('login'));
    }

    public function test_index_returns_403_without_permission(): void
    {
        $this->actingAs($this->makeAdminUser())->get(route('achats.index'))->assertForbidden();
    }

    public function test_create_returns_200(): void
    {
        $this->actingAs($this->user)->get(route('achats.create'))->assertOk();
    }

    // ── Création directe ──────────────────────────────────────────────────────

    public function test_store_cree_un_bon_a_valider_avec_agence_reference_et_snapshot_de_ligne(): void
    {
        $commande = $this->creerCommande(10);

        $this->assertSame(StatutCommandeAchat::A_VALIDER, $commande->statut);
        $this->assertSame($this->site->id, $commande->site_id);
        $this->assertStringStartsWith('BC-', $commande->reference);
        $this->assertSame(10000.0, (float) $commande->total_commande);
        $this->assertSame($this->user->id, $commande->created_by);
        $this->assertSame($this->user->id, $commande->contenu_modifie_par);

        $ligne = $commande->lignes()->firstOrFail();
        $this->assertSame('Bouteille 500 ml', $ligne->libelle_snapshot);
        $this->assertSame('BT-500', $ligne->reference_snapshot);
    }

    public function test_creation_rapide_d_un_fournisseur_depuis_le_bon_de_commande(): void
    {
        Permission::firstOrCreate(['name' => 'fournisseurs.create', 'guard_name' => 'web']);
        $this->user->givePermissionTo('fournisseurs.create');

        $this->actingAs($this->user)
            ->from(route('achats.create'))
            ->post(route('produits.fournisseurs.store'), [
                'raison_sociale' => 'Plastiques de Kaloum',
                'code_pays' => 'GN',
                'phone' => '622000111',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('achats.create'))
            ->assertSessionHas('created_fournisseur_id');

        $cree = Fournisseur::where('organization_id', $this->org->id)->findOrFail(session('created_fournisseur_id'));

        $this->actingAs($this->user)
            ->get(route('achats.create'))
            ->assertInertia(fn ($page) => $page
                ->component('Achats/Form')
                ->where('fournisseurs', fn ($fournisseurs) => collect($fournisseurs)->contains('id', $cree->id)));
    }

    public function test_store_exige_agence_et_fournisseur(): void
    {
        $this->actingAs($this->user)
            ->post(route('achats.store'), $this->payload(['site_id' => null, 'fournisseur_id' => null]))
            ->assertSessionHasErrors(['site_id', 'fournisseur_id']);
    }

    public function test_store_fails_without_lignes(): void
    {
        $this->actingAs($this->user)
            ->post(route('achats.store'), $this->payload(['lignes' => []]))
            ->assertSessionHasErrors('lignes');
    }

    public function test_store_refuse_variante_fournisseur_et_agence_d_une_autre_organisation(): void
    {
        $autre = Organization::factory()->create();
        $produitAutre = $this->makeProduitAvecVariante($autre, ['type' => 'materiel']);
        $siteAutre = Site::factory()->for($autre)->create();

        $this->actingAs($this->user)
            ->post(route('achats.store'), $this->payload([
                'site_id' => $siteAutre->id,
                'fournisseur_id' => $this->makeFournisseur($autre)->id,
                'lignes' => [['variante_id' => $this->varianteId($produitAutre), 'qte' => 1, 'prix_achat' => 10]],
            ]))
            ->assertSessionHasErrors(['site_id', 'fournisseur_id', 'lignes.0.variante_id']);

        $this->assertSame(0, CommandeAchat::count());
    }

    public function test_creation_permise_si_la_regle_du_role_couvre_l_agence_refusee_sinon(): void
    {
        $autreSite = Site::factory()->for($this->org)->create();
        $acheteur = $this->makeUtilisateur('acheteur', ['achats.read', 'achats.create']);
        $this->regle('acheteur', null, false, 'son_agence');

        $this->actingAs($acheteur)
            ->post(route('achats.store'), $this->payload(['site_id' => $autreSite->id]))
            ->assertSessionHasErrors(['site_id' => "Votre rôle ne permet pas d'acheter pour cette agence."]);

        $this->actingAs($acheteur)
            ->post(route('achats.store'), $this->payload())
            ->assertSessionHasNoErrors();
        $this->assertSame(1, CommandeAchat::count());

        $this->actingAs($acheteur)->get(route('achats.create'))
            ->assertInertia(fn ($page) => $page->where('sites', fn ($sites) => collect($sites)->pluck('id')->all() === [$this->site->id]));
    }

    public function test_admin_entreprise_sans_regle_n_a_aucun_acces_automatique(): void
    {
        $commande = $this->creerCommande(10);
        $autreAdmin = $this->makeUserWithPermissions($this->org, ['achats.read', 'achats.create', 'achats.update']);
        $autreAdmin->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);
        RegleValidationRole::where('role_name', 'admin_entreprise')->delete();

        $this->actingAs($autreAdmin)->get(route('achats.index'))
            ->assertInertia(fn ($page) => $page->where('commandes.total', 0)->where('peut_creer', false));
        $this->actingAs($autreAdmin)->get(route('achats.show', $commande))->assertForbidden();
        $this->actingAs($autreAdmin)->get(route('achats.pdf', $commande))->assertForbidden();
        $this->actingAs($autreAdmin)
            ->post(route('achats.store'), $this->payload())
            ->assertSessionHasErrors('site_id');
    }

    public function test_modification_possible_avant_validation_puis_bloquee(): void
    {
        $commande = $this->creerCommande(10);
        $modificateur = $this->makeUtilisateur('acheteur', ['achats.read', 'achats.update']);
        $this->regle('acheteur', null);

        $this->actingAs($modificateur)
            ->put(route('achats.update', $commande), $this->payload(['lignes' => [['variante_id' => $this->varianteId(), 'qte' => 4, 'prix_achat' => 1000]]]))
            ->assertSessionHasNoErrors();

        $commande->refresh();
        $this->assertSame(4000.0, (float) $commande->total_commande);
        $this->assertSame($modificateur->id, $commande->contenu_modifie_par);

        $this->actingAs($this->makeValidateur())->patch(route('achats.valider', $commande))->assertSessionHasNoErrors();

        $this->actingAs($modificateur->fresh())
            ->put(route('achats.update', $commande), $this->payload())
            ->assertSessionHasErrors('commande');
    }

    // ── Validation : permission + plafond ─────────────────────────────────────

    public function test_validation_avec_plafond_suffisant_fige_le_montant_et_le_snapshot(): void
    {
        $commande = $this->creerCommande(10);
        $validateur = $this->makeValidateur('responsable_achat', 5_000_000);

        $this->actingAs($validateur)->patch(route('achats.valider', $commande))->assertSessionHasNoErrors();

        $commande->refresh();
        $this->assertSame(StatutCommandeAchat::VALIDEE, $commande->statut);
        $this->assertSame($validateur->id, $commande->validee_par);
        $this->assertNotNull($commande->validee_at);
        $this->assertSame(10000.0, (float) $commande->montant_valide);
        $this->assertSame('FOURNISSEUR TEST', $commande->fournisseur_nom_snapshot);
        $this->assertSame($this->site->nom, $commande->site_nom_snapshot);
        $this->assertSame('responsable_achat', $commande->validation_regle_snapshot['role']);
        $this->assertSame(5_000_000.0, (float) $commande->validation_regle_snapshot['plafond']);
        $this->assertFalse($commande->validation_regle_snapshot['plafond_illimite']);
    }

    public function test_le_snapshot_ne_bouge_plus_quand_le_referentiel_change(): void
    {
        $commande = $this->creerCommande(10);
        $this->actingAs($this->makeValidateur())->patch(route('achats.valider', $commande));

        $this->fournisseur->entrepriseTierce->update(['raison_sociale' => 'NOUVEAU NOM']);
        $this->site->update(['nom' => 'Agence renommée']);
        $this->produit->update(['nom' => 'Produit renommé']);

        $commande->refresh();
        $this->assertSame('FOURNISSEUR TEST', $commande->fournisseurNom());
        $this->assertNotSame('Agence renommée', $commande->siteNom());
        $this->assertSame('Bouteille 500 ml', $commande->lignes()->first()->libelle_snapshot);
    }

    public function test_validation_refusee_si_le_montant_depasse_le_plafond_egalite_autorisee(): void
    {
        $commande = $this->creerCommande(10);
        $validateur = $this->makeValidateur('agent_achat', 9_999);

        $this->actingAs($validateur)
            ->patch(route('achats.valider', $commande))
            ->assertSessionHasErrors(['validation' => 'Montant supérieur à votre plafond de validation (9 999 GNF).']);
        $this->assertSame(StatutCommandeAchat::A_VALIDER, $commande->fresh()->statut);

        RegleValidationRole::where('role_name', 'agent_achat')->update(['plafond' => 10_000]);
        $this->actingAs($validateur)->patch(route('achats.valider', $commande))->assertSessionHasNoErrors();
        $this->assertSame(StatutCommandeAchat::VALIDEE, $commande->fresh()->statut);
    }

    public function test_validation_refusee_sans_permission_meme_avec_un_plafond(): void
    {
        $commande = $this->creerCommande(10);
        $sansPermission = $this->makeUtilisateur('magasinier', ['achats.read']);
        $this->regle('magasinier', null, true);

        $this->actingAs($sansPermission)->patch(route('achats.valider', $commande))->assertForbidden();
        $this->assertSame(StatutCommandeAchat::A_VALIDER, $commande->fresh()->statut);
    }

    public function test_validation_refusee_sans_regle_le_bon_est_hors_perimetre(): void
    {
        $commande = $this->creerCommande(10);
        $validateur = $this->makeUtilisateur('responsable_achat', ['achats.read', 'achats.valider']);

        $this->actingAs($validateur)->patch(route('achats.valider', $commande))->assertForbidden();
        $this->assertSame(StatutCommandeAchat::A_VALIDER, $commande->fresh()->statut);
    }

    public function test_validation_refusee_avec_un_perimetre_sans_plafond(): void
    {
        $commande = $this->creerCommande(10);
        $validateur = $this->makeValidateur('responsable_achat', null);

        $this->actingAs($validateur)
            ->patch(route('achats.valider', $commande))
            ->assertSessionHasErrors(['validation' => "Votre rôle n'a pas de plafond de validation."]);
    }

    public function test_plusieurs_roles_le_plafond_le_plus_favorable_s_applique(): void
    {
        $commande = $this->creerCommande(10);
        $validateur = $this->makeValidateur('agent_achat', 1_000);
        $this->regle('directeur_achat', 50_000);
        Role::firstOrCreate(['name' => 'directeur_achat', 'guard_name' => 'web']);
        $validateur->assignRole('directeur_achat');

        $this->actingAs($validateur->fresh())->patch(route('achats.valider', $commande))->assertSessionHasNoErrors();
        $this->assertSame('directeur_achat', $commande->fresh()->validation_regle_snapshot['role']);
    }

    public function test_validation_refusee_si_la_regle_ne_couvre_pas_l_agence(): void
    {
        $commande = $this->creerCommande(10);
        $autreSite = Site::factory()->for($this->org)->create();
        $validateur = $this->makeValidateur('responsable_achat', 5_000_000, false, 'agences_selectionnees', [$autreSite->id]);

        $this->actingAs($validateur)->patch(route('achats.valider', $commande))->assertForbidden();
        $this->assertSame(StatutCommandeAchat::A_VALIDER, $commande->fresh()->statut);
    }

    // ── Séparation des tâches ─────────────────────────────────────────────────

    public function test_le_createur_ne_peut_pas_valider_son_propre_bon(): void
    {
        $createur = $this->makeValidateur('responsable_achat', null, true);
        $createur->givePermissionTo(['achats.create']);

        $this->actingAs($createur)->post(route('achats.store'), $this->payload())->assertSessionHasNoErrors();
        $commande = CommandeAchat::firstOrFail();

        $this->actingAs($createur)
            ->patch(route('achats.valider', $commande))
            ->assertSessionHasErrors(['validation' => self::MOTIF_CREATEUR]);
        $this->assertSame(StatutCommandeAchat::A_VALIDER, $commande->fresh()->statut);
    }

    public function test_le_dernier_modificateur_ne_peut_pas_valider(): void
    {
        $commande = $this->creerCommande(10);
        $validateur = $this->makeValidateur('responsable_achat', null, true);
        $validateur->givePermissionTo('achats.update');

        $this->actingAs($validateur)->put(route('achats.update', $commande), $this->payload())->assertSessionHasNoErrors();

        $this->actingAs($validateur)
            ->patch(route('achats.valider', $commande))
            ->assertSessionHasErrors(['validation' => self::MOTIF_MODIFICATEUR]);
    }

    public function test_le_super_administrateur_suit_le_moteur_normal_de_perimetre_et_de_plafond(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $superAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $superAdmin->assignRole('super_admin');
        $superAdmin->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $commande = $this->creerCommande(10);

        // Sans règle : aucun accès, malgré le Gate::before.
        $this->actingAs($superAdmin)->get(route('achats.show', $commande))->assertForbidden();
        $this->actingAs($superAdmin)->patch(route('achats.valider', $commande))->assertForbidden();
        $this->actingAs($superAdmin)->post(route('achats.store'), $this->payload())->assertSessionHasErrors('site_id');

        // Règle par défaut (toutes agences, sans limite) : crée, voit, valide le bon d'un autre…
        RegleValidationRole::provisionnerAchatsParDefaut($this->org->id);
        $this->actingAs($superAdmin)->post(route('achats.store'), $this->payload())->assertSessionHasNoErrors();
        $sonBon = CommandeAchat::where('created_by', $superAdmin->id)->firstOrFail();
        $this->actingAs($superAdmin)->get(route('achats.show', $commande))->assertOk();
        $this->actingAs($superAdmin)->patch(route('achats.valider', $commande))->assertSessionHasNoErrors();
        $this->assertSame(StatutCommandeAchat::VALIDEE, $commande->fresh()->statut);

        // … et le sien : sa règle par défaut l'autorise à valider ses propres bons (décision du 09/10/2026).
        $this->actingAs($superAdmin)->patch(route('achats.valider', $sonBon))->assertSessionHasNoErrors();
        $this->assertSame(StatutCommandeAchat::VALIDEE, $sonBon->fresh()->statut);
        $this->assertTrue($sonBon->fresh()->validation_regle_snapshot['son_propre_bon']);

        // Réglage retiré dans Paramètres → Achats : la séparation des tâches s'applique à lui aussi.
        RegleValidationRole::where('role_name', 'super_admin')->update(['peut_valider_ses_propres_bons' => false]);
        $this->actingAs($superAdmin)->post(route('achats.store'), $this->payload())->assertSessionHasNoErrors();
        $sonSecondBon = CommandeAchat::where('created_by', $superAdmin->id)->where('statut', StatutCommandeAchat::A_VALIDER)->firstOrFail();
        $this->actingAs($superAdmin)
            ->patch(route('achats.valider', $sonSecondBon))
            ->assertSessionHasErrors(['validation' => self::MOTIF_CREATEUR]);

        // Règle restreinte à une autre agence : plus d'accès aux bons de cette agence.
        $autreSite = Site::factory()->for($this->org)->create();
        RegleValidationRole::where('role_name', 'super_admin')->update(['perimetre' => 'agences_selectionnees', 'sites' => json_encode([$autreSite->id])]);
        $bonDUnAutre = $this->creerCommande(5);
        $this->actingAs($superAdmin)->get(route('achats.show', $bonDUnAutre))->assertForbidden();
        $this->actingAs($superAdmin)->patch(route('achats.valider', $bonDUnAutre))->assertForbidden();
        $this->actingAs($superAdmin)->post(route('achats.store'), $this->payload())->assertSessionHasErrors('site_id');
    }

    public function test_la_fiche_explique_pourquoi_le_bouton_valider_est_absent(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        RegleValidationRole::provisionnerAchatsParDefaut($this->org->id);
        $superAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $superAdmin->assignRole('super_admin');
        $superAdmin->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);
        $commande = $this->creerCommande(10);

        $actions = fn (User $user, CommandeAchat $bon, bool $peutValider, ?string $motif) => $this->actingAs($user)
            ->get(route('achats.show', $bon))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('actions.peut_valider', $peutValider)
                ->where('actions.motif_non_validable', $motif));

        // Rôle super_admin sans `achats.valider` en base (base non resynchronisée) : le Gate::before
        // fait passer can(), le serveur refuse — la fiche le dit au lieu de masquer le bouton en silence.
        Role::findByName('super_admin')->revokePermissionTo('achats.valider');
        $sansPermission = "Votre rôle n'a pas la permission de valider les bons de commande.";
        $actions($superAdmin->fresh(), $commande, false, $sansPermission);
        $this->actingAs($superAdmin->fresh())
            ->patch(route('achats.valider', $commande))
            ->assertSessionHasErrors(['validation' => $sansPermission]);
        $this->assertSame(StatutCommandeAchat::A_VALIDER, $commande->fresh()->statut);

        // Simple lecteur sans la permission : aucun motif (il n'est pas validateur), pas de bouton.
        $lecteur = $this->makeUtilisateur('lecteur_achats', ['achats.read']);
        $this->regle('lecteur_achats', null);
        $actions($lecteur, $commande, false, null);

        // Permission rétablie : bouton sur le bon d'un autre comme sur le sien (règle par défaut)…
        Role::findByName('super_admin')->givePermissionTo('achats.valider');
        $actions($superAdmin->fresh(), $commande, true, null);
        $this->actingAs($superAdmin->fresh())->post(route('achats.store'), $this->payload())->assertSessionHasNoErrors();
        $sonBon = CommandeAchat::where('created_by', $superAdmin->id)->firstOrFail();
        $actions($superAdmin->fresh(), $sonBon, true, null);

        // … et le motif de séparation des tâches s'affiche si le réglage est retiré.
        RegleValidationRole::where('role_name', 'super_admin')->update(['peut_valider_ses_propres_bons' => false]);
        $actions($superAdmin->fresh(), $sonBon, false, self::MOTIF_CREATEUR);
    }

    public function test_la_migration_cree_les_regles_par_defaut_sans_ecraser_une_regle_configuree(): void
    {
        RegleValidationRole::where('role_name', 'admin_entreprise')->update(['plafond' => 1_000, 'perimetre' => 'son_agence']);
        $autreOrg = Organization::factory()->create();

        (require database_path('migrations/2026_10_07_200400_provisionner_regles_achats_par_defaut.php'))->up();

        $admin = RegleValidationRole::where('organization_id', $this->org->id)->where('role_name', 'admin_entreprise')->firstOrFail();
        $this->assertSame(1_000.0, (float) $admin->plafond);
        $this->assertSame('son_agence', $admin->perimetre);
        $this->assertFalse($admin->plafond_illimite);

        foreach ([$this->org->id, $autreOrg->id] as $orgId) {
            $super = RegleValidationRole::where('organization_id', $orgId)->where('role_name', 'super_admin')->firstOrFail();
            $this->assertTrue($super->plafond_illimite);
            $this->assertSame('toutes_agences', $super->perimetre);
            $this->assertTrue($super->peut_valider_ses_propres_bons);
        }
        $adminAutreOrg = RegleValidationRole::where('organization_id', $autreOrg->id)->where('role_name', 'admin_entreprise')->firstOrFail();
        $this->assertTrue($adminAutreOrg->plafond_illimite);
        $this->assertFalse($adminAutreOrg->peut_valider_ses_propres_bons);
    }

    public function test_la_migration_autorise_l_auto_validation_des_regles_super_admin_existantes_seulement(): void
    {
        $super = $this->regle('super_admin', null, true);
        RegleValidationRole::whereKey($super->id)->update(['peut_valider_ses_propres_bons' => false]);
        $admin = RegleValidationRole::where('role_name', 'admin_entreprise')->firstOrFail();

        (require database_path('migrations/2026_10_09_200000_add_peut_valider_ses_propres_bons_to_regles_validation_roles_table.php'))->up();

        $this->assertTrue($super->fresh()->peut_valider_ses_propres_bons);
        $this->assertFalse($admin->fresh()->peut_valider_ses_propres_bons);
    }

    public function test_deploiement_les_deux_migrations_dans_la_meme_passe_sans_colonne_au_depart(): void
    {
        // Base pas encore déployée (production, formation) : 200400 tourne avant que 200000 n'ajoute
        // la colonne. Elle ne doit pas échouer, et le super administrateur doit finir autorisé.
        RegleValidationRole::query()->delete();
        Schema::table('regles_validation_roles', fn (Blueprint $t) => $t->dropColumn('peut_valider_ses_propres_bons'));

        (require database_path('migrations/2026_10_07_200400_provisionner_regles_achats_par_defaut.php'))->up();
        (require database_path('migrations/2026_10_09_200000_add_peut_valider_ses_propres_bons_to_regles_validation_roles_table.php'))->up();

        $regles = RegleValidationRole::where('organization_id', $this->org->id)->get()->keyBy('role_name');
        $this->assertTrue($regles['super_admin']->peut_valider_ses_propres_bons);
        $this->assertFalse($regles['admin_entreprise']->peut_valider_ses_propres_bons);
    }

    public function test_un_role_autorise_a_valider_ses_propres_bons_le_fait_dans_la_limite_de_son_plafond(): void
    {
        $acheteur = $this->makeValidateur('responsable_achat', 15_000);
        $acheteur->givePermissionTo(['achats.create', 'achats.update']);
        RegleValidationRole::where('role_name', 'responsable_achat')->update(['peut_valider_ses_propres_bons' => true]);

        $this->actingAs($acheteur)->post(route('achats.store'), $this->payload())->assertSessionHasNoErrors();
        $petit = CommandeAchat::where('created_by', $acheteur->id)->firstOrFail();

        // Visible dans « À valider par moi », bouton affiché, validation acceptée.
        $this->actingAs($acheteur)->get(route('achats.index', ['a_valider_par_moi' => '1']))
            ->assertInertia(fn ($page) => $page->where('commandes.total', 1)->where('commandes.data.0.id', $petit->id));
        $this->actingAs($acheteur)->get(route('achats.show', $petit))
            ->assertInertia(fn ($page) => $page->where('actions.peut_valider', true)->where('aucun_validateur_disponible', false));
        $this->actingAs($acheteur)->patch(route('achats.valider', $petit))->assertSessionHasNoErrors();
        $this->assertSame(StatutCommandeAchat::VALIDEE, $petit->fresh()->statut);

        // Le plafond reste contrôlé : 20 000 GNF > 15 000 GNF.
        $this->actingAs($acheteur)->post(route('achats.store'), $this->payload([
            'lignes' => [['variante_id' => $this->varianteId(), 'qte' => 20, 'prix_achat' => 1000]],
        ]))->assertSessionHasNoErrors();
        $gros = CommandeAchat::where('created_by', $acheteur->id)->where('statut', StatutCommandeAchat::A_VALIDER)->firstOrFail();
        $this->actingAs($acheteur)->patch(route('achats.valider', $gros))->assertSessionHasErrors('validation');
        $this->assertSame(StatutCommandeAchat::A_VALIDER, $gros->fresh()->statut);
    }

    public function test_le_reglage_d_auto_validation_ne_concerne_que_le_role_qui_le_porte(): void
    {
        // Le créateur (admin_entreprise, sans auto-validation) reste soumis à la séparation des
        // tâches ; un autre rôle autorisé à valider SES bons garde son plafond sur ceux des autres.
        $this->createurAvecDroitDeValiderSansLimite();
        $autoValidateur = $this->makeValidateur('responsable_achat', 5_000);
        RegleValidationRole::where('role_name', 'responsable_achat')->update(['peut_valider_ses_propres_bons' => true]);

        $commande = $this->creerCommande(10);

        $this->actingAs($this->user)
            ->patch(route('achats.valider', $commande))
            ->assertSessionHasErrors(['validation' => self::MOTIF_CREATEUR]);
        $this->actingAs($autoValidateur)
            ->patch(route('achats.valider', $commande))
            ->assertSessionHasErrors('validation');
        $this->assertSame(StatutCommandeAchat::A_VALIDER, $commande->fresh()->statut);
    }

    // ── Isolation et périmètre de lecture ─────────────────────────────────────

    public function test_isolation_organisationnelle(): void
    {
        $autre = Organization::factory()->create();
        $commande = CommandeAchat::create(['organization_id' => $autre->id, 'total_commande' => 5000]);

        $this->actingAs($this->user)->get(route('achats.show', $commande))->assertForbidden();
        $this->actingAs($this->user)->get(route('achats.pdf', $commande))->assertForbidden();
        $this->actingAs($this->makeValidateur())->patch(route('achats.valider', $commande))->assertForbidden();
    }

    public function test_perimetre_de_lecture_agences_createur_et_validateur(): void
    {
        $autreSite = Site::factory()->for($this->org)->create();
        $commande = $this->creerCommande(10);
        $commande->update(['site_id' => $autreSite->id]);

        // Lecteur dont la règle couvre seulement l'agence principale : voit ses bons, pas les autres.
        $lecteur = $this->makeUtilisateur('lecteur_achats', ['achats.read']);
        $this->regle('lecteur_achats', null, false, 'agences_selectionnees', [$this->site->id]);
        $bonVisible = $this->creerCommande(3);
        $this->actingAs($lecteur)->get(route('achats.show', $commande))->assertForbidden();
        $this->actingAs($lecteur)->get(route('achats.show', $bonVisible))->assertOk();
        $this->actingAs($lecteur)->get(route('achats.index'))
            ->assertInertia(fn ($page) => $page->where('commandes.total', 1)->where('commandes.data.0.id', $bonVisible->id));

        $createur = $this->makeUtilisateur('acheteur', ['achats.read', 'achats.create']);
        $commande->update(['created_by' => $createur->id]);
        $this->actingAs($createur)->get(route('achats.show', $commande))->assertOk();

        $validateur = $this->makeUtilisateur('valideur_hors_agence', ['achats.read']);
        $commande->update(['validee_par' => $validateur->id]);
        $this->actingAs($validateur)->get(route('achats.index'))
            ->assertInertia(fn ($page) => $page->where('commandes.total', 1)->where('commandes.data.0.id', $commande->id));
    }

    public function test_filtre_a_valider_par_moi(): void
    {
        $this->creerCommande(10);
        $this->creerCommande(100);
        $validateur = $this->makeValidateur('responsable_achat', 20_000);

        $this->actingAs($validateur)
            ->get(route('achats.index', ['a_valider_par_moi' => '1']))
            ->assertInertia(fn ($page) => $page->where('commandes.total', 1));
    }

    // ── Annulation / suppression ──────────────────────────────────────────────

    public function test_annulation_possible_au_dessus_du_plafond(): void
    {
        $commande = $this->creerCommande(10);
        $annuleur = $this->makeValidateur('agent_achat', 1);
        $annuleur->givePermissionTo('achats.annuler');

        $this->actingAs($annuleur)
            ->patch(route('achats.annuler', $commande), ['motif_annulation' => 'Doublon'])
            ->assertSessionHasNoErrors();
        $this->assertSame(StatutCommandeAchat::ANNULEE, $commande->fresh()->statut);
    }

    public function test_annuler_fails_without_motif(): void
    {
        $commande = $this->creerCommande(10);

        $this->actingAs($this->user)
            ->patch(route('achats.annuler', $commande), [])
            ->assertSessionHasErrors('motif_annulation');
    }

    public function test_destroy_seulement_une_commande_annulee(): void
    {
        $commande = $this->creerCommande(10);
        $this->actingAs($this->user)->delete(route('achats.destroy', $commande))->assertForbidden();

        $commande->update(['statut' => StatutCommandeAchat::ANNULEE]);
        $this->actingAs($this->user)->delete(route('achats.destroy', $commande))->assertRedirect(route('achats.index'));
        $this->assertSoftDeleted('commandes_achats', ['id' => $commande->id]);
    }

    // ── Notifications (après commit) ──────────────────────────────────────────

    public function test_notifications_creation_validation_et_annulation(): void
    {
        Notification::fake();

        $validateur = $this->makeValidateur('responsable_achat', 50_000);
        $petitPlafond = $this->makeValidateur('agent_achat', 1_000);
        $magasinier = $this->makeUtilisateur('magasinier', ['receptions.create', 'receptions.read']);

        $commande = $this->creerCommande(10);

        Notification::assertSentTo($validateur, CommandeAchatNotification::class, fn ($n) => $n->toArray($validateur)['type'] === 'commande_achat_creee');
        Notification::assertNotSentTo($petitPlafond, CommandeAchatNotification::class);
        Notification::assertNotSentTo($this->user, CommandeAchatNotification::class);

        $this->actingAs($validateur)->patch(route('achats.valider', $commande))->assertSessionHasNoErrors();

        Notification::assertSentTo($this->user, CommandeAchatNotification::class, fn ($n) => $n->toArray($this->user)['type'] === 'commande_achat_validee');
        Notification::assertSentTo($magasinier, CommandeAchatNotification::class, fn ($n) => $n->toArray($magasinier)['type'] === 'commande_achat_validee');
    }

    /** Le créateur de référence ($this->user, rôle admin_entreprise) peut lui aussi valider, sans limite. */
    private function createurAvecDroitDeValiderSansLimite(): void
    {
        Permission::firstOrCreate(['name' => 'achats.valider', 'guard_name' => 'web']);
        Role::findByName('admin_entreprise', 'web')->givePermissionTo('achats.valider');
        RegleValidationRole::where('organization_id', $this->org->id)
            ->where('role_name', 'admin_entreprise')
            ->update(['plafond' => null, 'plafond_illimite' => true]);
    }

    public function test_parcours_complet_a_cree_b_est_notifie_retrouve_le_bon_et_le_valide(): void
    {
        Notification::fake();
        // A pourrait valider (permission + sans limite) : seule la séparation des tâches l'en empêche.
        $this->createurAvecDroitDeValiderSansLimite();
        $b = $this->makeValidateur('responsable_achat', 50_000);

        $commande = $this->creerCommande(10);

        Notification::assertSentTo($b, CommandeAchatNotification::class, fn ($n) => $n->toArray($b)['type'] === 'commande_achat_creee');
        $this->actingAs($b)->get(route('achats.index'))
            ->assertInertia(fn ($page) => $page->where('commandes.data', fn ($data) => collect($data)->contains('id', $commande->id)));
        $this->actingAs($b)->get(route('achats.index', ['a_valider_par_moi' => '1']))
            ->assertInertia(fn ($page) => $page->where('commandes.total', 1)->where('commandes.data.0.id', $commande->id));
        $this->actingAs($b)->get(route('achats.show', $commande))
            ->assertInertia(fn ($page) => $page->where('actions.peut_valider', true)->where('aucun_validateur_disponible', false));

        $this->actingAs($this->user)
            ->patch(route('achats.valider', $commande))
            ->assertSessionHasErrors(['validation' => self::MOTIF_CREATEUR]);
        $this->assertSame(StatutCommandeAchat::A_VALIDER, $commande->fresh()->statut);

        $this->actingAs($b)->patch(route('achats.valider', $commande))->assertSessionHasNoErrors();
        $this->assertSame(StatutCommandeAchat::VALIDEE, $commande->fresh()->statut);
        $this->assertSame($b->id, $commande->fresh()->validee_par);
    }

    public function test_la_fiche_signale_quand_aucun_autre_utilisateur_ne_peut_valider(): void
    {
        // Le créateur est le seul validateur possible (permission + règle sans limite).
        $this->createurAvecDroitDeValiderSansLimite();
        $commande = $this->creerCommande(10);

        $this->actingAs($this->user)->get(route('achats.show', $commande))
            ->assertInertia(fn ($page) => $page
                ->where('aucun_validateur_disponible', true)
                ->where('actions.peut_valider', false)
                ->where('validable_par', fn ($roles) => collect($roles)->contains('role', 'admin_entreprise')));

        // Un second utilisateur éligible lève le message.
        $this->makeValidateur('responsable_achat', 50_000);
        $this->actingAs($this->user)->get(route('achats.show', $commande))
            ->assertInertia(fn ($page) => $page->where('aucun_validateur_disponible', false));
    }

    public function test_aucune_notification_quand_la_validation_est_refusee(): void
    {
        $commande = $this->creerCommande(10);
        Notification::fake();

        $this->actingAs($this->makeValidateur('agent_achat', 1))->patch(route('achats.valider', $commande));

        Notification::assertNothingSent();
    }

    public function test_aucune_notification_si_la_transaction_englobante_est_annulee_apres_la_validation(): void
    {
        $commande = $this->creerCommande(10);
        $validateur = $this->makeValidateur();
        Notification::fake();

        try {
            DB::transaction(function () use ($commande, $validateur) {
                app(CommandeAchatService::class)->valider($commande, $validateur);
                throw new \RuntimeException('rollback simulé');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(StatutCommandeAchat::A_VALIDER, $commande->fresh()->statut);
        Notification::assertNothingSent();
    }

    // ── PDF ───────────────────────────────────────────────────────────────────

    public function test_pdf_porte_le_filigrane_non_valide_tant_que_le_bon_n_est_pas_valide(): void
    {
        $commande = $this->creerCommande(10);

        $response = $this->actingAs($this->user)->get(route('achats.pdf', $commande));
        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('Content-Type'));

        $commande->load(['fournisseur', 'lignes', 'createdBy', 'valideePar', 'organization', 'site']);
        $html = view('pdf.bon_commande_achat', ['commande' => $commande, 'organisation' => $commande->organization, 'createdBy' => '—'])->render();
        $this->assertStringContainsString('NON VALIDÉ', $html);

        $this->actingAs($this->makeValidateur())->patch(route('achats.valider', $commande));
        $commande = $commande->fresh(['fournisseur', 'lignes', 'createdBy', 'valideePar', 'organization', 'site']);
        $html = view('pdf.bon_commande_achat', ['commande' => $commande, 'organisation' => $commande->organization, 'createdBy' => '—'])->render();
        $this->assertStringNotContainsString('NON VALIDÉ', $html);
        $this->assertStringContainsString('Bon de commande validé', $html);
    }

    // ── Paramètres → Achats ───────────────────────────────────────────────────

    public function test_parametres_achats_enregistre_les_plafonds_par_role_super_admin_compris(): void
    {
        $this->user->givePermissionTo(Permission::firstOrCreate(['name' => 'parametres.update', 'guard_name' => 'web']));
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $this->actingAs($this->user)->get(route('settings.achats'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('config', fn ($config) => collect($config)->contains('role_name', 'super_admin')));

        $this->actingAs($this->user)->put(route('settings.achats.validation'), ['config' => [
            ['role_name' => 'admin_entreprise', 'actif' => true, 'plafond' => 5_000_000, 'plafond_illimite' => false, 'peut_valider_ses_propres_bons' => true, 'perimetre' => 'toutes_agences', 'sites' => []],
            ['role_name' => 'super_admin', 'actif' => true, 'plafond' => null, 'plafond_illimite' => true, 'peut_valider_ses_propres_bons' => false, 'perimetre' => 'toutes_agences', 'sites' => []],
        ]])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('regles_validation_roles', ['organization_id' => $this->org->id, 'role_name' => 'admin_entreprise', 'plafond' => 5_000_000, 'peut_valider_ses_propres_bons' => true]);
        $this->assertDatabaseHas('regles_validation_roles', ['organization_id' => $this->org->id, 'role_name' => 'super_admin', 'plafond_illimite' => true, 'peut_valider_ses_propres_bons' => false]);
        $this->actingAs($this->user)->get(route('settings.achats'))
            ->assertInertia(fn ($page) => $page->where('config', fn ($config) => collect($config)->firstWhere('role_name', 'admin_entreprise')['peut_valider_ses_propres_bons'] === true));

        // Périmètre seul (sans plafond) accepté ; agences sélectionnées sans agence refusé.
        $this->actingAs($this->user)->put(route('settings.achats.validation'), ['config' => [
            ['role_name' => 'admin_entreprise', 'actif' => true, 'plafond' => null, 'plafond_illimite' => false, 'perimetre' => 'toutes_agences', 'sites' => []],
        ]])->assertSessionHasNoErrors();
        $this->actingAs($this->user)->put(route('settings.achats.validation'), ['config' => [
            ['role_name' => 'admin_entreprise', 'actif' => true, 'plafond' => null, 'plafond_illimite' => false, 'perimetre' => 'agences_selectionnees', 'sites' => []],
        ]])->assertSessionHasErrors('config.0.sites');
    }
}
