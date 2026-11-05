<?php

namespace Tests\Feature\Api\Mobile;

use App\Enums\StatutCommandeVente;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Tests\Feature\Concerns\MakesClientProfiles;
use Tests\TestCase;

/**
 * Chantier du 31/08/2026 : le scan mobile (GET /v1/mobile/livraisons/scan/{reference}) doit
 * reconnaître à la fois les anciennes références (CMD-.../TR-...) et les nouvelles (VTE-/DST-/
 * TRF-) — cf. ScanCommandeController et docs/api-espace-client-contract.md.
 *
 * Correctif du 31/08/2026 (bloquant avant commit) : la numérotation étant désormais scopée par
 * organisation (cf. docs/references-metier.md), deux organisations peuvent porter EXACTEMENT la
 * même référence (ex: VTE-310826-001 pour l'une ET l'autre) — une recherche non filtrée par
 * organization_id retournerait alors potentiellement les données de la mauvaise organisation.
 * Les tests ci-dessous vérifient l'isolation stricte, y compris sur les préfixes legacy.
 */
class ScanCommandeControllerTest extends TestCase
{
    use MakesClientProfiles, RefreshDatabase;

    private Organization $org;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureClientRoles();
        $this->org = Organization::factory()->create();
        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
    }

    private function actingAsClientRole(): void
    {
        $this->actingAsOrg($this->org);
    }

    /** Personnel habilité : depuis le 05/11/2026, le rôle `client` seul ne permet plus de lire une référence. */
    private function actingAsOrg(Organization $org): void
    {
        Sanctum::actingAs($this->agent($org, ['ventes.read', 'logistique.read', User::PERMISSION_LECTURE_TOUTES_AGENCES]), ['*']);
    }

    /** @param  list<string>  $permissions */
    private function agent(Organization $org, array $permissions): User
    {
        $user = User::factory()->create(['organization_id' => $org->id]);
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function compte(?string $organizationId = null): User
    {
        $this->ensureClientRoles();

        return User::factory()->create([
            'organization_id' => $organizationId ?? $this->org->id,
            'telephone' => '+224'.fake()->unique()->numerify('6########'),
        ]);
    }

    /** @return array{client: User, livreur: User, proprietaire: User, commande: CommandeVente} */
    private function commandeAvecSesComptes(StatutCommandeVente $statut = StatutCommandeVente::LIVRAISON_EN_COURS): array
    {
        $compteClient = $this->compte();
        $client = Client::factory()->create([
            'organization_id' => $this->org->id,
            'user_id' => $compteClient->id,
            'telephone' => '+224621000001',
            'adresse' => 'Cité Enco-5, Ratoma',
            'ville' => 'Conakry',
        ]);
        $compteProprietaire = $this->compte();
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => Proprietaire::factory()->create(['organization_id' => $this->org->id, 'user_id' => $compteProprietaire->id])->id,
        ]);
        $compteLivreur = $this->compte();
        $equipe = EquipeLivraison::create(['organization_id' => $this->org->id, 'vehicule_id' => $vehicule->id, 'nom' => 'Equipe', 'is_active' => true, 'taux_commission_proprietaire' => 0]);
        EquipeLivreur::create([
            'equipe_id' => $equipe->id,
            'livreur_id' => Livreur::factory()->create(['organization_id' => $this->org->id, 'user_id' => $compteLivreur->id])->id,
            'role' => 'chauffeur',
            'ordre' => 1,
        ]);

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'client_id' => $client->id,
            'vehicule_id' => $vehicule->id,
            'statut' => $statut->value,
            'reference' => 'VTE-051126-001',
            'total_commande' => 480000,
            'validated_at' => now(),
        ]);

        return ['client' => $compteClient, 'livreur' => $compteLivreur, 'proprietaire' => $compteProprietaire, 'commande' => $commande];
    }

    private function scanPar(User $user, string $reference): TestResponse
    {
        Sanctum::actingAs($user, ['*']);

        return $this->scan($reference);
    }

    // ── Contrôle des relations réelles (05/11/2026) ───────────────────────────────

    public function test_un_compte_sans_lien_ne_lit_pas_une_commande_de_son_organisation(): void
    {
        $this->commandeAvecSesComptes();
        $intrus = $this->compte();
        $intrus->assignRole('client');

        $reponse = $this->scanPar($intrus, 'VTE-051126-001')->assertNotFound()->assertJson(['message' => 'Référence introuvable.']);

        $this->assertStringNotContainsString('+224621000001', $reponse->getContent());
    }

    public function test_un_compte_sans_lien_ne_lit_pas_un_transfert_de_son_organisation(): void
    {
        $createur = $this->compte();
        TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $this->site->id,
            'site_destination_id' => $this->site->id,
            'reference' => 'TRF-051126-001',
            'created_by' => $createur->id,
        ]);
        $intrus = $this->compte();
        $intrus->assignRole('client');

        $this->scanPar($intrus, 'TRF-051126-001')->assertNotFound()->assertJson(['message' => 'Référence introuvable.']);
    }

    public function test_un_compte_sans_organisation_recoit_un_404_et_non_une_erreur(): void
    {
        $this->commandeAvecSesComptes();
        $sansOrganisation = User::factory()->create(['organization_id' => null, 'telephone' => '+224'.fake()->unique()->numerify('6########')]);

        $this->scanPar($sansOrganisation, 'VTE-051126-001')->assertNotFound();
        $this->scanPar($sansOrganisation, 'TRF-051126-001')->assertNotFound();
    }

    public function test_un_compte_sans_organisation_n_obtient_aucun_acces_par_son_telephone(): void
    {
        $cas = $this->commandeAvecSesComptes();
        $cas['commande']->client->update(['user_id' => null]);
        $memeNumero = User::factory()->create(['organization_id' => null, 'telephone' => '+224621000001']);

        $this->scanPar($memeNumero, 'VTE-051126-001')->assertNotFound();
    }

    public function test_le_client_lit_sa_commande_avec_total_et_coordonnees(): void
    {
        $cas = $this->commandeAvecSesComptes();

        $this->scanPar($cas['client'], 'VTE-051126-001')
            ->assertOk()
            ->assertJson(['total' => 480000.0, 'client_telephone' => '+224621000001', 'client_adresse' => 'Cité Enco-5, Ratoma, Conakry']);
    }

    /** Régression : le scan sélectionnait une colonne `quartier` absente de `clients`, donc tombait en 500 sur toute commande liée à un client. */
    public function test_le_scan_d_une_commande_avec_client_repond(): void
    {
        $this->commandeAvecSesComptes();
        $this->actingAsOrg($this->org);

        $this->scan('VTE-051126-001')->assertOk()->assertJsonStructure(['client_nom', 'client_telephone', 'client_adresse']);
    }

    public function test_le_livreur_lit_les_coordonnees_pendant_la_livraison_sans_le_total(): void
    {
        $cas = $this->commandeAvecSesComptes();

        $this->scanPar($cas['livreur'], 'VTE-051126-001')
            ->assertOk()
            ->assertJson(['total' => null, 'client_telephone' => '+224621000001']);

        $cas['commande']->update(['statut' => StatutCommandeVente::CLOTUREE]);

        $this->scanPar($cas['livreur'], 'VTE-051126-001')
            ->assertOk()
            ->assertJson(['total' => null, 'client_telephone' => null, 'client_adresse' => null]);
    }

    public function test_le_proprietaire_lit_le_client_sans_coordonnees_ni_total(): void
    {
        $cas = $this->commandeAvecSesComptes();

        $this->scanPar($cas['proprietaire'], 'VTE-051126-001')
            ->assertOk()
            ->assertJson(['client_nom' => $cas['commande']->client->nom_complet, 'total' => null, 'client_telephone' => null, 'client_adresse' => null]);
    }

    public function test_le_personnel_habilite_garde_la_lecture_complete(): void
    {
        $this->commandeAvecSesComptes();
        $this->actingAsOrg($this->org);

        $this->scan('VTE-051126-001')
            ->assertOk()
            ->assertJson(['total' => 480000.0, 'client_telephone' => '+224621000001']);
    }

    public function test_un_agent_ne_lit_que_les_operations_de_ses_agences(): void
    {
        $this->commandeAvecSesComptes();
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Autre agence', 'type' => 'depot', 'localisation' => 'Kindia']);

        $agentAilleurs = $this->agent($this->org, ['ventes.read']);
        $agentAilleurs->sites()->attach($autreSite->id);
        $this->scanPar($agentAilleurs, 'VTE-051126-001')->assertNotFound();

        $agentSurPlace = $this->agent($this->org, ['ventes.read']);
        $agentSurPlace->sites()->attach($this->site->id);
        $this->scanPar($agentSurPlace, 'VTE-051126-001')->assertOk()->assertJson(['total' => 480000.0]);
    }

    public function test_un_agent_lit_un_transfert_de_son_agence_de_depart_ou_d_arrivee(): void
    {
        $destination = Site::create(['organization_id' => $this->org->id, 'nom' => 'Arrivée', 'type' => 'depot', 'localisation' => 'Kindia']);
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Autre agence', 'type' => 'depot', 'localisation' => 'Labé']);
        TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $this->site->id,
            'site_destination_id' => $destination->id,
            'reference' => 'TRF-051126-002',
            'created_by' => $this->compte()->id,
        ]);

        $agentAilleurs = $this->agent($this->org, ['logistique.read']);
        $agentAilleurs->sites()->attach($autreSite->id);
        $this->scanPar($agentAilleurs, 'TRF-051126-002')->assertNotFound();

        $agentArrivee = $this->agent($this->org, ['logistique.read']);
        $agentArrivee->sites()->attach($destination->id);
        $this->scanPar($agentArrivee, 'TRF-051126-002')->assertOk();
    }

    private function scan(string $reference): TestResponse
    {
        return $this->getJson(route('client.livraisons.scan', ['reference' => $reference]));
    }

    // ── Commandes : legacy CMD- et nouveaux VTE-/DST- ────────────────────────

    public function test_scan_reconnait_lancien_prefixe_cmd(): void
    {
        CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'reference' => 'CMD-010125-001',
        ]);
        $this->actingAsClientRole();

        $this->scan('CMD-010125-001')
            ->assertOk()
            ->assertJson(['type' => 'commande', 'reference' => 'CMD-010125-001']);
    }

    public function test_scan_reconnait_le_nouveau_prefixe_vte(): void
    {
        CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'reference' => 'VTE-310826-001',
        ]);
        $this->actingAsClientRole();

        $this->scan('VTE-310826-001')
            ->assertOk()
            ->assertJson(['type' => 'commande', 'reference' => 'VTE-310826-001']);
    }

    public function test_scan_reconnait_le_nouveau_prefixe_dst(): void
    {
        CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'reference' => 'DST-310826-001',
        ]);
        $this->actingAsClientRole();

        $this->scan('DST-310826-001')
            ->assertOk()
            ->assertJson(['type' => 'commande', 'reference' => 'DST-310826-001']);
    }

    // ── Transferts : legacy TR- et nouveau TRF- ──────────────────────────────

    public function test_scan_reconnait_lancien_prefixe_tr(): void
    {
        $siteDest = Site::create(['organization_id' => $this->org->id, 'nom' => 'Dest', 'type' => 'depot', 'localisation' => 'Kindia']);
        $creator = User::factory()->create(['organization_id' => $this->org->id]);
        TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $this->site->id,
            'site_destination_id' => $siteDest->id,
            'reference' => 'TR-00042-K7X',
            'created_by' => $creator->id,
        ]);
        $this->actingAsClientRole();

        $this->scan('TR-00042-K7X')
            ->assertOk()
            ->assertJson(['type' => 'transfert', 'reference' => 'TR-00042-K7X']);
    }

    public function test_scan_reconnait_le_nouveau_prefixe_trf(): void
    {
        $siteDest = Site::create(['organization_id' => $this->org->id, 'nom' => 'Dest', 'type' => 'depot', 'localisation' => 'Kindia']);
        $creator = User::factory()->create(['organization_id' => $this->org->id]);
        TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $this->site->id,
            'site_destination_id' => $siteDest->id,
            'reference' => 'TRF-310826-001',
            'created_by' => $creator->id,
        ]);
        $this->actingAsClientRole();

        $this->scan('TRF-310826-001')
            ->assertOk()
            ->assertJson(['type' => 'transfert', 'reference' => 'TRF-310826-001']);
    }

    public function test_scan_rejette_un_prefixe_non_reconnu(): void
    {
        $this->actingAsClientRole();

        $this->scan('XYZ-000000-001')
            ->assertNotFound()
            ->assertJson(['message' => 'Référence introuvable.']);
    }

    // ── Isolation multi-organisations : même référence, organisations distinctes ────

    /** @return array<string, array{0: string}> */
    public static function referencesCommandeProvider(): array
    {
        return [
            'legacy CMD' => ['CMD-310826-001'],
            'nouveau VTE' => ['VTE-310826-001'],
            'nouveau DST' => ['DST-310826-001'],
        ];
    }

    #[DataProvider('referencesCommandeProvider')]
    public function test_isolation_deux_organisations_avec_exactement_la_meme_reference_commande(string $reference): void
    {
        CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'reference' => $reference,
            'total_commande' => 11111,
        ]);

        $orgB = Organization::factory()->create();
        $siteB = Site::create(['organization_id' => $orgB->id, 'nom' => 'Site B', 'type' => 'depot', 'localisation' => 'Kindia']);
        CommandeVente::factory()->create([
            'organization_id' => $orgB->id,
            'site_id' => $siteB->id,
            'reference' => $reference,
            'total_commande' => 22222,
        ]);

        $this->actingAsOrg($this->org);
        $this->scan($reference)
            ->assertOk()
            ->assertJson(['reference' => $reference, 'total' => 11111.0]);

        $this->actingAsOrg($orgB);
        $this->scan($reference)
            ->assertOk()
            ->assertJson(['reference' => $reference, 'total' => 22222.0]);
    }

    /** @return array<string, array{0: string}> */
    public static function referencesTransfertProvider(): array
    {
        return [
            'legacy TR' => ['TR-00042-K7X'],
            'nouveau TRF' => ['TRF-310826-001'],
        ];
    }

    #[DataProvider('referencesTransfertProvider')]
    public function test_isolation_deux_organisations_avec_exactement_la_meme_reference_transfert(string $reference): void
    {
        $siteDestA = Site::create(['organization_id' => $this->org->id, 'nom' => 'Dest A', 'type' => 'depot', 'localisation' => 'Kindia']);
        $creatorA = User::factory()->create(['organization_id' => $this->org->id]);
        TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $this->site->id,
            'site_destination_id' => $siteDestA->id,
            'reference' => $reference,
            'created_by' => $creatorA->id,
        ]);

        $orgB = Organization::factory()->create();
        $siteSourceB = Site::create(['organization_id' => $orgB->id, 'nom' => 'Site B', 'type' => 'depot', 'localisation' => 'Conakry']);
        $siteDestB = Site::create(['organization_id' => $orgB->id, 'nom' => 'Dest B', 'type' => 'depot', 'localisation' => 'Kindia']);
        $creatorB = User::factory()->create(['organization_id' => $orgB->id]);
        TransfertLogistique::create([
            'organization_id' => $orgB->id,
            'site_source_id' => $siteSourceB->id,
            'site_destination_id' => $siteDestB->id,
            'reference' => $reference,
            'created_by' => $creatorB->id,
        ]);

        $this->actingAsOrg($this->org);
        $this->scan($reference)
            ->assertOk()
            ->assertJson(['reference' => $reference, 'site_source' => 'Site Test']);

        $this->actingAsOrg($orgB);
        $this->scan($reference)
            ->assertOk()
            ->assertJson(['reference' => $reference, 'site_source' => 'Site B']);
    }

    // ── Référence existant uniquement dans une autre organisation → 404 ─────────────

    public function test_scan_dune_commande_dune_autre_organisation_retourne_404(): void
    {
        $orgB = Organization::factory()->create();
        $siteB = Site::create(['organization_id' => $orgB->id, 'nom' => 'Site B', 'type' => 'depot', 'localisation' => 'Kindia']);
        CommandeVente::factory()->create([
            'organization_id' => $orgB->id,
            'site_id' => $siteB->id,
            'reference' => 'VTE-310826-009',
        ]);

        // Utilisateur de $this->org (org A) — la référence n'existe que dans orgB.
        $this->actingAsClientRole();

        $this->scan('VTE-310826-009')
            ->assertNotFound()
            ->assertJson(['message' => 'Référence introuvable.']);
    }

    public function test_scan_dun_transfert_dune_autre_organisation_retourne_404(): void
    {
        $orgB = Organization::factory()->create();
        $siteSourceB = Site::create(['organization_id' => $orgB->id, 'nom' => 'Src B', 'type' => 'depot', 'localisation' => 'Conakry']);
        $siteDestB = Site::create(['organization_id' => $orgB->id, 'nom' => 'Dest B', 'type' => 'depot', 'localisation' => 'Kindia']);
        $creatorB = User::factory()->create(['organization_id' => $orgB->id]);
        TransfertLogistique::create([
            'organization_id' => $orgB->id,
            'site_source_id' => $siteSourceB->id,
            'site_destination_id' => $siteDestB->id,
            'reference' => 'TRF-310826-009',
            'created_by' => $creatorB->id,
        ]);

        // Utilisateur de $this->org (org A) — la référence n'existe que dans orgB.
        $this->actingAsClientRole();

        $this->scan('TRF-310826-009')
            ->assertNotFound()
            ->assertJson(['message' => 'Référence introuvable.']);
    }
}
