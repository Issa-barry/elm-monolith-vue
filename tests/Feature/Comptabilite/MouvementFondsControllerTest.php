<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutMouvementFonds;
use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\MouvementFonds;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class MouvementFondsControllerTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private Site $siege;

    private Site $agence;

    private CompteTresorerie $caisseSiege;

    private CompteTresorerie $caisseAgence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['tresorerie.create', 'tresorerie.read', 'tresorerie.envoyer', 'tresorerie.recevoir', 'tresorerie.annuler', 'tresorerie.rejeter', 'tresorerie.confirmer_retour']);

        $this->siege = Site::create(['organization_id' => $this->org->id, 'nom' => 'Siège', 'type' => 'siege', 'localisation' => 'Conakry']);
        $this->agence = $this->user->sites()->first();

        $compteCaisse = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();
        $this->caisseSiege = CompteTresorerie::create([
            'organization_id' => $this->org->id, 'site_id' => $this->siege->id,
            'compte_comptable_id' => $compteCaisse->id, 'type' => 'caisse', 'libelle' => 'Caisse Siège',
        ]);
        $this->caisseAgence = CompteTresorerie::create([
            'organization_id' => $this->org->id, 'site_id' => $this->agence->id,
            'compte_comptable_id' => $compteCaisse->id, 'type' => 'caisse', 'libelle' => 'Caisse Agence',
        ]);

        $this->user->sites()->attach($this->siege->id, ['role' => 'employe', 'is_default' => false]);
    }

    private function storePayload(): array
    {
        return [
            'site_origine_id' => $this->siege->id,
            'site_destination_id' => $this->agence->id,
            'compte_tresorerie_origine_id' => $this->caisseSiege->id,
            'montant' => 250_000,
        ];
    }

    public function test_index_refuse_non_authentifie(): void
    {
        $this->get(route('comptabilite.tresorerie.mouvements.index'))->assertRedirect(route('login'));
    }

    public function test_index_refuse_sans_permission(): void
    {
        $this->user->syncPermissions([]);

        $this->actingAs($this->user)
            ->get(route('comptabilite.tresorerie.mouvements.index'))
            ->assertStatus(403);
    }

    public function test_store_cree_un_brouillon(): void
    {
        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.mouvements.store'), $this->storePayload())
            ->assertRedirect();

        $this->assertDatabaseHas('mouvements_fonds', [
            'organization_id' => $this->org->id,
            'montant' => 250_000,
            'statut' => StatutMouvementFonds::BROUILLON->value,
        ]);
    }

    public function test_cycle_complet_envoyer_puis_recevoir(): void
    {
        $this->actingAs($this->user)->post(route('comptabilite.tresorerie.mouvements.store'), $this->storePayload());
        $mouvement = MouvementFonds::where('organization_id', $this->org->id)->firstOrFail();
        $this->assertNull($mouvement->compte_tresorerie_destination_id);

        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.mouvements.envoyer', $mouvement))
            ->assertRedirect();
        $this->assertSame(StatutMouvementFonds::ENVOYE, $mouvement->fresh()->statut);

        // Le support de destination est choisi ici, à la réception — pas à la création.
        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.mouvements.recevoir', $mouvement), [
                'compte_tresorerie_destination_id' => $this->caisseAgence->id,
            ])
            ->assertRedirect();
        $this->assertSame(StatutMouvementFonds::RECU, $mouvement->fresh()->statut);
        $this->assertSame($this->caisseAgence->id, $mouvement->fresh()->compte_tresorerie_destination_id);
    }

    public function test_recevoir_requiert_un_support_de_tresorerie(): void
    {
        $this->actingAs($this->user)->post(route('comptabilite.tresorerie.mouvements.store'), $this->storePayload());
        $mouvement = MouvementFonds::where('organization_id', $this->org->id)->firstOrFail();
        $this->actingAs($this->user)->post(route('comptabilite.tresorerie.mouvements.envoyer', $mouvement));

        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.mouvements.recevoir', $mouvement), [])
            ->assertSessionHasErrors('compte_tresorerie_destination_id');
        $this->assertSame(StatutMouvementFonds::ENVOYE, $mouvement->fresh()->statut);
    }

    public function test_double_confirmation_envoyer_echoue_la_deuxieme_fois(): void
    {
        $this->actingAs($this->user)->post(route('comptabilite.tresorerie.mouvements.store'), $this->storePayload());
        $mouvement = MouvementFonds::where('organization_id', $this->org->id)->firstOrFail();

        $this->actingAs($this->user)->post(route('comptabilite.tresorerie.mouvements.envoyer', $mouvement));

        // La policy refuse la deuxième tentative (statut n'est plus BROUILLON) : 403,
        // jamais une deuxième pièce comptable créée pour le même mouvement.
        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.mouvements.envoyer', $mouvement))
            ->assertStatus(403);
    }

    public function test_annuler_requiert_un_motif(): void
    {
        $this->actingAs($this->user)->post(route('comptabilite.tresorerie.mouvements.store'), $this->storePayload());
        $mouvement = MouvementFonds::where('organization_id', $this->org->id)->firstOrFail();

        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.mouvements.annuler', $mouvement), [])
            ->assertSessionHasErrors('motif');
    }

    public function test_isole_les_organisations(): void
    {
        $autreOrg = Organization::factory()->create();
        $autreSite1 = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'S1', 'type' => 'siege', 'localisation' => 'X']);
        $autreSite2 = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'S2', 'type' => 'agence', 'localisation' => 'X']);
        $compteAutre = CompteComptable::where('organization_id', $autreOrg->id)->where('numero', '571000')->firstOrFail();
        $compteTresoAutre1 = CompteTresorerie::create(['organization_id' => $autreOrg->id, 'site_id' => $autreSite1->id, 'compte_comptable_id' => $compteAutre->id, 'type' => 'caisse', 'libelle' => 'C1']);
        $compteTresoAutre2 = CompteTresorerie::create(['organization_id' => $autreOrg->id, 'site_id' => $autreSite2->id, 'compte_comptable_id' => $compteAutre->id, 'type' => 'caisse', 'libelle' => 'C2']);

        $autreUser = User::factory()->create(['organization_id' => $autreOrg->id]);
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        foreach (['tresorerie.create', 'tresorerie.read'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $autreUser->assignRole('manager');
        $autreUser->givePermissionTo(['tresorerie.create', 'tresorerie.read']);
        $autreUser->sites()->attach($autreSite2->id, ['role' => 'employe', 'is_default' => true]);

        $mouvementAutreOrg = MouvementFonds::create([
            'organization_id' => $autreOrg->id,
            'site_origine_id' => $autreSite1->id,
            'site_destination_id' => $autreSite2->id,
            'compte_tresorerie_origine_id' => $compteTresoAutre1->id,
            'compte_tresorerie_destination_id' => $compteTresoAutre2->id,
            'montant' => 100_000,
            'statut' => StatutMouvementFonds::BROUILLON->value,
        ]);

        // Un utilisateur de $this->org ne peut ni voir ni envoyer un mouvement d'une
        // autre organisation, même en devinant son ID.
        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.mouvements.envoyer', $mouvementAutreOrg))
            ->assertStatus(403);
    }

    public function test_non_admin_ne_peut_pas_envoyer_depuis_un_site_qui_n_est_pas_le_sien(): void
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        foreach (['tresorerie.create', 'tresorerie.read', 'tresorerie.envoyer'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $nonAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $nonAdmin->assignRole('manager');
        $nonAdmin->givePermissionTo(['tresorerie.create', 'tresorerie.read', 'tresorerie.envoyer']);
        // Affecté uniquement à l'agence, jamais au siège.
        $nonAdmin->sites()->attach($this->agence->id, ['role' => 'employe', 'is_default' => true]);

        $mouvement = MouvementFonds::create([
            'organization_id' => $this->org->id,
            'site_origine_id' => $this->siege->id,
            'site_destination_id' => $this->agence->id,
            'compte_tresorerie_origine_id' => $this->caisseSiege->id,
            'compte_tresorerie_destination_id' => $this->caisseAgence->id,
            'montant' => 100_000,
            'statut' => StatutMouvementFonds::BROUILLON->value,
        ]);

        $this->actingAs($nonAdmin)
            ->post(route('comptabilite.tresorerie.mouvements.envoyer', $mouvement))
            ->assertStatus(403);
    }

    private function creerMouvement(Site $origine, Site $destination, int $montant): MouvementFonds
    {
        return MouvementFonds::create([
            'organization_id' => $this->org->id,
            'site_origine_id' => $origine->id,
            'site_destination_id' => $destination->id,
            'compte_tresorerie_origine_id' => ($origine->id === $this->siege->id ? $this->caisseSiege : $this->caisseAgence)->id,
            'montant' => $montant,
            'statut' => StatutMouvementFonds::BROUILLON->value,
        ]);
    }

    /**
     * Montants (triés) des mouvements renvoyés par l'index pour ces paramètres de filtre.
     *
     * @return list<int|float>
     */
    private function montantsIndex(array $query = [], ?User $acteur = null): array
    {
        $montants = [];

        $this->actingAs($acteur ?? $this->user)
            ->get(route('comptabilite.tresorerie.mouvements.index', $query))
            ->assertOk()
            // `function` et non `fn` : une fonction fléchée capture $montants par valeur, la référence
            // de la closure interne ne remonterait alors jamais jusqu'ici.
            ->assertInertia(function (Assert $page) use (&$montants) {
                $page->component('Comptabilite/MouvementsFonds/Index')
                    ->where('mouvements.data', function ($data) use (&$montants) {
                        $montants = $data->pluck('montant')->sort()->values()->all();

                        return true;
                    });
            });

        return $montants;
    }

    private function jeuDeMouvementsPourFiltres(): void
    {
        $this->creerMouvement($this->siege, $this->agence, 100_000);
        $this->creerMouvement($this->agence, $this->siege, 300_000);
        $this->creerMouvement($this->siege, $this->agence, 500_000);
    }

    public function test_index_sans_filtre_liste_tous_les_mouvements(): void
    {
        $this->jeuDeMouvementsPourFiltres();

        $this->assertEquals([100_000, 300_000, 500_000], $this->montantsIndex());
    }

    public function test_index_filtre_par_site_origine(): void
    {
        $this->jeuDeMouvementsPourFiltres();

        $this->assertEquals([100_000, 500_000], $this->montantsIndex(['site_origine_id' => $this->siege->id]));
        $this->assertEquals([300_000], $this->montantsIndex(['site_origine_id' => $this->agence->id]));
    }

    public function test_index_filtre_par_site_destination(): void
    {
        $this->jeuDeMouvementsPourFiltres();

        $this->assertEquals([300_000], $this->montantsIndex(['site_destination_id' => $this->siege->id]));
        $this->assertEquals([100_000, 500_000], $this->montantsIndex(['site_destination_id' => $this->agence->id]));
    }

    public function test_index_combine_origine_et_destination(): void
    {
        $this->jeuDeMouvementsPourFiltres();

        $this->assertEquals([100_000, 500_000], $this->montantsIndex([
            'site_origine_id' => $this->siege->id,
            'site_destination_id' => $this->agence->id,
        ]));
        $this->assertSame([], $this->montantsIndex([
            'site_origine_id' => $this->siege->id,
            'site_destination_id' => $this->siege->id,
        ]));
    }

    public function test_index_filtre_par_montant_min_et_max(): void
    {
        $this->jeuDeMouvementsPourFiltres();

        // Bornes incluses : 300 000 correspond à « min 300 000 » comme à « max 300 000 ».
        $this->assertEquals([300_000, 500_000], $this->montantsIndex(['montant_min' => '300000']));
        $this->assertEquals([100_000, 300_000], $this->montantsIndex(['montant_max' => '300000']));
        $this->assertEquals([300_000], $this->montantsIndex(['montant_min' => '200000', 'montant_max' => '400000']));
        $this->assertSame([], $this->montantsIndex(['montant_min' => '600000']));
    }

    public function test_index_combine_montant_et_direction(): void
    {
        $this->jeuDeMouvementsPourFiltres();

        $this->assertEquals([500_000], $this->montantsIndex([
            'site_origine_id' => $this->siege->id,
            'montant_min' => '200000',
        ]));
    }

    public function test_index_ignore_un_montant_non_numerique_sans_planter(): void
    {
        $this->jeuDeMouvementsPourFiltres();

        $this->assertEquals([100_000, 300_000, 500_000], $this->montantsIndex(['montant_min' => 'abc', 'montant_max' => ['1']]));
    }

    public function test_index_renvoie_les_valeurs_de_filtre_et_les_sites_de_l_organisation(): void
    {
        $this->actingAs($this->user)
            ->get(route('comptabilite.tresorerie.mouvements.index', [
                'site_origine_id' => $this->siege->id,
                'site_destination_id' => $this->agence->id,
                'montant_min' => '1000',
                'montant_max' => '9000',
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.site_origine_id', $this->siege->id)
                ->where('filters.site_destination_id', $this->agence->id)
                ->where('filters.montant_min', '1000')
                ->where('filters.montant_max', '9000')
                ->has('sites_mouvements', 2)
                ->where('sites_mouvements', fn ($sites) => $sites->pluck('label')->diff(['Siège', 'Site Principal'])->isEmpty())
            );
    }

    public function test_index_non_admin_filtre_sur_un_site_hors_perimetre_sans_elargir_sa_visibilite(): void
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'tresorerie.read', 'guard_name' => 'web']);
        $nonAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $nonAdmin->assignRole('manager');
        $nonAdmin->givePermissionTo(['tresorerie.read']);
        // Affecté uniquement à l'agence : le siège est hors de son périmètre.
        $nonAdmin->sites()->attach($this->agence->id, ['role' => 'employe', 'is_default' => true]);

        $autreAgence = Site::create(['organization_id' => $this->org->id, 'nom' => 'Autre agence', 'type' => 'agence', 'localisation' => 'Conakry']);

        $this->creerMouvement($this->siege, $this->agence, 100_000);
        // Ni l'origine ni la destination ne sont dans le périmètre du non-admin.
        $this->creerMouvement($this->siege, $autreAgence, 700_000);

        // Sans filtre, le non-admin ne voit que ce qui touche son agence.
        $this->assertEquals([100_000], $this->montantsIndex([], $nonAdmin));
        // Filtrer sur le siège (hors périmètre) ne révèle pas le mouvement siège → autre agence.
        $this->assertEquals([100_000], $this->montantsIndex(['site_origine_id' => $this->siege->id], $nonAdmin));
        $this->assertEquals([100_000], $this->montantsIndex(['montant_min' => '1'], $nonAdmin));
        $this->assertSame([], $this->montantsIndex(['site_destination_id' => $autreAgence->id], $nonAdmin));

        // Le siège reste proposé dans les listes Origine/Destination, même hors périmètre.
        $this->actingAs($nonAdmin)
            ->get(route('comptabilite.tresorerie.mouvements.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sites_mouvements', fn ($sites) => $sites->pluck('label')->contains('Siège'))
            );
    }

    public function test_index_filtre_sur_un_site_d_une_autre_organisation_ne_renvoie_rien(): void
    {
        $this->jeuDeMouvementsPourFiltres();

        $autreOrg = Organization::factory()->create();
        $siteEtranger = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Site étranger', 'type' => 'siege', 'localisation' => 'X']);

        $this->assertSame([], $this->montantsIndex(['site_origine_id' => $siteEtranger->id]));
        $this->assertSame([], $this->montantsIndex(['site_destination_id' => $siteEtranger->id]));

        $this->actingAs($this->user)
            ->get(route('comptabilite.tresorerie.mouvements.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sites_mouvements', fn ($sites) => ! $sites->pluck('label')->contains('Site étranger'))
            );
    }
}
