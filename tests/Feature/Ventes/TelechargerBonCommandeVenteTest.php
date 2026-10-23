<?php

namespace Tests\Feature\Ventes;

use App\Enums\StatutCommandeVente;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\HasProduitVariante;
use Tests\TestCase;

/** GET /backoffice/ventes/{commande}/pdf — bon de commande de vente au modèle officiel partagé. */
class TelechargerBonCommandeVenteTest extends TestCase
{
    use HasProduitVariante, RefreshDatabase;

    private Organization $org;

    private Site $agence;

    private CommandeVente $commande;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
        $this->agence = Site::factory()->for($this->org)->create(['nom' => 'Kaloum']);
        $this->commande = $this->commande($this->org, $this->agence);
    }

    private function commande(Organization $org, Site $site): CommandeVente
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $org->id,
            'site_id' => $site->id,
            'reference' => 'CMD-181026-'.random_int(100, 999),
            'total_commande' => 45000,
            'statut' => StatutCommandeVente::A_CHARGER,
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

        return $commande;
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

    public function test_telecharge_le_bon_de_commande_avec_la_reference_comme_nom(): void
    {
        $user = $this->utilisateur($this->org, 'admin_entreprise', ['ventes.read'], $this->agence);

        $reponse = $this->actingAs($user)->get(route('ventes.pdf', $this->commande));

        $reponse->assertOk()->assertHeader('content-type', 'application/pdf');
        $disposition = (string) $reponse->headers->get('content-disposition');
        $this->assertStringStartsWith('attachment;', $disposition);
        $this->assertStringContainsString('filename='.$this->commande->reference.'.pdf', $disposition);
        $this->assertStringStartsWith('%PDF-', $reponse->getContent());
    }

    public function test_affiche_le_bon_en_apercu_sans_forcer_le_telechargement(): void
    {
        $user = $this->utilisateur($this->org, 'admin_entreprise', ['ventes.read'], $this->agence);

        $reponse = $this->actingAs($user)->get(route('ventes.pdf', ['commande_vente' => $this->commande, 'preview' => 1]));

        $reponse->assertOk()->assertHeader('content-type', 'application/pdf');
        $disposition = (string) $reponse->headers->get('content-disposition');
        $this->assertStringStartsWith('inline;', $disposition);
        $this->assertStringContainsString('filename='.$this->commande->reference.'.pdf', $disposition);
        $this->assertStringStartsWith('%PDF-', $reponse->getContent());
    }

    public function test_reste_telechargeable_quel_que_soit_le_statut(): void
    {
        $user = $this->utilisateur($this->org, 'admin_entreprise', ['ventes.read'], $this->agence);

        foreach ([StatutCommandeVente::BROUILLON, StatutCommandeVente::ANNULEE, StatutCommandeVente::CLOTUREE] as $statut) {
            $this->commande->forceFill(['statut' => $statut])->saveQuietly();
            $this->actingAs($user)->get(route('ventes.pdf', $this->commande))->assertOk();
        }
    }

    public function test_refuse_sans_permission_de_consultation_des_ventes(): void
    {
        $user = $this->utilisateur($this->org, 'agent_test', ['ventes.update', 'ventes.create'], $this->agence);

        $this->actingAs($user)->get(route('ventes.pdf', $this->commande))->assertForbidden();
        $this->actingAs($user)->get(route('ventes.pdf', ['commande_vente' => $this->commande, 'preview' => 1]))->assertForbidden();
    }

    public function test_refuse_une_commande_d_une_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $intrus = $this->utilisateur($autreOrg, 'admin_entreprise', ['ventes.read'], null);

        $this->actingAs($intrus)->get(route('ventes.pdf', $this->commande))->assertForbidden();
    }

    public function test_refuse_hors_des_agences_de_l_utilisateur(): void
    {
        $autreAgence = Site::factory()->for($this->org)->create();
        $agent = $this->utilisateur($this->org, 'agent_test', ['ventes.read'], $autreAgence);

        $this->actingAs($agent)->get(route('ventes.pdf', $this->commande))->assertForbidden();

        $agent->sites()->attach($this->agence->id, ['role' => 'employe', 'is_default' => false]);
        $this->actingAs($agent->fresh())->get(route('ventes.pdf', $this->commande))->assertOk();
    }

    public function test_la_vision_toutes_agences_ouvre_le_telechargement(): void
    {
        $autreAgence = Site::factory()->for($this->org)->create();
        $agent = $this->utilisateur($this->org, 'agent_test', ['ventes.read', User::PERMISSION_LECTURE_TOUTES_AGENCES], $autreAgence);

        $this->actingAs($agent)->get(route('ventes.pdf', $this->commande))->assertOk();
    }

    public function test_invite_redirige_vers_la_connexion(): void
    {
        $this->get(route('ventes.pdf', $this->commande))->assertRedirect();
    }
}
