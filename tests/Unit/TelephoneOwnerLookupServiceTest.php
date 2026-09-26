<?php

namespace Tests\Unit;

use App\Enums\TypeProprietaire;
use App\Models\Client;
use App\Models\EntrepriseTierce;
use App\Models\Fournisseur;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Proprietaire;
use App\Services\TelephoneOwnerLookupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Couvre TelephoneOwnerLookupService::find() — recherche cross-entité (Client, Fournisseur,
 * Proprietaire...) utilisée pour enrichir les messages de doublon téléphone avec le nom et le
 * type du propriétaire réel. Cf. docblock du service : ne remplace aucun contrôle d'unicité
 * existant, purement informatif hors Client.
 */
class TelephoneOwnerLookupServiceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
    }

    public function test_trouve_un_client_par_telephone(): void
    {
        Client::factory()->create([
            'organization_id' => $this->org->id,
            'nom_complet' => 'Amadou Diallo',
            'telephone' => '+224622000001',
        ]);

        $resultat = TelephoneOwnerLookupService::find($this->org->id, '+224622000001');

        $this->assertSame('client', $resultat['type']);
        $this->assertSame('Amadou Diallo', $resultat['nom']);
    }

    public function test_exclut_le_client_donne(): void
    {
        $client = Client::factory()->create([
            'organization_id' => $this->org->id,
            'telephone' => '+224622000001',
        ]);

        $resultat = TelephoneOwnerLookupService::find($this->org->id, '+224622000001', $client->id);

        $this->assertNull($resultat);
    }

    public function test_trouve_un_fournisseur_societe_par_telephone(): void
    {
        $entreprise = EntrepriseTierce::resoudreOuCreer($this->org->id, [
            'raison_sociale' => 'Société Baldé SARL',
            'telephone' => '+224622000002',
        ]);
        Fournisseur::create([
            'organization_id' => $this->org->id,
            'entreprise_tierce_id' => $entreprise->id,
            'is_active' => true,
        ]);

        $resultat = TelephoneOwnerLookupService::find($this->org->id, '+224622000002');

        $this->assertSame('fournisseur', $resultat['type']);
        $this->assertSame('Société Baldé SARL', $resultat['nom']);
    }

    public function test_trouve_un_fournisseur_personne_physique_par_telephone(): void
    {
        $personne = Personne::resoudreOuCreer($this->org->id, [
            'nom' => 'BARRY',
            'prenom' => 'Ousmane',
            'telephone' => '+224622000003',
        ]);
        Fournisseur::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'is_active' => true,
        ]);

        $resultat = TelephoneOwnerLookupService::find($this->org->id, '+224622000003');

        $this->assertSame('fournisseur', $resultat['type']);
        $this->assertSame('Ousmane BARRY', $resultat['nom']);
    }

    public function test_trouve_un_proprietaire_par_telephone(): void
    {
        Proprietaire::factory()->create([
            'organization_id' => $this->org->id,
            'type' => TypeProprietaire::PERSONNE_PHYSIQUE,
            'nom' => 'SOW',
            'prenom' => 'Mariama',
            'telephone' => '+224622000004',
        ]);

        $resultat = TelephoneOwnerLookupService::find($this->org->id, '+224622000004');

        $this->assertSame('proprietaire', $resultat['type']);
        $this->assertSame('Mariama SOW', $resultat['nom']);
    }

    public function test_retourne_null_si_aucun_tiers_ne_possede_ce_numero(): void
    {
        $resultat = TelephoneOwnerLookupService::find($this->org->id, '+224699999999');

        $this->assertNull($resultat);
    }

    public function test_scope_par_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        Client::factory()->create([
            'organization_id' => $autreOrg->id,
            'telephone' => '+224622000005',
        ]);

        $resultat = TelephoneOwnerLookupService::find($this->org->id, '+224622000005');

        $this->assertNull($resultat);
    }
}
