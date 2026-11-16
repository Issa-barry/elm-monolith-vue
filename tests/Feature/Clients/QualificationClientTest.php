<?php

namespace Tests\Feature\Clients;

use App\Enums\StatutCommandeVente;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\Organization;
use App\Models\Parametre;
use App\Models\Produit;
use App\Models\Site;
use App\Models\VarianteStock;
use App\Rules\ClientUtilisableEnVente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Qualification commerciale d'un client (ADR 0066) : la fiche créée à l'inscription d'un livreur ou
 * d'un propriétaire n'est jamais utilisable dans une vente tant qu'un utilisateur `clients.qualifier`
 * n'a pas choisi sa nature ; une fois qualifiée, la tarification de sa nature s'applique normalement.
 * Origine : VTE-151126-080, facturée au tarif externe à cause d'une telle fiche.
 */
class QualificationClientTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initOrgAndUser([
            'clients.read', 'clients.update', 'clients.qualifier',
            'ventes.read', 'ventes.create', 'ventes.update', 'pdv.read', 'pdv.create',
        ]);
        Parametre::setVentesAutoriserStockNegatif($this->org->id, true);
        $this->site = Site::where('organization_id', $this->org->id)->firstOrFail();
    }

    private function nonQualifie(array $attributs = []): Client
    {
        return Client::factory()->nonQualifie()->create(['organization_id' => $this->org->id, ...$attributs]);
    }

    private function fabricable(): Produit
    {
        return $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack de 25 sachets', 'type' => 'fabricable'],
            ['prix_vente' => 5500, 'prix_usine' => 4800, 'prix_externe' => 5150, 'prix_revendeur' => 5300],
        );
    }

    // ── Création et qualification ────────────────────────────────────────────

    public function test_un_client_cree_au_back_office_est_qualifie_par_son_createur(): void
    {
        $this->actingAs($this->user);
        $client = Client::factory()->create(['organization_id' => $this->org->id]);

        $this->assertTrue($client->estQualifie());
        $this->assertSame($this->user->id, $client->qualifie_par);
    }

    public function test_qualifier_enregistre_nature_date_et_auteur_ensemble(): void
    {
        $client = $this->nonQualifie();

        $this->actingAs($this->user)
            ->post(route('clients.qualification', $client), ['type' => 'distributeur'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $client->refresh();
        $this->assertSame('distributeur', $client->type->value);
        $this->assertNotNull($client->qualifie_le);
        $this->assertSame($this->user->id, $client->qualifie_par);
    }

    public function test_qualifier_en_revendeur_applique_le_cashback_obligatoire(): void
    {
        $client = $this->nonQualifie();

        // Sans montant : refusé, rien n'est enregistré (jamais un client à moitié qualifié).
        $this->actingAs($this->user)
            ->post(route('clients.qualification', $client), ['type' => 'revendeur'])
            ->assertSessionHasErrors('cashback_montant_par_pack');
        $client->refresh();
        $this->assertFalse($client->estQualifie());
        $this->assertSame('externe', $client->type->value);

        // Désactivation explicite refusée (CASHBACK-001).
        $this->actingAs($this->user)
            ->post(route('clients.qualification', $client), ['type' => 'revendeur', 'cashback_eligible' => false, 'cashback_montant_par_pack' => 300])
            ->assertSessionHasErrors('cashback_eligible');
        $this->assertFalse($client->fresh()->estQualifie());

        $this->actingAs($this->user)
            ->post(route('clients.qualification', $client), ['type' => 'revendeur', 'cashback_montant_par_pack' => 300])
            ->assertSessionHasNoErrors();
        $client->refresh();
        $this->assertTrue($client->estQualifie());
        $this->assertTrue($client->cashback_eligible);
        $this->assertSame(300, $client->cashback_montant_par_pack);
    }

    public function test_qualifier_exige_la_permission_dediee_meme_avec_la_modification(): void
    {
        $client = $this->nonQualifie();
        $sansQualifier = $this->makeUserWithPermissions($this->org, ['clients.read', 'clients.update']);
        $sansQualifier->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($sansQualifier)
            ->post(route('clients.qualification', $client), ['type' => 'externe'])
            ->assertForbidden();
        $this->assertFalse($client->fresh()->estQualifie());
    }

    public function test_qualifier_un_client_d_une_autre_organisation_est_refuse(): void
    {
        $autre = Client::factory()->nonQualifie()->create(['organization_id' => Organization::factory()->create()->id]);

        $this->actingAs($this->user)
            ->post(route('clients.qualification', $autre), ['type' => 'externe'])
            ->assertForbidden();
    }

    public function test_un_client_deja_qualifie_ne_peut_pas_etre_requalifie(): void
    {
        $client = Client::factory()->create(['organization_id' => $this->org->id, 'type' => 'distributeur', 'cashback_eligible' => false, 'cashback_montant_par_pack' => null]);

        $this->actingAs($this->user)
            ->post(route('clients.qualification', $client), ['type' => 'externe'])
            ->assertSessionHasErrors('type');
        $this->assertSame('distributeur', $client->fresh()->type->value);
    }

    public function test_la_modification_ne_change_pas_la_nature_d_un_client_non_qualifie(): void
    {
        $client = $this->nonQualifie(['nom_complet' => 'Yaya CAMARA', 'telephone' => '+224620000301']);

        $this->actingAs($this->user)
            ->put(route('clients.update', $client), [
                'nom_complet' => 'Yaya CAMARA',
                'telephone' => '620000301',
                'code_pays' => 'GN',
                'is_active' => true,
                'type' => 'revendeur',
                'cashback_eligible' => true,
                'cashback_montant_par_pack' => 300,
            ])
            ->assertSessionHasErrors('type');

        $client->refresh();
        $this->assertSame('externe', $client->type->value);
        $this->assertFalse($client->estQualifie());
    }

    /** @return array<string, mixed> */
    private function saisieFiche(Client $client, string $type, array $autres = []): array
    {
        return [
            'nom_complet' => 'Mamadou BAH',
            'telephone' => substr($client->telephone, 4),
            'code_pays' => 'GN',
            'is_active' => true,
            'type' => $type,
            'cashback_eligible' => false,
            'cashback_montant_par_pack' => null,
            ...$autres,
        ];
    }

    private function clientExterneQualifie(): Client
    {
        return Client::factory()->create([
            'organization_id' => $this->org->id,
            'type' => 'externe',
            'cashback_eligible' => false,
            'cashback_montant_par_pack' => null,
            'qualifie_le' => '2026-10-01 08:00:00',
        ]);
    }

    public function test_changer_la_nature_d_un_client_qualifie_exige_la_permission_dediee(): void
    {
        $client = $this->clientExterneQualifie();
        $sansQualifier = $this->makeUserWithPermissions($this->org, ['clients.read', 'clients.update']);
        $sansQualifier->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($sansQualifier)
            ->put(route('clients.update', $client), $this->saisieFiche($client, 'distributeur'))
            ->assertForbidden();
        $this->assertSame('externe', $client->fresh()->type->value);

        // Les coordonnées restent modifiables avec la seule permission de modification.
        $this->actingAs($sansQualifier)
            ->put(route('clients.update', $client), $this->saisieFiche($client, 'externe', ['ville' => 'Kindia']))
            ->assertSessionHasNoErrors()
            ->assertRedirect();
        $client->refresh();
        $this->assertSame('Kindia', $client->ville);
        $this->assertSame('2026-10-01 08:00:00', $client->qualifie_le->format('Y-m-d H:i:s'));
    }

    public function test_changer_la_nature_avec_la_permission_enregistre_la_requalification(): void
    {
        $client = $this->clientExterneQualifie();

        $this->actingAs($this->user)
            ->put(route('clients.update', $client), $this->saisieFiche($client, 'revendeur', ['cashback_eligible' => true, 'cashback_montant_par_pack' => 200]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $client->refresh();
        $this->assertSame('revendeur', $client->type->value);
        $this->assertTrue($client->cashback_eligible);
        $this->assertSame($this->user->id, $client->qualifie_par);
        $this->assertTrue($client->qualifie_le->isToday());
    }

    public function test_fiche_et_liste_signalent_un_client_a_qualifier(): void
    {
        $client = $this->nonQualifie();

        $this->actingAs($this->user)->get(route('clients.show', $client))
            ->assertInertia(fn (Assert $page) => $page->where('client.qualifie', false));

        $this->actingAs($this->user)->get(route('clients.index'))
            ->assertInertia(fn (Assert $page) => $page->where('clients.0.qualifie', false));
    }

    // ── Ventes ───────────────────────────────────────────────────────────────

    public function test_un_client_non_qualifie_n_est_pas_propose_dans_les_ventes(): void
    {
        $nonQualifie = $this->nonQualifie();
        $qualifie = Client::factory()->create(['organization_id' => $this->org->id]);

        $this->actingAs($this->user)->get(route('ventes.create'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('clients', 1)
                ->where('clients.0.id', $qualifie->id));

        $this->actingAs($this->user)->get('/backoffice/pdv')
            ->assertInertia(fn (Assert $page) => $page
                ->where('clients', fn ($clients) => collect($clients)->pluck('id')->all() === [$qualifie->id]));

        $this->assertNotSame($nonQualifie->id, $qualifie->id);
    }

    public function test_creer_une_vente_avec_un_client_non_qualifie_est_refuse(): void
    {
        $produit = $this->fabricable();
        $client = $this->nonQualifie();

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'client_id' => $client->id,
                'lignes' => [['produit_id' => $produit->id, 'qte' => 100, 'prix_vente' => 5500]],
            ])
            ->assertSessionHasErrors(['client_id' => ClientUtilisableEnVente::MESSAGE_NON_QUALIFIE]);

        $this->assertSame(0, CommandeVente::count());
    }

    public function test_pdv_avec_un_client_non_qualifie_est_refuse(): void
    {
        $produit = $this->fabricable();
        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $produit->variantePrincipale()->first()->id,
            'site_id' => $this->site->id,
            'qte_stock' => 100,
        ]);
        $client = $this->nonQualifie();

        $this->actingAs($this->user)->post('/backoffice/pdv/checkout', [
            'mode' => 'Vente rapide',
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ])->assertSessionHasErrors('client_id');

        $this->assertSame(0, CommandeVente::count());
    }

    public function test_un_client_d_une_autre_organisation_est_refuse_meme_qualifie(): void
    {
        $produit = $this->fabricable();
        $etranger = Client::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        $this->actingAs($this->user)
            ->post(route('ventes.store'), [
                'client_id' => $etranger->id,
                'lignes' => [['produit_id' => $produit->id, 'qte' => 1, 'prix_vente' => 5500]],
            ])
            ->assertSessionHasErrors(['client_id' => 'Le client sélectionné est introuvable.']);

        $this->actingAs($this->user)->post('/backoffice/pdv/checkout', [
            'mode' => 'Vente rapide',
            'client_id' => $etranger->id,
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 1]],
        ])->assertSessionHasErrors('client_id');

        $this->assertSame(0, CommandeVente::count());
    }

    public function test_une_commande_existante_reste_modifiable_tant_que_son_client_n_est_pas_remplace(): void
    {
        $produit = $this->fabricable();
        $client = Client::factory()->create(['organization_id' => $this->org->id, 'type' => 'externe', 'cashback_eligible' => false, 'cashback_montant_par_pack' => null]);

        $this->actingAs($this->user)->post(route('ventes.store'), [
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'qte' => 2, 'prix_vente' => 5500]],
        ])->assertSessionHasNoErrors();
        $commande = CommandeVente::where('client_id', $client->id)->firstOrFail();
        $commande->update(['statut' => StatutCommandeVente::BROUILLON]);
        // État des fiches créées à l'inscription avant l'ADR 0066 : client déjà utilisé, non qualifié.
        $client->forceFill(['qualifie_le' => null, 'qualifie_par' => null])->save();

        $this->actingAs($this->user)->get(route('ventes.edit', $commande))
            ->assertInertia(fn (Assert $page) => $page->where('clients.0.id', $client->id));

        $this->actingAs($this->user)->put(route('ventes.update', $commande), [
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'qte' => 3, 'prix_vente' => 5500]],
        ])->assertSessionHasNoErrors();
        $this->assertSame(3, (int) $commande->fresh()->lignes->first()->quantite_demandee);

        $autreNonQualifie = $this->nonQualifie();
        $this->actingAs($this->user)->put(route('ventes.update', $commande), [
            'client_id' => $autreNonQualifie->id,
            'lignes' => [['produit_id' => $produit->id, 'qte' => 3, 'prix_vente' => 5500]],
        ])->assertSessionHasErrors('client_id');
        $this->assertSame($client->id, $commande->fresh()->client_id);
    }

    public function test_une_fois_qualifie_le_client_est_tarife_selon_sa_nature(): void
    {
        $produit = $this->fabricable();
        $client = $this->nonQualifie();

        $this->actingAs($this->user)
            ->post(route('clients.qualification', $client), ['type' => 'revendeur', 'cashback_montant_par_pack' => 100])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->user)->post(route('ventes.store'), [
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'qte' => 10, 'prix_vente' => 5500]],
        ])->assertSessionHasNoErrors();

        $ligne = CommandeVente::where('client_id', $client->id)->firstOrFail()->lignes->first();
        $this->assertEquals(5300.0, (float) $ligne->prix_vente_snapshot);
        $this->assertSame('revendeur', $ligne->prix_origine_snapshot->value);
    }

    public function test_sans_client_la_vente_reste_au_prix_de_vente(): void
    {
        $produit = $this->fabricable();

        $this->actingAs($this->user)->post('/backoffice/pdv/checkout', [
            'mode' => 'Vente rapide',
            'lignes' => [['produit_id' => $produit->id, 'quantite' => 2]],
        ])->assertSessionHasNoErrors();

        $ligne = CommandeVente::latest()->firstOrFail()->lignes->first();
        $this->assertEquals(5500.0, (float) $ligne->prix_vente_snapshot);
        $this->assertSame('vente', $ligne->prix_origine_snapshot->value);
    }
}
