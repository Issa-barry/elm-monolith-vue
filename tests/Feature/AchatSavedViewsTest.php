<?php

namespace Tests\Feature;

use App\Features\ModuleFeature;
use App\Models\CommandeAchat;
use App\Models\EntrepriseTierce;
use App\Models\Fournisseur;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\RegleValidationRole;
use App\Models\SavedFilter;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class AchatSavedViewsTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['achats.read']);
        Feature::for($this->org)->activate(ModuleFeature::ACHATS);
        RegleValidationRole::create([
            'organization_id' => $this->org->id, 'domaine' => RegleValidationRole::DOMAINE_ACHATS,
            'role_name' => 'admin_entreprise', 'perimetre' => 'toutes_agences', 'plafond_illimite' => true,
        ]);
        $this->actingAs($this->user);
    }

    private function commande(string $reference, array $attributes = []): CommandeAchat
    {
        return CommandeAchat::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->user->sites()->firstOrFail()->id,
            'reference' => $reference, 'total_commande' => 3000, 'statut' => 'validee',
            ...$attributes,
        ]);
    }

    private function save(string $name, array $filters, bool $default = false): string
    {
        return $this->postJson(route('saved-filters.store', 'achats'), [
            'name' => $name, 'visibility' => 'personal', 'filters' => $filters, 'is_default' => $default,
        ])->assertCreated()->json('id');
    }

    public function test_purchase_views_apply_defaults_manual_search_and_consultable_agencies(): void
    {
        // Cette agence est autorisée par la règle achats, sans affectation personnelle.
        $site = Site::factory()->create(['organization_id' => $this->org->id]);
        $this->commande('BC-VISIBLE', ['site_id' => $site->id]);
        $this->commande('BC-MASQUEE', ['site_id' => $site->id, 'statut' => 'a_valider']);
        $this->commande('BC-AUTRE');
        $id = $this->save('Validées', ['statut' => 'validee', 'site_ids' => [$site->id]], true);
        foreach (['', '?saved_view='.$id] as $query) {
            $this->get('/backoffice/achats'.$query)->assertOk()->assertInertia(fn (Assert $p) => $p
                ->where('saved_view.id', $id)->where('filters.site_ids', [$site->id])
                ->has('commandes.data', 1)->where('commandes.data.0.reference', 'BC-VISIBLE'));
        }
        $this->get('/backoffice/achats?all=1')->assertInertia(fn (Assert $p) => $p
            ->where('saved_view', null)->where('commandes.total', 3));
        $this->get('/backoffice/achats?recherche=MASQUEE')->assertInertia(fn (Assert $p) => $p
            ->where('saved_view', null)->has('commandes.data', 1)->where('commandes.data.0.reference', 'BC-MASQUEE'));
        $other = $this->save('À valider', ['statut' => 'a_valider'], true);
        $this->getJson(route('saved-filters.index', 'achats'))->assertJsonCount(2, 'views')->assertJsonPath('default_id', $other);
        $this->get('/backoffice/achats?saved_view='.$id)->assertInertia(fn (Assert $p) => $p->where('saved_view.id', $id));
    }

    public function test_search_matches_reference_supplier_person_and_snapshot_before_pagination(): void
    {
        $enterprise = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'Topaz']);
        $supplier = Fournisseur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $enterprise->id]);
        $target = $this->commande('BC-CIBLE', ['fournisseur_id' => $supplier->id, 'created_at' => now()->subDay()]);
        for ($i = 0; $i < 31; $i++) {
            $this->commande('BC-AUTRE-'.$i);
        }
        $this->commande('BC-ETRANGERE', ['organization_id' => Organization::factory()->create()->id, 'fournisseur_id' => $supplier->id]);
        foreach (['CIBLE', 'Topaz'] as $search) {
            $this->get('/backoffice/achats?recherche='.$search)->assertOk()->assertInertia(fn (Assert $p) => $p
                ->where('commandes.total', 1)->where('commandes.data.0.id', $target->id));
        }
        $person = Personne::create(['organization_id' => $this->org->id, 'nom_complet' => 'Aissatou Barry']);
        $physical = Fournisseur::create(['organization_id' => $this->org->id, 'personne_id' => $person->id]);
        $this->commande('BC-PERSONNE', ['fournisseur_id' => $physical->id]);
        $this->get('/backoffice/achats?recherche=Aissatou')->assertInertia(fn (Assert $p) => $p->where('commandes.total', 1));
        $target->update(['fournisseur_nom_snapshot' => 'Ancien fournisseur']);
        $this->get('/backoffice/achats?recherche=Ancien')->assertInertia(fn (Assert $p) => $p->where('commandes.total', 1));
        $this->get('/backoffice/achats?reference=BC-CIBLE')->assertInertia(fn (Assert $p) => $p->where('commandes.total', 1));
        $id = $this->save('Topaz', ['fournisseur_id' => $supplier->id, 'recherche' => 'CIBLE']);
        $enterprise->update(['raison_sociale' => 'Nouveau nom']);
        $this->get('/backoffice/achats?saved_view='.$id)->assertInertia(fn (Assert $p) => $p->where('commandes.total', 1));
    }

    public function test_views_enforce_permissions_scope_organization_and_valid_criteria(): void
    {
        $payload = ['name' => 'Partagée', 'visibility' => 'shared', 'filters' => ['statut' => 'validee']];
        $this->postJson(route('saved-filters.store', 'achats'), $payload)->assertForbidden();
        Permission::firstOrCreate(['name' => 'achats.update', 'guard_name' => 'web']);
        $this->user->givePermissionTo('achats.update');
        $id = $this->postJson(route('saved-filters.store', 'achats'), $payload)->assertCreated()->json('id');
        foreach ([['statut' => 'inconnu'], ['fournisseur_id' => $this->org->id], ['site_ids' => [Site::factory()->create()->id]], ['a_valider_par_moi' => 'invalide']] as $filters) {
            $this->postJson(route('saved-filters.store', 'achats'), [
                'name' => 'Invalide', 'visibility' => 'personal', 'filters' => $filters,
            ])->assertUnprocessable();
        }
        SavedFilter::findOrFail($id)->update(['scope' => 'ventes']);
        $this->get('/backoffice/achats?saved_view='.$id)->assertNotFound();
        SavedFilter::findOrFail($id)->update(['scope' => 'achats', 'organization_id' => Organization::factory()->create()->id]);
        $this->get('/backoffice/achats?saved_view='.$id)->assertNotFound();
        $this->user->revokePermissionTo('achats.read');
        $this->getJson(route('saved-filters.index', 'achats'))->assertForbidden();
    }

    public function test_saved_views_cannot_restore_lost_purchase_scope_or_validation_permission(): void
    {
        $other = Site::factory()->create(['organization_id' => $this->org->id]);
        $this->commande('BC-EXTERIEUR', ['site_id' => $other->id]);
        $id = $this->save('Agence extérieure', ['site_ids' => [$other->id]]);
        RegleValidationRole::where('organization_id', $this->org->id)->firstOrFail()->update([
            'perimetre' => 'agences_selectionnees', 'sites' => [$this->user->sites()->firstOrFail()->id],
        ]);
        $this->get('/backoffice/achats?saved_view='.$id)->assertForbidden();
        $this->postJson(route('saved-filters.store', 'achats'), [
            'name' => 'Hors périmètre', 'visibility' => 'personal', 'filters' => ['site_ids' => [$other->id]],
        ])->assertUnprocessable();
        $mine = $this->save('À valider par moi', ['a_valider_par_moi' => '1']);
        $this->get('/backoffice/achats?saved_view='.$mine)->assertInertia(fn (Assert $p) => $p->where('commandes.total', 0));
    }
}
