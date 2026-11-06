<?php

namespace Tests\Feature\Api\Mobile;

use App\Enums\StatutTransfert;
use App\Models\Categorie;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\Site;
use App\Models\TransfertLigne;
use App\Models\TransfertLogistique;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\HasProduitVariante;
use Tests\TestCase;

/**
 * Routes du livreur : le numéro de téléphone seul ne donne jamais accès à une fiche rattachée à un
 * autre compte (ADR 0059). Le repli par téléphone reste possible sur une fiche non réclamée de
 * l'organisation du compte : un livreur dont la fiche n'est pas encore reliée garde son parcours.
 */
class LogistiqueIdentiteLivreurTest extends TestCase
{
    use HasProduitVariante, RefreshDatabase;

    private const TELEPHONE = '+224620000777';

    private Organization $org;

    private Site $source;

    private Site $destination;

    private Vehicule $vehicule;

    private EquipeLivraison $equipe;

    private Produit $produit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->source = Site::create(['organization_id' => $this->org->id, 'nom' => 'Source', 'type' => 'depot', 'localisation' => 'Conakry']);
        $this->destination = Site::create(['organization_id' => $this->org->id, 'nom' => 'Destination', 'type' => 'depot', 'localisation' => 'Kindia']);
        $this->vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'livraison_logistique' => true,
            'is_active' => true,
            'capacite_packs' => 500,
        ]);
        $this->equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $this->vehicule->id,
            'nom' => 'Équipe',
            'is_active' => true,
        ]);
        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Eau']);
        $this->produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Eau', 'categorie_id' => $categorie->id], ['prix_vente' => 5000]);
        $this->seedVarianteStockSuffisant($this->produit->variantePrincipale()->first(), $this->source);
    }

    /** @return array<string, array{0: StatutTransfert, 1: string, 2: string, 3: StatutTransfert}> */
    public static function ecrituresProvider(): array
    {
        return [
            'démarrer le chargement' => [StatutTransfert::BROUILLON, 'POST', 'demarrer-chargement', StatutTransfert::CHARGEMENT],
            'saisir les quantités' => [StatutTransfert::CHARGEMENT, 'PUT', 'quantites-chargees', StatutTransfert::CHARGEMENT],
            'confirmer le départ' => [StatutTransfert::CHARGEMENT, 'POST', 'confirmer-depart', StatutTransfert::TRANSIT],
        ];
    }

    #[DataProvider('ecrituresProvider')]
    public function test_le_livreur_relie_a_son_compte_ecrit(StatutTransfert $depart, string $methode, string $route, StatutTransfert $arrivee): void
    {
        $compte = $this->compte($this->org->id);
        $this->membre($this->fiche(self::TELEPHONE, $compte->id));
        $transfert = $this->transfert($depart);

        $this->ecrire($compte, $methode, $route, $transfert)->assertOk();

        $this->assertSame($arrivee, $transfert->fresh()->statut);
    }

    #[DataProvider('ecrituresProvider')]
    public function test_une_fiche_non_reclamee_de_l_organisation_garde_son_parcours(StatutTransfert $depart, string $methode, string $route, StatutTransfert $arrivee): void
    {
        $compte = $this->compte($this->org->id, self::TELEPHONE);
        $this->membre($this->fiche(self::TELEPHONE, null));
        $transfert = $this->transfert($depart);

        $this->ecrire($compte, $methode, $route, $transfert)->assertOk();

        $this->assertSame($arrivee, $transfert->fresh()->statut);
    }

    #[DataProvider('ecrituresProvider')]
    public function test_le_telephone_ne_donne_pas_acces_a_la_fiche_d_un_autre_compte(StatutTransfert $depart, string $methode, string $route): void
    {
        $titulaire = $this->compte($this->org->id);
        $this->membre($this->fiche(self::TELEPHONE, $titulaire->id));
        $transfert = $this->transfert($depart);
        $avant = $this->etat($transfert);

        $this->ecrire($this->compte($this->org->id, self::TELEPHONE), $methode, $route, $transfert)
            ->assertForbidden()
            ->assertExactJson(['message' => 'Accès non autorisé.']);

        $this->assertSame($avant, $this->etat($transfert));
    }

    #[DataProvider('ecrituresProvider')]
    public function test_un_compte_sans_organisation_n_obtient_rien_par_le_telephone(StatutTransfert $depart, string $methode, string $route): void
    {
        $this->membre($this->fiche(self::TELEPHONE, null));
        $transfert = $this->transfert($depart);
        $avant = $this->etat($transfert);

        $this->ecrire($this->compte(null, self::TELEPHONE), $methode, $route, $transfert)->assertForbidden();

        $this->assertSame($avant, $this->etat($transfert));
    }

    #[DataProvider('ecrituresProvider')]
    public function test_un_livreur_hors_de_l_equipe_est_refuse(StatutTransfert $depart, string $methode, string $route): void
    {
        $compte = $this->compte($this->org->id);
        $this->fiche(self::TELEPHONE, $compte->id);
        $transfert = $this->transfert($depart);
        $avant = $this->etat($transfert);

        $this->ecrire($compte, $methode, $route, $transfert)->assertForbidden();

        $this->assertSame($avant, $this->etat($transfert));
    }

    public function test_un_transfert_d_une_autre_organisation_est_refuse(): void
    {
        $compte = $this->compte($this->org->id);
        $this->membre($this->fiche(self::TELEPHONE, $compte->id));
        $transfert = $this->transfert(StatutTransfert::BROUILLON);
        $autre = Organization::factory()->create();
        TransfertLogistique::whereKey($transfert->id)->update(['organization_id' => $autre->id]);

        $this->ecrire($compte, 'POST', 'demarrer-chargement', $transfert)->assertForbidden();

        $this->assertSame(StatutTransfert::BROUILLON, $transfert->fresh()->statut);
    }

    public function test_les_lectures_suivent_la_meme_regle(): void
    {
        $titulaire = $this->compte($this->org->id);
        $this->membre($this->fiche(self::TELEPHONE, $titulaire->id));
        $transfert = $this->transfert(StatutTransfert::CHARGEMENT);
        $usurpateur = $this->compte($this->org->id, self::TELEPHONE);

        Sanctum::actingAs($usurpateur);
        $this->getJson(route('client.logistique.index'))->assertOk()->assertExactJson([]);
        $this->getJson(route('client.logistique.show', $transfert))->assertForbidden();

        Sanctum::actingAs($titulaire);
        $this->getJson(route('client.logistique.index'))->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $transfert->id);
        $this->getJson(route('client.logistique.show', $transfert))->assertOk()->assertJsonPath('id', $transfert->id);
    }

    public function test_un_livreur_relie_sans_organisation_sur_son_compte_garde_ses_livraisons(): void
    {
        $compte = $this->compte(null);
        $this->membre($this->fiche(self::TELEPHONE, $compte->id));
        $transfert = $this->transfert(StatutTransfert::BROUILLON);

        $this->ecrire($compte, 'POST', 'demarrer-chargement', $transfert)->assertOk();

        $this->assertSame(StatutTransfert::CHARGEMENT, $transfert->fresh()->statut);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function ecrire(User $compte, string $methode, string $route, TransfertLogistique $transfert): TestResponse
    {
        Sanctum::actingAs($compte);
        $ligne = $transfert->lignes()->firstOrFail();

        return $this->json($methode, route('client.logistique.'.$route, $transfert), $route === 'quantites-chargees'
            ? ['lignes' => [['id' => $ligne->id, 'quantite_chargee' => 7]]]
            : []);
    }

    private function compte(?string $organisationId, string $telephone = ''): User
    {
        return User::factory()->create([
            'organization_id' => $organisationId,
            'telephone' => $telephone !== '' ? $telephone : '+224'.fake()->unique()->numerify('6########'),
        ])->fresh();
    }

    /** La fiche est posée directement : RattachementAutomatique relierait sinon la même personne. */
    private function fiche(string $telephone, ?string $userId): Livreur
    {
        $livreur = Livreur::factory()->create([
            'organization_id' => $this->org->id,
            'telephone' => $telephone,
        ]);
        Livreur::whereKey($livreur->id)->update(['user_id' => $userId]);

        return $livreur->fresh();
    }

    private function membre(Livreur $livreur): void
    {
        EquipeLivreur::create(['equipe_id' => $this->equipe->id, 'livreur_id' => $livreur->id, 'role' => 'chauffeur', 'ordre' => 0]);
    }

    private function transfert(StatutTransfert $statut): TransfertLogistique
    {
        $transfert = TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $this->source->id,
            'site_destination_id' => $this->destination->id,
            'vehicule_id' => $this->vehicule->id,
            'equipe_livraison_id' => $this->equipe->id,
            'statut' => $statut,
            'created_by' => User::factory()->create(['organization_id' => $this->org->id])->id,
        ]);

        TransfertLigne::create([
            'transfert_logistique_id' => $transfert->id,
            'variante_id' => $this->produit->variantePrincipale()->first()->id,
            'quantite_demandee' => 10,
            'quantite_chargee' => 10,
        ]);

        return $transfert;
    }

    /** @return array{0: string, 1: int|null} */
    private function etat(TransfertLogistique $transfert): array
    {
        $frais = $transfert->fresh();

        return [$frais->statut->value, $frais->lignes()->firstOrFail()->quantite_chargee];
    }
}
