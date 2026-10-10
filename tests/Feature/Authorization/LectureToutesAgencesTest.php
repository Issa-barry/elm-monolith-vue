<?php

namespace Tests\Feature\Authorization;

use App\Enums\StatutCommandeVente;
use App\Enums\StatutTransfert;
use App\Features\ModuleFeature;
use App\Models\CommandeAchat;
use App\Models\CommandeVente;
use App\Models\CompteTresorerie;
use App\Models\FactureVente;
use App\Models\MouvementFonds;
use App\Models\Organization;
use App\Models\PaiementFiche;
use App\Models\Proprietaire;
use App\Models\RegleValidationRole;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\TypeVehicule;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\Achats\PerimetreCommandesAchat;
use App\Services\Rapports\RapportPerimetreResolver;
use App\Services\SiteScopeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Vision 360° en lecture (ADR 0025) : la permission `sites.lecture_toutes_agences` d'un rôle ouvre
 * la CONSULTATION des données de toutes les agences, là où le rôle a déjà « Lire ». Elle n'ouvre
 * aucun écran et n'élargit jamais une écriture. Rôle de référence : Assistante de direction
 * (trinôme ADD), rattachée à l'agence A ; un collègue de même profil sans la permission sert de
 * témoin.
 */
class LectureToutesAgencesTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private const LECTURE = [
        'ventes.read', 'ventes.exporter', 'factures.read', 'achats.read', 'factures-fournisseurs.read',
        'produits.read', 'vehicules.read', 'tresorerie.read', 'comptabilite.read', 'logistique.read',
        'receptions.read', 'rapports.read',
    ];

    private Site $agenceA;

    private Site $agenceB;

    private Role $roleAdd;

    private User $add;

    private User $collegue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser([]);

        $this->agenceA = $this->user->sites()->firstOrFail();
        $this->agenceB = $this->creerSite('Agence Kindia');

        $this->roleAdd = $this->creerRole('assistante_de_direction', 'ASSISTANTE DE DIRECTION', 'ADD', [
            ...self::LECTURE, User::PERMISSION_LECTURE_TOUTES_AGENCES,
        ]);
        $this->add = $this->creerUtilisateur($this->roleAdd, $this->agenceA);

        $this->collegue = $this->creerUtilisateur(
            $this->creerRole('assistant_agence', 'Assistant d’agence', 'ADA', self::LECTURE),
            $this->agenceA,
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function creerSite(string $nom, ?Organization $org = null): Site
    {
        return Site::create([
            'organization_id' => ($org ?? $this->org)->id,
            'nom' => $nom,
            'type' => 'depot',
            'localisation' => $nom,
        ]);
    }

    /** Rôle personnalisé de l'organisation, comme ceux créés depuis /backoffice/roles. */
    private function creerRole(string $name, string $label, ?string $code, array $permissions): Role
    {
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $role = Role::create([
            'name' => $name,
            'guard_name' => 'web',
            'label' => $label,
            'code' => $code,
            'organization_id' => $this->org->id,
        ]);
        $role->givePermissionTo($permissions);

        return $role;
    }

    private function creerUtilisateur(Role $role, Site $site): User
    {
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole($role);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function accorder(Role $role, array $permissions): void
    {
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $role->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function commande(Site $site, array $overrides = []): CommandeVente
    {
        static $seq = 0;
        $seq++;

        return CommandeVente::create(array_merge([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'statut' => StatutCommandeVente::LIVREE,
            'total_commande' => 5000,
            'reference' => 'V360-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT).'-'.uniqid(),
            'numero' => $seq,
        ], $overrides));
    }

    private function facture(Site $site, float $montant): FactureVente
    {
        return FactureVente::create([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'commande_vente_id' => $this->commande($site, ['total_commande' => $montant])->id,
            'montant_brut' => $montant,
            'montant_net' => $montant,
            'statut_facture' => 'impayee',
        ]);
    }

    private function bonAchat(Site $site): CommandeAchat
    {
        return CommandeAchat::create([
            'organization_id' => $site->organization_id,
            'site_id' => $site->id,
            'total_commande' => 5000,
        ]);
    }

    /** @return list<string> */
    private function referencesListees(User $user, array $params = []): array
    {
        $props = $this->actingAs($user)
            ->get(route('ventes.index', array_merge(['periode' => 'all'], $params)))
            ->assertOk()
            ->viewData('page')['props'];

        return array_column($props['commandes'], 'reference');
    }

    /** @return list<string> */
    private function referencesExportees(TestResponse $response): array
    {
        $chemin = tempnam(sys_get_temp_dir(), 'export_360').'.xlsx';
        file_put_contents($chemin, $response->streamedContent());
        $tableau = IOFactory::load($chemin)->getActiveSheet()->toArray(null, true, true, false);
        @unlink($chemin);

        return array_column(array_slice($tableau, 1), array_search('Référence', $tableau[0], true));
    }

    // ── 1, 2, 7 : lecture de son agence, des autres agences, et témoin limité ────

    public function test_l_assistant_consulte_les_ventes_de_son_agence_et_des_autres_agences(): void
    {
        $ici = $this->commande($this->agenceA);
        $ailleurs = $this->commande($this->agenceB);

        $this->assertEqualsCanonicalizing([$ici->reference, $ailleurs->reference], $this->referencesListees($this->add));
        // Le filtre Agence lui est ouvert, comme à un administrateur.
        $this->assertSame([$ailleurs->reference], $this->referencesListees($this->add, ['site_ids' => [$this->agenceB->id]]));
    }

    public function test_un_autre_role_reste_limite_a_son_agence(): void
    {
        $ici = $this->commande($this->agenceA);
        $this->commande($this->agenceB);

        $this->assertSame([$ici->reference], $this->referencesListees($this->collegue));
        // Demander une autre agence ne contourne rien : le paramètre est ignoré.
        $this->assertSame([$ici->reference], $this->referencesListees($this->collegue, ['site_ids' => [$this->agenceB->id]]));
    }

    public function test_les_donnees_d_une_autre_organisation_restent_invisibles(): void
    {
        $etrangere = $this->commande($this->creerSite('Ailleurs', Organization::factory()->create()));
        $ici = $this->commande($this->agenceA);

        $this->assertSame([$ici->reference], $this->referencesListees($this->add));
        $this->actingAs($this->add)->get(route('ventes.show', $etrangere))->assertForbidden();
    }

    // ── 3, 10 : la vision 360° n'ouvre aucun écran sans « Lire » ───────────────

    public function test_sans_la_permission_lire_la_ressource_reste_inaccessible(): void
    {
        $sansVentes = $this->creerUtilisateur(
            $this->creerRole('assistant_sans_ventes', 'Assistant sans ventes', 'ASV', [
                'produits.read', User::PERMISSION_LECTURE_TOUTES_AGENCES,
            ]),
            $this->agenceA,
        );
        $commande = $this->commande($this->agenceB);
        $bon = $this->bonAchat($this->agenceB);
        Feature::for($this->org)->activate(ModuleFeature::ACHATS);

        $this->actingAs($sansVentes)->get(route('ventes.index'))->assertForbidden();
        $this->actingAs($sansVentes)->get(route('ventes.show', $commande))->assertForbidden();
        $this->actingAs($sansVentes)->get(route('ventes.export'))->assertForbidden();
        $this->actingAs($sansVentes)->get(route('achats.index'))->assertForbidden();
        $this->actingAs($sansVentes)->get(route('achats.show', $bon))->assertForbidden();
        $this->actingAs($sansVentes)->get(route('comptabilite.tresorerie.situation.show', $this->agenceB))->assertForbidden();
    }

    // ── 9 : exports, recherche, statistiques ──────────────────────────────────

    public function test_l_export_des_ventes_couvre_toutes_les_agences(): void
    {
        $ici = $this->commande($this->agenceA);
        $ailleurs = $this->commande($this->agenceB);

        $exportAdd = $this->referencesExportees($this->actingAs($this->add)->get(route('ventes.export'))->assertOk());
        $exportCollegue = $this->referencesExportees($this->actingAs($this->collegue)->get(route('ventes.export'))->assertOk());

        $this->assertEqualsCanonicalizing([$ici->reference, $ailleurs->reference], $exportAdd);
        $this->assertSame([$ici->reference], $exportCollegue);
    }

    public function test_la_recherche_globale_couvre_toutes_les_agences(): void
    {
        $this->commande($this->agenceA, ['reference' => 'RECH360-ICI']);
        $this->commande($this->agenceB, ['reference' => 'RECH360-AILLEURS']);

        $titres = function (User $user): array {
            Sanctum::actingAs($user, ['*']);

            return array_column(
                $this->getJson(route('api.search.global', ['q' => 'RECH360']))->assertOk()->json('results.commandes.items') ?? [],
                'title',
            );
        };

        $this->assertEqualsCanonicalizing(['RECH360-ICI', 'RECH360-AILLEURS'], $titres($this->add));
        $this->assertSame(['RECH360-ICI'], $titres($this->collegue));
    }

    public function test_le_tableau_de_bord_agrege_toutes_les_agences(): void
    {
        $this->facture($this->agenceA, 100_000);
        $this->facture($this->agenceB, 900_000);

        $stats = fn (User $user) => $this->actingAs($user)->get(route('dashboard'))->assertOk()->viewData('page')['props']['stats_factures'];

        $this->assertSame(2, $stats($this->add)['total_count']);
        $this->assertEquals(1_000_000, $stats($this->add)['total_montant']);
        $this->assertSame(1, $stats($this->collegue)['total_count']);
        $this->assertEquals(100_000, $stats($this->collegue)['total_montant']);
    }

    public function test_le_rapport_d_activite_propose_toutes_les_agences(): void
    {
        $resolver = app(RapportPerimetreResolver::class);

        $this->assertEqualsCanonicalizing(
            [$this->agenceA->id, $this->agenceB->id],
            $resolver->sitesProposes($this->add)->pluck('id')->all(),
        );
        $this->assertSame([$this->agenceA->id], $resolver->sitesProposes($this->collegue)->pluck('id')->all());
    }

    // ── Autres modules ────────────────────────────────────────────────────────

    public function test_tresorerie_la_situation_d_une_autre_agence_est_consultable(): void
    {
        $this->actingAs($this->add)->get(route('comptabilite.tresorerie.situation.show', $this->agenceB))->assertOk();
        $this->actingAs($this->collegue)->get(route('comptabilite.tresorerie.situation.show', $this->agenceB))->assertForbidden();

        $agences = fn (User $user) => array_column(
            $this->actingAs($user)->get(route('comptabilite.tresorerie.situation.index'))->assertOk()->viewData('page')['props']['sites'],
            'value',
        );
        $this->assertEqualsCanonicalizing([$this->agenceA->id, $this->agenceB->id], $agences($this->add));
    }

    public function test_tresorerie_le_detail_inter_agences_d_autres_agences_est_consultable_sans_pouvoir_regler(): void
    {
        $agenceC = $this->creerSite('Agence Labé');
        $this->accorder($this->roleAdd, ['tresorerie.create', 'tresorerie.envoyer']);
        $couple = [$this->agenceB, $agenceC];

        $this->actingAs($this->add)->get(route('comptabilite.tresorerie.inter-agences.show', $couple))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('peut_regler', false));
        $this->actingAs($this->collegue)->get(route('comptabilite.tresorerie.inter-agences.show', $couple))->assertForbidden();

        // Régler reste réservé aux agences de rattachement, même avec les permissions d'écriture.
        $this->actingAs($this->add)
            ->post(route('comptabilite.tresorerie.inter-agences.reglements.store', $couple), [])
            ->assertForbidden();
    }

    public function test_produits_le_stock_d_une_autre_agence_est_consultable(): void
    {
        $this->actingAs($this->add)->get(route('produits.index', ['site_ids' => [$this->agenceB->id]]))->assertOk();
        $this->actingAs($this->collegue)->get(route('produits.index', ['site_ids' => [$this->agenceB->id]]))->assertForbidden();
    }

    // ── Achats : consultation ouverte, « Peut acheter pour » intact pour l'écriture ──

    public function test_achats_les_bons_de_toutes_les_agences_sont_consultables_sans_regle_d_achat(): void
    {
        Feature::for($this->org)->activate(ModuleFeature::ACHATS);
        $bon = $this->bonAchat($this->agenceB);

        $this->actingAs($this->add)->get(route('achats.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('commandes.total', 1)
                ->where('commandes.data.0.id', $bon->id)
                ->where('commandes.data.0.annulable', false)
                ->where('commandes.data.0.peut_valider', false)
                ->where('peut_creer', false));
        $this->actingAs($this->add)->get(route('achats.show', $bon))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('actions.peut_modifier', false)
                ->where('actions.peut_valider', false)
                ->where('actions.peut_annuler', false)
                ->where('actions.peut_supprimer', false)
                ->where('actions.lien_reception', null)
                ->where('actions.lien_facture', null));

        // Témoin : même permission « Lire », sans vision 360° ni règle d'achat.
        $this->actingAs($this->collegue)->get(route('achats.index'))
            ->assertInertia(fn (Assert $page) => $page->where('commandes.total', 0));
        $this->actingAs($this->collegue)->get(route('achats.show', $bon))->assertForbidden();
    }

    public function test_achats_aucune_ecriture_hors_du_perimetre_peut_acheter_pour(): void
    {
        Feature::for($this->org)->activate(ModuleFeature::ACHATS);
        $this->accorder($this->roleAdd, ['achats.create', 'achats.update', 'achats.delete', 'achats.valider', 'achats.annuler']);
        // « Peut acheter pour » : son agence seulement.
        RegleValidationRole::create([
            'organization_id' => $this->org->id,
            'domaine' => RegleValidationRole::DOMAINE_ACHATS,
            'role_name' => $this->roleAdd->name,
            'plafond' => null,
            'plafond_illimite' => true,
            'perimetre' => 'son_agence',
            'sites' => null,
        ]);
        $bon = $this->bonAchat($this->agenceB);

        $this->actingAs($this->add)->get(route('achats.show', $bon))->assertOk();
        $this->actingAs($this->add)->put(route('achats.update', $bon), [])->assertForbidden();
        $this->actingAs($this->add)->patch(route('achats.valider', $bon))->assertForbidden();
        $this->actingAs($this->add)->patch(route('achats.annuler', $bon), ['motif' => 'Test'])->assertForbidden();
        $this->actingAs($this->add)->delete(route('achats.destroy', $bon))->assertForbidden();

        // Création : le périmètre contrôlé par AchatReferentielValidator reste « son agence ».
        $perimetre = app(PerimetreCommandesAchat::class);
        $this->assertTrue($perimetre->couvreSite($this->add, $this->agenceA->id));
        $this->assertFalse($perimetre->couvreSite($this->add, $this->agenceB->id));
        $this->assertSame([$this->agenceA->id], $perimetre->sites($this->add)->pluck('id')->all());
        $this->assertFalse($perimetre->estVisible($bon, $this->add));
        $this->assertTrue($perimetre->estConsultable($bon, $this->add));

        $this->assertDatabaseHas('commandes_achats', ['id' => $bon->id, 'deleted_at' => null]);
    }

    // ── 4, 5, 6 : création, modification, suppression inchangées ───────────────

    public function test_creer_ou_deplacer_un_vehicule_dans_une_autre_agence_reste_interdit(): void
    {
        $this->accorder($this->roleAdd, ['vehicules.create', 'vehicules.update']);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $payload = fn (Site $site, string $immatriculation) => [
            'nom_vehicule' => 'Camion 360',
            'immatriculation' => $immatriculation,
            'type_vehicule_id' => TypeVehicule::where('organization_id', $this->org->id)->value('id'),
            'proprietaire_id' => $proprietaire->id,
            'categorie' => 'partenaire',
            'site_id' => $site->id,
            'livraison_vente' => true,
            'livraison_logistique' => false,
        ];

        $this->actingAs($this->add)->post(route('vehicules.store'), $payload($this->agenceB, 'RC-360-B'))->assertForbidden();
        $this->assertDatabaseMissing('vehicules', ['immatriculation' => 'RC-360-B']);

        // Dans son agence, la règle actuelle continue de l'autoriser.
        $this->actingAs($this->add)->post(route('vehicules.store'), $payload($this->agenceA, 'RC-360-A'))->assertRedirect();
        $vehicule = Vehicule::where('immatriculation', 'RC-360-A')->firstOrFail();

        $this->actingAs($this->add)->put(route('vehicules.update', $vehicule), $payload($this->agenceB, 'RC-360-A'))->assertForbidden();
        $this->assertSame($this->agenceA->id, $vehicule->fresh()->site_id);
    }

    public function test_les_actions_d_ecriture_des_policies_restent_liees_au_rattachement(): void
    {
        $this->accorder($this->roleAdd, [
            'tresorerie.create', 'tresorerie.envoyer', 'tresorerie.valider_supports',
            'comptabilite.payer', 'logistique.update',
        ]);
        $add = $this->add->fresh();

        // Trésorerie : régler la dette d'une agence = y être rattaché.
        $this->assertTrue($add->can('regler', [MouvementFonds::class, $this->agenceA]));
        $this->assertFalse($add->can('regler', [MouvementFonds::class, $this->agenceB]));

        $support = (new CompteTresorerie)->forceFill(['organization_id' => $this->org->id, 'site_id' => $this->agenceB->id]);
        $this->assertFalse($add->can('valider', $support));

        // Fiche de paiement d'une autre agence : consultable, jamais payable.
        $fiche = (new PaiementFiche)->forceFill(['organization_id' => $this->org->id, 'site_id' => $this->agenceB->id]);
        $this->assertTrue($add->can('view', $fiche));
        $this->assertFalse($add->can('payer', $fiche));
        $this->assertFalse($this->collegue->can('view', $fiche));

        // Transfert à destination d'une autre agence : réception réservée à cette agence.
        $transfert = (new TransfertLogistique)->forceFill([
            'organization_id' => $this->org->id,
            'site_source_id' => $this->agenceB->id,
            'site_destination_id' => $this->agenceB->id,
            'statut' => StatutTransfert::TRANSIT,
        ]);
        $this->assertTrue($add->can('view', $transfert));
        $this->assertFalse($add->can('validerReception', $transfert));
    }

    public function test_le_perimetre_d_ecriture_ignore_la_vision_360(): void
    {
        $scope = app(SiteScopeService::class);

        $this->assertTrue($scope->accessibleSiteIds($this->add)->isEmpty(), 'Consultation : aucune restriction.');
        $this->assertSame([$this->agenceA->id], $scope->assignedSiteIds($this->add)->all());
        $this->assertSame([$this->agenceA->id], $scope->accessibleSiteIds($this->collegue)->all());
        $this->assertFalse($this->add->isAdmin());
    }

    // ── 8 : la permission porte la règle, pas le libellé ni le trinôme ──────────

    public function test_renommer_le_role_ou_changer_son_trinome_ne_retire_pas_la_vision_360(): void
    {
        $this->commande($this->agenceA);
        $this->commande($this->agenceB);

        $this->roleAdd->update(['label' => 'Assistant·e de la direction générale', 'code' => 'ADG']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertCount(2, $this->referencesListees($this->add->fresh()));
    }

    public function test_retirer_la_permission_ramene_l_assistant_a_son_agence(): void
    {
        $ici = $this->commande($this->agenceA);
        $this->commande($this->agenceB);

        $this->roleAdd->revokePermissionTo(User::PERMISSION_LECTURE_TOUTES_AGENCES);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Le trinôme ADD seul ne donne rien : seule la permission compte.
        $this->assertSame('ADD', $this->roleAdd->fresh()->code);
        $this->assertSame([$ici->reference], $this->referencesListees($this->add->fresh()));
    }

    public function test_la_migration_accorde_la_permission_au_role_de_trinome_add_uniquement(): void
    {
        $this->roleAdd->revokePermissionTo(User::PERMISSION_LECTURE_TOUTES_AGENCES);
        $this->roleAdd->update(['label' => 'Libellé modifié']);
        Permission::where('name', User::PERMISSION_LECTURE_TOUTES_AGENCES)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $migration = require database_path('migrations/2026_10_10_300000_backfill_sites_lecture_toutes_agences_permission.php');
        $migration->up();
        $migration->up();

        $avecPermission = Role::whereHas('permissions', fn ($q) => $q->where('name', User::PERMISSION_LECTURE_TOUTES_AGENCES))->pluck('name')->all();
        $this->assertSame([$this->roleAdd->name], $avecPermission);
    }

    // ── Interface : filtre Agence ouvert, sans rien dire des droits d'écriture ──

    public function test_les_props_partagees_ouvrent_le_filtre_agence_a_l_assistant_seulement(): void
    {
        $auth = fn (User $user) => $this->actingAs($user)->get(route('ventes.index'))->assertOk()->viewData('page')['props']['auth'];

        $this->assertTrue($auth($this->add)['voit_toutes_agences']);
        $this->assertSame([], $auth($this->add)['user_sites']);
        $this->assertTrue($auth($this->add)['permissions'][User::PERMISSION_LECTURE_TOUTES_AGENCES]);

        $this->assertFalse($auth($this->collegue)['voit_toutes_agences']);
        $this->assertSame([$this->agenceA->nom], array_column($auth($this->collegue)['user_sites'], 'nom'));
    }
}
