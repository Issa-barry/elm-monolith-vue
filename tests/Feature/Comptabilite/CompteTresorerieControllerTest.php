<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutSoldeOuverture;
use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\Organization;
use App\Models\Site;
use App\Models\SoldeOuvertureTresorerie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Verrouille le formulaire de configuration des supports de trésorerie —
 * absence totale de test avant le 2026-08-22, ce qui a laissé passer un bug
 * signalé par l'utilisateur : un libellé oublié était rejeté par le serveur
 * (correctement) mais l'interface n'affichait aucune erreur ("refuse de
 * créer" sans explication). Décision produit du 2026-08-22 (revue Codex) :
 * le libellé devient FACULTATIF, généré automatiquement ("{Type} de {Site}")
 * par CompteTresorerie::boot() quand il est vide — cf. CompteTresorerieLibelleTest
 * pour la génération pure, ces tests verrouillent le comportement HTTP complet.
 */
class CompteTresorerieControllerTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['tresorerie.gerer_soldes_ouverture']);
    }

    public function test_index_retourne_200(): void
    {
        $this->actingAs($this->user)
            ->get(route('comptabilite.tresorerie.supports.index'))
            ->assertStatus(200);
    }

    public function test_store_cree_un_support_avec_donnees_valides(): void
    {
        $site = $this->user->sites()->first();
        $compte = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();

        $this->actingAs($this->user)
            ->from(route('comptabilite.tresorerie.supports.index'))
            ->post(route('comptabilite.tresorerie.supports.store'), [
                'site_id' => $site->id,
                'compte_comptable_id' => $compte->id,
                'type' => 'caisse',
                'libelle' => 'Caisse Matoto',
            ])
            ->assertRedirect(route('comptabilite.tresorerie.supports.index'));

        $this->assertDatabaseHas('compta_supports_tresorerie', [
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'libelle' => 'Caisse Matoto',
        ]);
    }

    /**
     * Reproduit exactement le cas signalé : libellé oublié. Ne doit plus
     * jamais être rejeté — un libellé est généré automatiquement.
     */
    public function test_store_genere_le_libelle_automatiquement_si_vide(): void
    {
        $site = $this->user->sites()->first();
        $compte = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();

        $this->actingAs($this->user)
            ->from(route('comptabilite.tresorerie.supports.index'))
            ->post(route('comptabilite.tresorerie.supports.store'), [
                'site_id' => $site->id,
                'compte_comptable_id' => $compte->id,
                'type' => 'caisse',
                'libelle' => '',
            ])
            ->assertRedirect(route('comptabilite.tresorerie.supports.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('compta_supports_tresorerie', [
            'site_id' => $site->id,
            'libelle' => "Caisse de {$site->nom}",
        ]);
    }

    public function test_store_genere_le_libelle_pour_banque_et_mobile_money(): void
    {
        $site = $this->user->sites()->first();
        $compteBanque = CompteComptable::where('organization_id', $this->org->id)->where('numero', '521000')->firstOrFail();
        $compteMM = CompteComptable::where('organization_id', $this->org->id)->where('numero', '561000')->firstOrFail();

        $this->actingAs($this->user)->post(route('comptabilite.tresorerie.supports.store'), [
            'site_id' => $site->id,
            'compte_comptable_id' => $compteBanque->id,
            'type' => 'banque',
            'libelle' => '',
        ]);
        $this->actingAs($this->user)->post(route('comptabilite.tresorerie.supports.store'), [
            'site_id' => $site->id,
            'compte_comptable_id' => $compteMM->id,
            'type' => 'mobile_money',
            'libelle' => '',
        ]);

        $this->assertDatabaseHas('compta_supports_tresorerie', ['site_id' => $site->id, 'libelle' => "Banque de {$site->nom}"]);
        $this->assertDatabaseHas('compta_supports_tresorerie', ['site_id' => $site->id, 'libelle' => "Mobile Money de {$site->nom}"]);
    }

    public function test_store_conserve_un_libelle_renseigne(): void
    {
        $site = $this->user->sites()->first();
        $compte = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();

        $this->actingAs($this->user)->post(route('comptabilite.tresorerie.supports.store'), [
            'site_id' => $site->id,
            'compte_comptable_id' => $compte->id,
            'type' => 'caisse',
            'libelle' => '  Ma caisse personnalisée  ',
        ]);

        $this->assertDatabaseHas('compta_supports_tresorerie', [
            'site_id' => $site->id,
            'libelle' => 'Ma caisse personnalisée',
        ]);
    }

    public function test_store_deuxieme_support_identique_recoit_un_suffixe(): void
    {
        $site = $this->user->sites()->first();
        $compte = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();

        // Deux comptes comptables distincts (571000 générique n'est pas unique en base, mais le
        // scénario réel est : deux supports "caisse" pour le même site, jamais initialisés avec
        // un libellé personnalisé) — on force la collision en créant directement le premier.
        CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'compte_comptable_id' => $compte->id,
            'type' => 'caisse',
            'libelle' => "Caisse de {$site->nom}",
        ]);

        $this->actingAs($this->user)->post(route('comptabilite.tresorerie.supports.store'), [
            'site_id' => $site->id,
            'compte_comptable_id' => $compte->id,
            'type' => 'caisse',
            'libelle' => '',
        ]);

        $this->assertDatabaseHas('compta_supports_tresorerie', [
            'site_id' => $site->id,
            'libelle' => "Caisse de {$site->nom} (2)",
        ]);
    }

    /**
     * Reproduit le cas signalé le 2026-08-22 : le support "Sonfonia" avait été
     * créé avec le type Caisse mais le compte 561300 (Mobile Money Djomy), car
     * le dropdown ne filtrait rien. Doit désormais être refusé.
     */
    public function test_store_refuse_un_compte_incoherent_avec_le_type(): void
    {
        $site = $this->user->sites()->first();
        $compteMobileMoney = CompteComptable::where('organization_id', $this->org->id)->where('numero', '561300')->firstOrFail();

        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.supports.store'), [
                'site_id' => $site->id,
                'compte_comptable_id' => $compteMobileMoney->id,
                'type' => 'caisse',
                'libelle' => '',
            ])
            ->assertSessionHasErrors('compte_comptable_id');

        $this->assertDatabaseCount('compta_supports_tresorerie', 0);
    }

    public function test_store_accepte_un_compte_mobile_money_avec_operateur_pour_le_type_mobile_money(): void
    {
        $site = $this->user->sites()->first();
        $compteDjomy = CompteComptable::where('organization_id', $this->org->id)->where('numero', '561300')->firstOrFail();

        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.supports.store'), [
                'site_id' => $site->id,
                'compte_comptable_id' => $compteDjomy->id,
                'type' => 'mobile_money',
                'libelle' => '',
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('compta_supports_tresorerie', [
            'site_id' => $site->id,
            'compte_comptable_id' => $compteDjomy->id,
        ]);
    }

    public function test_index_expose_le_type_support_deduit_pour_chaque_compte(): void
    {
        $this->actingAs($this->user)
            ->get(route('comptabilite.tresorerie.supports.index'))
            ->assertInertia(function (Assert $page) {
                $comptes = collect($page->toArray()['props']['comptes_comptables'])->keyBy('numero');

                $this->assertSame('caisse', $comptes['571000']['type_support']);
                $this->assertSame('banque', $comptes['521000']['type_support']);
                $this->assertSame('mobile_money', $comptes['561300']['type_support']);
            });
    }

    public function test_store_refuse_un_site_dune_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $autreSite = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'X', 'type' => 'depot', 'localisation' => 'Y']);
        $compte = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();

        $this->actingAs($this->user)
            ->from(route('comptabilite.tresorerie.supports.index'))
            ->post(route('comptabilite.tresorerie.supports.store'), [
                'site_id' => $autreSite->id,
                'compte_comptable_id' => $compte->id,
                'type' => 'caisse',
                'libelle' => 'Intrusion',
            ])
            ->assertSessionHasErrors('site_id');

        $this->assertDatabaseCount('compta_supports_tresorerie', 0);
    }

    public function test_store_refuse_sans_permission(): void
    {
        $this->user->syncPermissions([]);
        $site = $this->user->sites()->first();
        $compte = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();

        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.supports.store'), [
                'site_id' => $site->id,
                'compte_comptable_id' => $compte->id,
                'type' => 'caisse',
                'libelle' => 'Test',
            ])
            ->assertStatus(403);
    }

    public function test_update_modifie_le_libelle(): void
    {
        $site = $this->user->sites()->first();
        $compte = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();
        $support = CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'compte_comptable_id' => $compte->id,
            'type' => 'caisse',
            'libelle' => 'Ancien nom',
        ]);

        $this->actingAs($this->user)
            ->put(route('comptabilite.tresorerie.supports.update', $support), [
                'libelle' => 'Nouveau nom',
                'type' => 'caisse',
                'compte_comptable_id' => $compte->id,
                'actif' => true,
            ])
            ->assertRedirect();

        $this->assertSame('Nouveau nom', $support->fresh()->libelle);
    }

    /**
     * Corrige rétroactivement le cas signalé le 2026-08-22 (support "Sonfonia"
     * créé Caisse + compte Mobile Money) : sans solde d'ouverture, le type et le
     * compte comptable restent modifiables tant que la même règle de cohérence
     * s'applique.
     */
    public function test_update_corrige_un_type_et_compte_incoherents_sans_solde(): void
    {
        $site = $this->user->sites()->first();
        $compteMobileMoney = CompteComptable::where('organization_id', $this->org->id)->where('numero', '561300')->firstOrFail();
        $compteCaisse = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();

        $support = CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'compte_comptable_id' => $compteMobileMoney->id,
            'type' => 'caisse',
            'libelle' => 'Caisse de Sonfonia',
        ]);

        $this->actingAs($this->user)
            ->put(route('comptabilite.tresorerie.supports.update', $support), [
                'libelle' => 'Caisse de Sonfonia',
                'type' => 'caisse',
                'compte_comptable_id' => $compteCaisse->id,
                'actif' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($compteCaisse->id, $support->fresh()->compte_comptable_id);
    }

    public function test_update_refuse_une_combinaison_type_compte_incoherente(): void
    {
        $site = $this->user->sites()->first();
        $compte = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();
        $compteMobileMoney = CompteComptable::where('organization_id', $this->org->id)->where('numero', '561300')->firstOrFail();

        $support = CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'compte_comptable_id' => $compte->id,
            'type' => 'caisse',
            'libelle' => 'Caisse',
        ]);

        $this->actingAs($this->user)
            ->put(route('comptabilite.tresorerie.supports.update', $support), [
                'libelle' => 'Caisse',
                'type' => 'caisse',
                'compte_comptable_id' => $compteMobileMoney->id,
                'actif' => true,
            ])
            ->assertSessionHasErrors('compte_comptable_id');

        $this->assertSame($compte->id, $support->fresh()->compte_comptable_id);
    }

    public function test_update_verrouille_le_type_et_le_compte_apres_un_solde_douverture(): void
    {
        $site = $this->user->sites()->first();
        $compte = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();
        $compteBanque = CompteComptable::where('organization_id', $this->org->id)->where('numero', '521000')->firstOrFail();

        $support = CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'compte_comptable_id' => $compte->id,
            'type' => 'caisse',
            'libelle' => 'Caisse',
        ]);
        SoldeOuvertureTresorerie::create([
            'organization_id' => $this->org->id,
            'compte_tresorerie_id' => $support->id,
            'date_situation' => '2026-08-01',
            'montant' => 1_000_000,
            'statut' => StatutSoldeOuverture::BROUILLON->value,
        ]);

        $this->actingAs($this->user)
            ->put(route('comptabilite.tresorerie.supports.update', $support), [
                'libelle' => 'Caisse renommée',
                'type' => 'banque',
                'compte_comptable_id' => $compteBanque->id,
                'actif' => true,
            ])
            ->assertSessionHasErrors('type');

        // Requête rejetée dans son ensemble (transaction atomique) — le libellé
        // n'est pas non plus modifié par cette tentative.
        $support->refresh();
        $this->assertSame('caisse', $support->type->value);
        $this->assertSame($compte->id, $support->compte_comptable_id);
        $this->assertSame('Caisse', $support->libelle);
    }

    public function test_update_modifie_le_libelle_apres_un_solde_douverture_si_type_et_compte_inchanges(): void
    {
        $site = $this->user->sites()->first();
        $compte = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();

        $support = CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'compte_comptable_id' => $compte->id,
            'type' => 'caisse',
            'libelle' => 'Caisse',
        ]);
        SoldeOuvertureTresorerie::create([
            'organization_id' => $this->org->id,
            'compte_tresorerie_id' => $support->id,
            'date_situation' => '2026-08-01',
            'montant' => 1_000_000,
            'statut' => StatutSoldeOuverture::BROUILLON->value,
        ]);

        $this->actingAs($this->user)
            ->put(route('comptabilite.tresorerie.supports.update', $support), [
                'libelle' => 'Caisse renommée',
                'type' => 'caisse',
                'compte_comptable_id' => $compte->id,
                'actif' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Caisse renommée', $support->fresh()->libelle);
    }
}
