<?php

namespace Tests\Feature\Ventes;

use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\HasProduitVariante;
use Tests\TestCase;

/** GET /backoffice/factures/{facture}/pdf — facture de vente au modèle officiel partagé. */
class TelechargerFactureVenteTest extends TestCase
{
    use HasProduitVariante, RefreshDatabase;

    private Organization $org;

    private Site $agence;

    private FactureVente $facture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        $this->agence = Site::factory()->for($this->org)->create(['nom' => 'Kaloum']);
        $this->facture = $this->facture($this->org, $this->agence);
    }

    private function facture(Organization $org, Site $site): FactureVente
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'site_id' => $site->id,
            'reference' => 'CMD-181026-'.random_int(100, 999),
            'total_commande' => 45000,
        ]);
        CommandeVenteLigne::create([
            'commande_vente_id' => $commande->id,
            'variante_id' => $this->makeProduitAvecVariante($org)->variantes()->first()->id,
            'quantite_demandee' => 10,
            'libelle_snapshot' => 'Pack Eau 1,5 L',
            'prix_usine_snapshot' => 4000,
            'prix_vente_snapshot' => 4500,
            'total_ligne' => 45000,
        ]);

        return FactureVente::factory()->payee()->create([
            'organization_id' => $org->id,
            'site_id' => $site->id,
            'commande_vente_id' => $commande->id,
            'montant_brut' => 45000,
            'montant_net' => 45000,
        ]);
    }

    /** @param  list<string>  $permissions */
    private function utilisateur(Organization $org, string $role, array $permissions, ?Site $site): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole($role);
        $user->givePermissionTo($permissions);
        $site ??= Site::factory()->for($org)->create();
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    public function test_telecharge_le_pdf_d_une_facture_payee_avec_la_reference_comme_nom(): void
    {
        $user = $this->utilisateur($this->org, 'admin_entreprise', ['ventes.read'], $this->agence);

        $reponse = $this->actingAs($user)->get(route('factures.pdf', $this->facture));

        $reponse->assertOk()->assertHeader('content-type', 'application/pdf');
        $disposition = (string) $reponse->headers->get('content-disposition');
        $this->assertStringStartsWith('attachment;', $disposition);
        $this->assertStringContainsString('filename='.$this->facture->reference.'.pdf', $disposition);
        $this->assertStringStartsWith('%PDF-', $reponse->getContent());
    }

    public function test_affiche_le_pdf_en_apercu_sans_forcer_le_telechargement(): void
    {
        $user = $this->utilisateur($this->org, 'admin_entreprise', ['ventes.read'], $this->agence);

        $reponse = $this->actingAs($user)->get(route('factures.pdf', ['facture_vente' => $this->facture, 'preview' => 1]));

        $reponse->assertOk()->assertHeader('content-type', 'application/pdf');
        $disposition = (string) $reponse->headers->get('content-disposition');
        $this->assertStringStartsWith('inline;', $disposition);
        $this->assertStringContainsString('filename='.$this->facture->reference.'.pdf', $disposition);
        $this->assertStringStartsWith('%PDF-', $reponse->getContent());
    }

    public function test_refuse_sans_permission_de_consultation_des_ventes(): void
    {
        $user = $this->utilisateur($this->org, 'agent_test', ['ventes.update', 'factures.encaisser'], $this->agence);

        $this->actingAs($user)->get(route('factures.pdf', $this->facture))->assertForbidden();
        $this->actingAs($user)->get(route('factures.pdf', ['facture_vente' => $this->facture, 'preview' => 1]))->assertForbidden();
    }

    public function test_refuse_une_facture_d_une_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $intrus = $this->utilisateur($autreOrg, 'admin_entreprise', ['ventes.read'], null);

        $this->actingAs($intrus)->get(route('factures.pdf', $this->facture))->assertForbidden();
    }

    public function test_refuse_hors_des_agences_de_l_utilisateur(): void
    {
        $autreAgence = Site::factory()->for($this->org)->create();
        $agent = $this->utilisateur($this->org, 'agent_test', ['ventes.read'], $autreAgence);

        $this->actingAs($agent)->get(route('factures.pdf', $this->facture))->assertForbidden();

        $agent->sites()->attach($this->agence->id, ['role' => 'employe', 'is_default' => false]);
        $this->actingAs($agent->fresh())->get(route('factures.pdf', $this->facture))->assertOk();
    }

    public function test_la_vision_toutes_agences_ouvre_le_telechargement(): void
    {
        $autreAgence = Site::factory()->for($this->org)->create();
        $agent = $this->utilisateur($this->org, 'agent_test', ['ventes.read', User::PERMISSION_LECTURE_TOUTES_AGENCES], $autreAgence);

        $this->actingAs($agent)->get(route('factures.pdf', $this->facture))->assertOk();
    }
}
