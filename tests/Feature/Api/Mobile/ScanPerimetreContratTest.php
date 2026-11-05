<?php

namespace Tests\Feature\Api\Mobile;

use App\Enums\NatureOperation;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutTransfert;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Parrain;
use App\Models\Personne;
use App\Models\Prestataire;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Contrat d'accès du scan QR : qui obtient quoi, indépendamment de l'implémentation. La référence
 * se déduit (numérotation séquentielle) : elle ne donne accès à rien sans relation ou habilitation.
 */
class ScanPerimetreContratTest extends TestCase
{
    use RefreshDatabase;

    private const TELEPHONE_CLIENT = '+224620000123';

    private const REFERENCE_VENTE = 'VTE-051126-001';

    private const REFERENCE_TRANSFERT = 'TRF-051126-001';

    private Organization $org;

    private Site $agence;

    private Site $autreAgence;

    private Site $arrivee;

    private Vehicule $vehicule;

    private Proprietaire $proprietaire;

    private EquipeLivraison $equipe;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->org = Organization::factory()->create();
        $this->agence = $this->site('Agence');
        $this->autreAgence = $this->site('Autre agence');
        $this->arrivee = $this->site('Arrivée');
        $this->proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $this->vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id, 'proprietaire_id' => $this->proprietaire->id]);
        $this->equipe = EquipeLivraison::create(['organization_id' => $this->org->id, 'vehicule_id' => $this->vehicule->id, 'is_active' => true]);
        $this->client = Client::factory()->create(['organization_id' => $this->org->id, 'telephone' => self::TELEPHONE_CLIENT, 'adresse' => 'Rue privée']);
        Client::whereKey($this->client->id)->update(['user_id' => null]);
    }

    // ── Ceux qui n'ont aucun lien : même réponse qu'une référence inconnue ───

    public function test_un_compte_sans_lien_obtient_la_reponse_d_une_reference_inconnue(): void
    {
        $this->vente();
        $this->transfert();
        $compte = $this->compte();

        $connue = $this->scanner($compte, self::REFERENCE_VENTE)->assertNotFound();
        $inconnue = $this->scanner($compte, 'VTE-010101-999')->assertNotFound();
        $this->assertSame($inconnue->json(), $connue->json());

        $connue = $this->scanner($compte, self::REFERENCE_TRANSFERT)->assertNotFound();
        $inconnue = $this->scanner($compte, 'TRF-010101-999')->assertNotFound();
        $this->assertSame($inconnue->json(), $connue->json());
    }

    public function test_le_parrain_du_vehicule_ne_scanne_pas_ses_operations(): void
    {
        $this->vente();
        $this->transfert();
        $compte = $this->compte();
        $parrain = Parrain::factory()->create(['organization_id' => $this->org->id]);
        $parrain->forceFill(['user_id' => $compte->id])->save();
        Vehicule::whereKey($this->vehicule->id)->update(['parrain_id' => $parrain->id]);

        $this->scanner($compte, self::REFERENCE_VENTE)->assertNotFound();
        $this->scanner($compte, self::REFERENCE_TRANSFERT)->assertNotFound();
    }

    public function test_un_consultant_ne_scanne_pas_les_operations_de_l_organisation(): void
    {
        $this->vente();
        $compte = $this->compte();
        $consultant = Prestataire::create([
            'organization_id' => $this->org->id,
            'personne_id' => Personne::factory()->create(['organization_id' => $this->org->id])->id,
            'type' => 'consultant',
        ]);
        $consultant->forceFill(['user_id' => $compte->id])->save();

        $this->scanner($compte, self::REFERENCE_VENTE)->assertNotFound();
    }

    public function test_un_compte_sans_organisation_recoit_404_et_jamais_500(): void
    {
        $this->vente();
        $this->transfert();
        $sansOrganisation = User::factory()->create(['organization_id' => null, 'telephone' => self::TELEPHONE_CLIENT]);

        $this->scanner($sansOrganisation, self::REFERENCE_VENTE)->assertNotFound();
        $this->scanner($sansOrganisation, self::REFERENCE_TRANSFERT)->assertNotFound();
    }

    public function test_le_super_administrateur_sans_organisation_n_obtient_rien(): void
    {
        $this->vente();
        $compte = User::factory()->create(['organization_id' => null]);
        $compte->assignRole('super_admin');

        $this->scanner($compte, self::REFERENCE_VENTE)->assertNotFound();
    }

    public function test_une_autre_organisation_ne_voit_rien_meme_avec_son_super_administrateur(): void
    {
        $this->vente();
        $autre = Organization::factory()->create();
        $superAdmin = User::factory()->create(['organization_id' => $autre->id]);
        $superAdmin->assignRole('super_admin');

        $this->scanner($superAdmin, self::REFERENCE_VENTE)->assertNotFound();
    }

    // ── Client ───────────────────────────────────────────────────────────────

    public function test_le_client_concerne_voit_sa_commande_complete(): void
    {
        $this->vente();
        $compte = $this->compte();
        Client::whereKey($this->client->id)->update(['user_id' => $compte->id]);

        $reponse = $this->scanner($compte, self::REFERENCE_VENTE)->assertOk();

        $this->assertSame(self::TELEPHONE_CLIENT, $reponse->json('client_telephone'));
        $this->assertStringContainsString('Rue privée', (string) $reponse->json('client_adresse'));
        $this->assertSame(1234567, (int) $reponse->json('total'));
    }

    public function test_un_autre_client_ne_voit_pas_la_commande(): void
    {
        $this->vente();
        $compte = $this->compte();
        Client::factory()->create(['organization_id' => $this->org->id, 'user_id' => $compte->id]);

        $this->scanner($compte, self::REFERENCE_VENTE)->assertNotFound();
    }

    // ── Livreur ──────────────────────────────────────────────────────────────

    public function test_le_livreur_voit_le_contact_en_cours_mais_jamais_le_montant(): void
    {
        $this->vente();
        $compte = $this->livreurDeLEquipe();

        $reponse = $this->scanner($compte, self::REFERENCE_VENTE)->assertOk();

        $this->assertSame(self::TELEPHONE_CLIENT, $reponse->json('client_telephone'));
        $this->assertNotNull($reponse->json('client_adresse'));
        $this->assertNull($reponse->json('total'));
    }

    public function test_le_nouveau_livreur_ne_scanne_pas_une_ancienne_vente_terminee(): void
    {
        $this->vente(['statut' => StatutCommandeVente::CLOTUREE, 'validated_at' => now()->subMonths(3)]);

        $this->scanner($this->livreurDeLEquipe(), self::REFERENCE_VENTE)->assertNotFound();
    }

    public function test_un_livreur_d_une_autre_equipe_ne_scanne_pas(): void
    {
        $this->vente();
        $compte = $this->compte();
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        Livreur::whereKey($livreur->id)->update(['user_id' => $compte->id]);

        $this->scanner($compte, self::REFERENCE_VENTE)->assertNotFound();
    }

    public function test_le_livreur_de_l_equipe_scanne_son_transfert(): void
    {
        $this->transfert();

        $this->scanner($this->livreurDeLEquipe(), self::REFERENCE_TRANSFERT)
            ->assertOk()
            ->assertJsonPath('type', 'transfert');
    }

    // ── Propriétaire ─────────────────────────────────────────────────────────

    public function test_le_proprietaire_voit_le_nom_sans_contact_ni_montant(): void
    {
        $this->vente();
        $compte = $this->compte();
        Proprietaire::whereKey($this->proprietaire->id)->update(['user_id' => $compte->id]);

        $reponse = $this->scanner($compte, self::REFERENCE_VENTE)->assertOk();

        $this->assertNotEmpty($reponse->json('client_nom'));
        $this->assertNull($reponse->json('client_telephone'));
        $this->assertNull($reponse->json('client_adresse'));
        $this->assertNull($reponse->json('total'));
    }

    public function test_le_proprietaire_d_un_autre_vehicule_ne_voit_rien(): void
    {
        $this->vente();
        $compte = $this->compte();
        Proprietaire::factory()->create(['organization_id' => $this->org->id, 'user_id' => $compte->id]);

        $this->scanner($compte, self::REFERENCE_VENTE)->assertNotFound();
    }

    // ── Agents et super administrateur ───────────────────────────────────────

    public function test_l_agent_habilite_sur_l_agence_voit_tout(): void
    {
        $this->vente();
        $agent = $this->agent(['ventes.read'], $this->agence);

        $reponse = $this->scanner($agent, self::REFERENCE_VENTE)->assertOk();

        $this->assertSame(self::TELEPHONE_CLIENT, $reponse->json('client_telephone'));
        $this->assertSame(1234567, (int) $reponse->json('total'));
    }

    public function test_l_agent_d_une_autre_agence_ne_voit_rien_meme_avec_la_permission(): void
    {
        $this->vente();
        $this->transfert();
        $agent = $this->agent(['ventes.read', 'logistique.read'], $this->autreAgence);

        $this->scanner($agent, self::REFERENCE_VENTE)->assertNotFound();
        $this->scanner($agent, self::REFERENCE_TRANSFERT)->assertNotFound();
    }

    public function test_l_agent_sans_la_permission_ne_voit_rien_meme_sur_son_agence(): void
    {
        $this->vente();
        $this->transfert();
        $agent = $this->agent([], $this->agence);

        $this->scanner($agent, self::REFERENCE_VENTE)->assertNotFound();
        $this->scanner($agent, self::REFERENCE_TRANSFERT)->assertNotFound();
    }

    public function test_l_agent_scanne_un_transfert_au_depart_ou_a_l_arrivee_de_son_agence(): void
    {
        $this->transfert();

        $this->scanner($this->agent(['logistique.read'], $this->agence), self::REFERENCE_TRANSFERT)->assertOk();
        $this->scanner($this->agent(['logistique.read'], $this->arrivee), self::REFERENCE_TRANSFERT)->assertOk();
    }

    public function test_la_vision_toutes_agences_permet_le_scan(): void
    {
        $this->vente();
        $agent = $this->agent(['ventes.read', User::PERMISSION_LECTURE_TOUTES_AGENCES], $this->autreAgence);

        $this->scanner($agent, self::REFERENCE_VENTE)->assertOk();
    }

    public function test_le_super_administrateur_scanne_dans_son_organisation(): void
    {
        $this->vente();
        $this->transfert();
        $superAdmin = $this->compte();
        $superAdmin->assignRole('super_admin');

        $this->scanner($superAdmin, self::REFERENCE_VENTE)->assertOk();
        $this->scanner($superAdmin, self::REFERENCE_TRANSFERT)->assertOk();
    }

    /** L'application Pro n'accepte que ce rôle et le super administrateur : son parcours est conservé. */
    public function test_l_administrateur_d_entreprise_de_l_application_pro_voit_tout(): void
    {
        $this->vente();
        $this->transfert();
        $admin = $this->compte();
        $admin->assignRole('admin_entreprise');

        $reponse = $this->scanner($admin, self::REFERENCE_VENTE)->assertOk();
        $this->assertSame(self::TELEPHONE_CLIENT, $reponse->json('client_telephone'));
        $this->assertSame(1234567, (int) $reponse->json('total'));

        $this->scanner($admin, self::REFERENCE_TRANSFERT)->assertOk();
    }

    /** Les deux applications lisent ces clés : le filtrage les met à null sans les retirer. */
    public function test_la_forme_de_la_reponse_ne_change_pas_selon_le_profil(): void
    {
        $this->vente();
        $compte = $this->compte();
        Proprietaire::whereKey($this->proprietaire->id)->update(['user_id' => $compte->id]);

        $this->scanner($compte, self::REFERENCE_VENTE)->assertOk()->assertJsonStructure([
            'type', 'reference', 'statut', 'statut_label', 'site_source', 'client_nom', 'client_telephone',
            'client_adresse', 'vehicule', 'equipe_nom', 'date_commande', 'nb_packs', 'total',
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function scanner(User $compte, string $reference): TestResponse
    {
        Sanctum::actingAs($compte->fresh());

        return $this->getJson(route('client.livraisons.scan', ['reference' => $reference]));
    }

    private function compte(): User
    {
        return User::factory()->create(['organization_id' => $this->org->id]);
    }

    /** @param  list<string>  $permissions */
    private function agent(array $permissions, Site $site): User
    {
        $agent = $this->compte();
        $agent->givePermissionTo($permissions);
        $agent->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $agent;
    }

    private function livreurDeLEquipe(): User
    {
        $compte = $this->compte();
        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        Livreur::whereKey($livreur->id)->update(['user_id' => $compte->id]);
        EquipeLivreur::create(['equipe_id' => $this->equipe->id, 'livreur_id' => $livreur->id, 'role' => 'chauffeur', 'ordre' => 0]);

        return $compte;
    }

    private function site(string $nom): Site
    {
        return Site::create(['organization_id' => $this->org->id, 'nom' => $nom, 'type' => 'depot', 'localisation' => 'Conakry']);
    }

    /** @param  array<string, mixed>  $attributs */
    private function vente(array $attributs = []): CommandeVente
    {
        return CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->agence->id,
            'vehicule_id' => $this->vehicule->id,
            'client_id' => $this->client->id,
            'reference' => self::REFERENCE_VENTE,
            'nature_operation' => NatureOperation::VENTE_STANDARD->value,
            'statut' => StatutCommandeVente::LIVRAISON_EN_COURS,
            'validated_at' => now(),
            'total_commande' => 1234567,
            ...$attributs,
        ]);
    }

    private function transfert(): TransfertLogistique
    {
        return TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $this->agence->id,
            'site_destination_id' => $this->arrivee->id,
            'vehicule_id' => $this->vehicule->id,
            'equipe_livraison_id' => $this->equipe->id,
            'reference' => self::REFERENCE_TRANSFERT,
            'statut' => StatutTransfert::TRANSIT,
            'created_by' => $this->compte()->id,
        ]);
    }
}
