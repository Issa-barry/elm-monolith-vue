<?php

namespace Tests\Feature\Contacts;

use App\Enums\ClientType;
use App\Enums\PrestataireType;
use App\Models\Client;
use App\Models\EntrepriseInterlocuteur;
use App\Models\EntrepriseTierce;
use App\Models\Fournisseur;
use App\Models\Organization;
use App\Models\Parrain;
use App\Models\Personne;
use App\Models\Prestataire;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\Contacts\FonctionsContact;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Lecture des fonctions d'une fiche (ADR 0060, CONTACT-007) : ids des fonctions inchangés,
 * cloisonnement par organisation, parrains limités au périmètre d'agences du lecteur.
 */
class FonctionsContactTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private FonctionsContact $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->service = app(FonctionsContact::class);
    }

    /** Personnel d'une seule agence, sans vision toutes agences. */
    private function lecteurAgence(Site $site): User
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('manager');
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function lecteurToutesAgences(): User
    {
        $site = Site::factory()->create(['organization_id' => $this->org->id]);
        $user = $this->lecteurAgence($site);
        Permission::firstOrCreate(['name' => User::PERMISSION_LECTURE_TOUTES_AGENCES, 'guard_name' => 'web']);
        $user->givePermissionTo(User::PERMISSION_LECTURE_TOUTES_AGENCES);

        return $user;
    }

    // Création directe : la factory Parrain remplace la personne qu'on lui passe.
    private function parrain(Personne $personne): Parrain
    {
        return Parrain::create(['organization_id' => $this->org->id, 'personne_id' => $personne->id, 'is_active' => true]);
    }

    private function fonctions(array $lignes): array
    {
        return collect($lignes)->map(fn (array $l) => $l['fonction'].':'.$l['id'])->sort()->values()->all();
    }

    public function test_une_personne_a_plusieurs_fonctions_les_retrouve_avec_leurs_ids(): void
    {
        $personne = Personne::factory()->create(['organization_id' => $this->org->id]);
        $client = Client::create(['organization_id' => $this->org->id, 'personne_id' => $personne->id, 'nom_complet' => 'X', 'telephone' => $personne->telephone, 'type' => ClientType::GROSSISTE->value]);
        $fournisseur = Fournisseur::create(['organization_id' => $this->org->id, 'personne_id' => $personne->id]);
        $prestataire = Prestataire::create(['organization_id' => $this->org->id, 'personne_id' => $personne->id, 'type' => PrestataireType::MECANICIEN->value]);
        $proprietaire = Proprietaire::create(['organization_id' => $this->org->id, 'personne_id' => $personne->id, 'type' => 'personne_physique', 'is_active' => true]);
        $entreprise = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'ABC']);
        $lien = EntrepriseInterlocuteur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $entreprise->id, 'personne_id' => $personne->id]);

        $resultat = $this->service->pourPersonne($personne, $this->lecteurToutesAgences());

        $this->assertSame($this->fonctions([
            ['fonction' => 'client', 'id' => $client->id],
            ['fonction' => 'fournisseur', 'id' => $fournisseur->id],
            ['fonction' => 'prestataire', 'id' => $prestataire->id],
            ['fonction' => 'proprietaire', 'id' => $proprietaire->id],
            ['fonction' => 'interlocuteur', 'id' => $lien->id],
        ]), $this->fonctions($resultat));

        $parFonction = collect($resultat)->keyBy('fonction');
        $this->assertSame('grossiste', $parFonction['client']['sous_categorie']);
        $this->assertSame('mecanicien', $parFonction['prestataire']['sous_categorie']);
        $this->assertSame($entreprise->id, $parFonction['interlocuteur']['entreprise_tierce_id']);
    }

    public function test_les_fonctions_d_une_entreprise(): void
    {
        $entreprise = EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => 'ABC']);
        $fournisseur = Fournisseur::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $entreprise->id]);
        $prestataire = Prestataire::create(['organization_id' => $this->org->id, 'entreprise_tierce_id' => $entreprise->id, 'type' => PrestataireType::CONSULTANT->value]);

        $resultat = $this->service->pourEntreprise($entreprise, $this->lecteurToutesAgences());

        $this->assertSame($this->fonctions([
            ['fonction' => 'fournisseur', 'id' => $fournisseur->id],
            ['fonction' => 'prestataire', 'id' => $prestataire->id],
        ]), $this->fonctions($resultat));
    }

    public function test_une_fiche_d_une_autre_organisation_est_refusee(): void
    {
        $autreOrg = Organization::factory()->create();
        $personne = Personne::factory()->create(['organization_id' => $autreOrg->id]);
        $entreprise = EntrepriseTierce::create(['organization_id' => $autreOrg->id, 'raison_sociale' => 'Ailleurs']);
        $lecteur = $this->lecteurToutesAgences();

        foreach ([fn () => $this->service->pourPersonne($personne, $lecteur), fn () => $this->service->pourEntreprise($entreprise, $lecteur)] as $lecture) {
            try {
                $lecture();
                $this->fail('Fiche d\'une autre organisation lue.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_une_personne_sans_organisation_est_refusee(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->service->pourPersonne(Personne::factory()->create(['organization_id' => null]), $this->lecteurToutesAgences());
    }

    public function test_un_parrain_hors_du_perimetre_d_agences_n_est_jamais_retourne(): void
    {
        $siteA = Site::factory()->create(['organization_id' => $this->org->id]);
        $siteB = Site::factory()->create(['organization_id' => $this->org->id]);
        $lecteurA = $this->lecteurAgence($siteA);

        $personne = Personne::factory()->create(['organization_id' => $this->org->id]);
        $client = Client::create(['organization_id' => $this->org->id, 'personne_id' => $personne->id, 'nom_complet' => 'X', 'telephone' => $personne->telephone, 'type' => ClientType::EXTERNE->value]);
        $parrain = $this->parrain($personne);
        Vehicule::factory()->create(['organization_id' => $this->org->id, 'site_id' => $siteB->id, 'parrain_id' => $parrain->id]);

        $vuParA = $this->service->pourPersonne($personne, $lecteurA);
        $this->assertSame(['client:'.$client->id], $this->fonctions($vuParA));
        $this->assertNotContains($parrain->id, collect($vuParA)->pluck('id')->all());

        $this->assertContains('parrain:'.$parrain->id, $this->fonctions($this->service->pourPersonne($personne, $this->lecteurAgence($siteB))));
        $this->assertContains('parrain:'.$parrain->id, $this->fonctions($this->service->pourPersonne($personne, $this->lecteurToutesAgences())));
    }

    public function test_un_parrain_sans_vehicule_ni_fiche_dans_l_agence_reste_masque(): void
    {
        $siteA = Site::factory()->create(['organization_id' => $this->org->id]);
        $personne = Personne::factory()->create(['organization_id' => $this->org->id]);
        $this->parrain($personne);

        $this->assertSame([], $this->service->pourPersonne($personne, $this->lecteurAgence($siteA)));
    }

    public function test_les_fonctions_supprimees_ne_sont_pas_listees_et_les_ids_ne_changent_pas(): void
    {
        $personne = Personne::factory()->create(['organization_id' => $this->org->id]);
        $fournisseur = Fournisseur::create(['organization_id' => $this->org->id, 'personne_id' => $personne->id]);
        $supprime = Prestataire::create(['organization_id' => $this->org->id, 'personne_id' => $personne->id, 'type' => PrestataireType::MACHINISTE->value]);
        $supprime->delete();

        $resultat = $this->service->pourPersonne($personne, $this->lecteurToutesAgences());

        $this->assertSame(['fournisseur:'.$fournisseur->id], $this->fonctions($resultat));
        $this->assertSame($fournisseur->id, $fournisseur->fresh()->id);
    }
}
