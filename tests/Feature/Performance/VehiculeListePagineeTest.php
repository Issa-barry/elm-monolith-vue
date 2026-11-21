<?php

namespace Tests\Feature\Performance;

use App\Enums\CommissionMode;
use App\Enums\CommissionRegleStatut;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionUniteCalcul;
use App\Http\Controllers\VehiculeController;
use App\Models\Categorie;
use App\Models\CommissionCibleType;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TypeVehicule;
use App\Models\User;
use App\Models\Vehicule;
use App\Models\VehiculeCapacite;
use App\Services\Commission\CommissionProcessusDefaults;
use Closure;
use Collator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Liste des véhicules paginée côté serveur (lot 6 performance) : 20 lignes par page, filtres, tri
 * et compteurs sur tous les véhicules, mêmes valeurs qu'avant la pagination.
 */
class VehiculeListePagineeTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private TypeVehicule $type;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['vehicules.read']);
        $this->type = TypeVehicule::factory()->create(['organization_id' => $this->org->id, 'nom' => 'Type liste']);
    }

    private function vehicule(string $nom, array $attributs = []): Vehicule
    {
        return Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'nom_vehicule' => $nom,
            'type_vehicule_id' => $this->type->id,
            'proprietaire_id' => Proprietaire::factory()->create(['organization_id' => $this->org->id])->id,
            ...$attributs,
        ]);
    }

    private function liste(array $params = []): TestResponse
    {
        return $this->actingAs($this->user)->get(route('vehicules.index', $params))->assertOk();
    }

    /** @return list<string> */
    private function noms(TestResponse $reponse): array
    {
        return collect($reponse->inertiaProps('vehicules'))->pluck('nom_vehicule')->all();
    }

    public function test_vingt_vehicules_par_page_sur_tous_les_resultats(): void
    {
        foreach (range(1, 25) as $i) {
            $this->vehicule(sprintf('V %02d', $i));
        }

        $page1 = $this->liste();
        $page2 = $this->liste(['page' => 2]);

        $this->assertSame(array_map(fn ($i) => sprintf('V %02d', $i), range(1, 20)), $this->noms($page1));
        $this->assertSame(array_map(fn ($i) => sprintf('V %02d', $i), range(21, 25)), $this->noms($page2));
        $this->assertSame(['page' => 1, 'par_page' => 20, 'total' => 25, 'derniere_page' => 2, 'tri' => null, 'sens' => null], $page1->inertiaProps('pagination'));
        $this->assertSame([], $this->liste(['page' => 3])->inertiaProps('vehicules'));
    }

    public function test_les_compteurs_portent_sur_tous_les_vehicules_quels_que_soient_la_page_et_les_filtres(): void
    {
        foreach (range(1, 22) as $i) {
            $this->vehicule(sprintf('Actif %02d', $i));
        }
        foreach (range(1, 3) as $i) {
            $this->vehicule("Inactif {$i}", ['is_active' => false]);
        }

        $reponse = $this->liste(['statut' => 'inactif']);

        $this->assertSame(['Inactif 1', 'Inactif 2', 'Inactif 3'], $this->noms($reponse));
        $this->assertSame(3, $reponse->inertiaProps('pagination.total'));
        $this->assertSame(25, $reponse->inertiaProps('vehicule_stats.total'));
        $this->assertSame(22, $reponse->inertiaProps('vehicule_stats.actifs'));
        $this->assertSame(3, $reponse->inertiaProps('vehicule_stats.inactifs'));
        $this->assertSame([['value' => $this->type->id, 'label' => 'Type liste']], $reponse->inertiaProps('types_options'));
    }

    public function test_le_tri_reproduit_celui_du_tableau_dans_le_navigateur(): void
    {
        if (! class_exists(Collator::class)) {
            $this->markTestSkipped('Extension intl absente : ordre de repli, non comparable au navigateur.');
        }
        foreach (['camion 10', 'Camion 2', 'Éco', 'eco', 'Zèbre', 'Ane'] as $nom) {
            $this->vehicule($nom);
        }

        // Ordre d'Intl.Collator (numeric: true), celui du tableau PrimeVue avant la pagination.
        $this->assertSame(['Ane', 'Camion 2', 'camion 10', 'eco', 'Éco', 'Zèbre'], $this->noms($this->liste(['tri' => 'nom_vehicule', 'sens' => 'asc'])));
        $this->assertSame(['Zèbre', 'Éco', 'eco', 'camion 10', 'Camion 2', 'Ane'], $this->noms($this->liste(['tri' => 'nom_vehicule', 'sens' => 'desc'])));
    }

    public function test_un_type_vide_reste_en_dernier_et_les_egalites_gardent_lordre_par_nom(): void
    {
        $autre = TypeVehicule::factory()->create(['organization_id' => $this->org->id, 'nom' => 'Benne']);
        $this->vehicule('C', ['type_vehicule_id' => null]);
        $this->vehicule('B');
        $this->vehicule('A');
        $this->vehicule('D', ['type_vehicule_id' => $autre->id]);

        $this->assertSame(['D', 'A', 'B', 'C'], $this->noms($this->liste(['tri' => 'type_label', 'sens' => 'asc'])));
        $this->assertSame(['A', 'B', 'D', 'C'], $this->noms($this->liste(['tri' => 'type_label', 'sens' => 'desc'])));
    }

    public function test_le_tri_par_statut_et_par_usage(): void
    {
        $this->vehicule('A', ['is_active' => false]);
        $this->vehicule('B');
        $this->vehicule('C', ['is_active' => false, 'livraison_vente' => false, 'livraison_logistique' => true]);

        $this->assertSame(['A', 'C', 'B'], $this->noms($this->liste(['tri' => 'is_active', 'sens' => 'asc'])));
        $this->assertSame(['B', 'A', 'C'], $this->noms($this->liste(['tri' => 'is_active', 'sens' => 'desc'])));
        $this->assertSame(['C', 'A', 'B'], $this->noms($this->liste(['tri' => 'usage_label', 'sens' => 'asc'])));
    }

    public function test_le_filtre_partages_porte_sur_tous_les_vehicules_et_le_statut_affiche_est_le_meme(): void
    {
        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Bouteille', 'statut' => 'actif']);
        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_VENTE)->id,
            'libelle' => 'Livreur',
            'scope_type' => CommissionScopeType::CATEGORIE->value,
            'scope_id' => $categorie->id,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'mode' => CommissionMode::A_REPARTIR->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 800,
            'effective_from' => now()->subMonth()->toDateString(),
            'statut' => CommissionRegleStatut::ACTIVE->value,
        ]);
        // Vente sans équipe : partage à faire. Logistique seule sans barème : rien à faire.
        foreach (range(1, 21) as $i) {
            $this->vehicule(sprintf('Vente %02d', $i));
        }
        foreach (range(1, 4) as $i) {
            $this->vehicule("Logistique {$i}", ['livraison_vente' => false, 'livraison_logistique' => true]);
        }

        $aFaire = $this->liste(['partage' => 'a_faire', 'page' => 2]);
        $fait = $this->liste(['partage' => 'fait']);
        $sansFiltre = [...$this->liste()->inertiaProps('vehicules'), ...$this->liste(['page' => 2])->inertiaProps('vehicules')];

        $this->assertSame(21, $aFaire->inertiaProps('pagination.total'));
        $this->assertSame(['Vente 21'], $this->noms($aFaire));
        $this->assertSame('sans_equipe', $aFaire->inertiaProps('vehicules.0.partages_commission.vente'));
        $this->assertSame(4, $fait->inertiaProps('pagination.total'));
        // Sans filtre, statuts calculés pour la seule page : mêmes valeurs.
        $parId = collect($sansFiltre)->keyBy('id');
        $this->assertCount(25, $parId);
        foreach ([...$this->liste(['partage' => 'a_faire'])->inertiaProps('vehicules'), ...$fait->inertiaProps('vehicules')] as $ligne) {
            $this->assertSame($ligne['partages_commission'], $parId[$ligne['id']]['partages_commission']);
        }
    }

    public function test_les_champs_de_filtre_ont_les_memes_valeurs_que_la_ligne_affichee(): void
    {
        $site = Site::factory()->create(['organization_id' => $this->org->id, 'nom' => 'Matoto']);
        $siteDefaut = Site::factory()->create(['organization_id' => $this->org->id, 'nom' => 'Kaloum']);
        $compte = User::factory()->create(['organization_id' => $this->org->id]);
        $compte->sites()->attach($site->id, ['role' => 'employe', 'is_default' => false]);
        $compte->sites()->attach($siteDefaut->id, ['role' => 'employe', 'is_default' => true]);
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id, 'user_id' => $compte->id]);
        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Pack', 'statut' => 'actif']);

        $complet = $this->vehicule('Complet', ['proprietaire_id' => $proprietaire->id, 'livraison_logistique' => true]);
        VehiculeCapacite::create(['organization_id' => $this->org->id, 'vehicule_id' => $complet->id, 'categorie_id' => $categorie->id, 'capacite_max' => 90]);
        $equipe = EquipeLivraison::create(['organization_id' => $this->org->id, 'vehicule_id' => $complet->id, 'is_active' => true]);
        EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => Livreur::factory()->create(['organization_id' => $this->org->id])->id, 'role' => 'chauffeur', 'ordre' => 0]);
        $this->vehicule('Sans type', ['type_vehicule_id' => null, 'is_active' => false, 'livraison_vente' => false]);

        $controleur = app(VehiculeController::class);
        $ligneListe = Closure::bind(fn (Vehicule $v) => $this->ligneListe($v), $controleur, VehiculeController::class);
        $vehiculeData = Closure::bind(fn (Vehicule $v) => $this->vehiculeData($v), $controleur, VehiculeController::class);

        $vehicules = Vehicule::with(['typeVehicule', 'site', 'proprietaire.personne', 'proprietaire.user.sites', 'parrain.personne', 'equipe.membres.livreur.personne', 'capacites.categorie'])
            ->where('organization_id', $this->org->id)->get();
        $this->assertCount(2, $vehicules);
        foreach ($vehicules as $v) {
            $ligne = $ligneListe($v);
            $complete = $vehiculeData($v);
            foreach ($ligne as $cle => $valeur) {
                $attendu = $cle === 'capacites'
                    ? array_map(fn ($c) => ['capacite_max' => $c['capacite_max']], $complete['capacites'])
                    : $complete[$cle];
                $this->assertSame($attendu, $valeur, "{$v->nom_vehicule} : {$cle}");
            }
        }
        $this->assertSame('Kaloum', $ligneListe($vehicules->firstWhere('nom_vehicule', 'Complet'))['agence_nom']);
        $this->assertSame([['capacite_max' => 90]], $ligneListe($vehicules->firstWhere('nom_vehicule', 'Complet'))['capacites']);
    }

    public function test_le_nombre_de_requetes_ne_depend_pas_du_nombre_de_vehicules(): void
    {
        $compter = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->liste();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        foreach (range(1, 21) as $i) {
            $this->vehicule(sprintf('V %02d', $i));
        }
        $compter(); // échauffement (caches de permissions)
        $avec21 = $compter();
        foreach (range(22, 60) as $i) {
            $this->vehicule(sprintf('V %02d', $i));
        }

        $this->assertSame($avec21, $compter());
    }

    public function test_les_parametres_de_tri_et_de_page_invalides_sont_refuses(): void
    {
        $this->actingAs($this->user)->get(route('vehicules.index', ['tri' => 'photo']))->assertSessionHasErrors('tri');
        $this->actingAs($this->user)->get(route('vehicules.index', ['tri' => 'nom_vehicule', 'sens' => 'haut']))->assertSessionHasErrors('sens');
        $this->actingAs($this->user)->get(route('vehicules.index', ['page' => 0]))->assertSessionHasErrors('page');
    }
}
