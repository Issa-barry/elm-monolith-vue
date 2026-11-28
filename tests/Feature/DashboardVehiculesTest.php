<?php

namespace Tests\Feature;

use App\Enums\ClientType;
use App\Enums\ModeRemiseGrossiste;
use App\Enums\NatureOperation;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutTransfert;
use App\Features\ModuleFeature;
use App\Features\TableauDeBordFeature;
use App\Models\Categorie;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CommandeVenteRetour;
use App\Models\CommandeVenteRetourLigne;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\ProduitVariante;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\TransfertRetour;
use App\Models\TypeVehicule;
use App\Models\User;
use App\Models\Vehicule;
use App\Models\VehiculeCapacite;
use App\Support\Depenses\DepensePerimetre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\HasProduitVariante;
use Tests\TestCase;

/**
 * Tableau de bord du parc, Stat-Véhicules V1 (docs/tableau-de-bord-vehicules.md) : accès avec
 * `vehicules.read` ; parc à l'agence actuelle, activité à l'agence de l'opération ; règles
 * d'usage du serveur ; quantités par catégorie jamais additionnées ; un bloc par source autorisée
 * (ventes, logistique, dépenses) ; périmètre d'agences et isolation entre organisations.
 */
class DashboardVehiculesTest extends TestCase
{
    use HasProduitVariante, RefreshDatabase;

    private const TOUT = ['vehicules.read', 'ventes.read', 'logistique.read', 'depenses.read'];

    private Organization $org;

    private Site $matoto;

    private Site $cba;

    private TypeVehicule $tricycle;

    private Categorie $sachets;

    private Categorie $bouteilles;

    private ProduitVariante $sachet;

    private ProduitVariante $bouteille;

    protected function setUp(): void
    {
        parent::setUp();
        // Vendredi 27 novembre 2026 : « Ce mois » = novembre.
        Carbon::setTestNow('2026-11-27 10:00:00');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->org = Organization::factory()->create();
        // Désactivé par défaut (ADR 0071) : refus couvert par TableauxDeBordActivationTest.
        Feature::for($this->org)->activate(TableauDeBordFeature::VEHICULES);
        $this->matoto = Site::create(['organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'depot']);
        $this->cba = Site::create(['organization_id' => $this->org->id, 'nom' => 'CBA', 'type' => 'depot']);
        $this->tricycle = TypeVehicule::create(['organization_id' => $this->org->id, 'nom' => 'Tricycle', 'is_active' => true]);
        // Sachets : capacité obligatoire pour vendre (ADR 0042) ; bouteilles : facultative.
        $this->sachets = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachet eau', 'statut' => 'actif', 'capacite_vehicule_obligatoire' => true]);
        $this->bouteilles = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Bouteille', 'statut' => 'actif']);
        $this->sachet = $this->variante($this->sachets);
        $this->bouteille = $this->variante($this->bouteilles);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Fabriques ─────────────────────────────────────────────────────────────────────────

    private function utilisateur(array $permissions = self::TOUT, string $role = 'admin_entreprise', array $sites = [], ?Organization $org = null): User
    {
        $org ??= $this->org;
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole($role);
        $user->givePermissionTo($permissions);
        $sites = $sites === [] ? [Site::where('organization_id', $org->id)->orderBy('nom')->firstOrFail()] : $sites;
        foreach ($sites as $index => $site) {
            $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => $index === 0]);
        }

        return $user;
    }

    /** Agent non administrateur : ses seules agences de rattachement. */
    private function agent(array $permissions, array $sites): User
    {
        return $this->utilisateur($permissions, 'agent_stat_vehicules_test', $sites);
    }

    private function variante(Categorie $categorie): ProduitVariante
    {
        return $this->makeProduitAvecVariante($this->org, ['nom' => $categorie->nom, 'categorie_id' => $categorie->id])->variantes()->firstOrFail();
    }

    private function vehicule(string $nom, ?Site $site = null, array $attributs = [], ?Organization $org = null): Vehicule
    {
        $org ??= $this->org;

        return Vehicule::factory()->create([
            'organization_id' => $org->id,
            'site_id' => ($site ?? $this->matoto)->id,
            'nom_vehicule' => $nom,
            'type_vehicule_id' => $org->is($this->org) ? $this->tricycle->id : TypeVehicule::create(['organization_id' => $org->id, 'nom' => 'Camion', 'is_active' => true])->id,
            'proprietaire_id' => Proprietaire::factory()->create(['organization_id' => $org->id])->id,
            ...$attributs,
        ]);
    }

    private function capacite(Vehicule $vehicule, Categorie $categorie, int $max): void
    {
        VehiculeCapacite::create(['organization_id' => $vehicule->organization_id, 'vehicule_id' => $vehicule->id, 'categorie_id' => $categorie->id, 'capacite_max' => $max]);
    }

    private function equipe(Vehicule $vehicule, bool $chauffeurActif = true): void
    {
        $equipe = EquipeLivraison::create(['organization_id' => $vehicule->organization_id, 'vehicule_id' => $vehicule->id, 'nom' => 'Équipe '.$vehicule->nom_vehicule, 'is_active' => true]);
        $chauffeur = Livreur::factory()->create(['organization_id' => $vehicule->organization_id, 'is_active' => $chauffeurActif]);
        EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $chauffeur->id, 'role' => 'chauffeur', 'ordre' => 0]);
    }

    /** Vente partie (chargement validé) avec ses lignes : [variante, chargé, retourné]. */
    private function vente(Vehicule $vehicule, Site $site, string $chargement, array $lignes = [], array $attributs = []): CommandeVente
    {
        $commande = CommandeVente::create([
            'organization_id' => $vehicule->organization_id,
            'site_id' => $site->id,
            'vehicule_id' => $vehicule->id,
            'reference' => 'STV-'.uniqid(),
            'total_commande' => 10000,
            'statut' => StatutCommandeVente::LIVREE,
            'chargement_valide_at' => $chargement,
            ...$attributs,
        ]);
        foreach ($lignes as [$variante, $charge, $retourne]) {
            $commande->lignes()->create([
                'variante_id' => $variante->id,
                'quantite_demandee' => $charge,
                'quantite_chargee' => $charge,
                'quantite_retournee' => $retourne,
                'prix_usine_snapshot' => 1500,
                'prix_vente_snapshot' => 2000,
                'total_ligne' => $charge * 2000,
            ]);
        }
        // Un retour est toujours enregistré par CommandeVenteRetourService, avec sa ligne de retour.
        $retourne = array_sum(array_column($lignes, 2));
        if ($retourne > 0) {
            CommandeVenteRetour::create(['organization_id' => $commande->organization_id, 'commande_vente_id' => $commande->id,
                'motif' => 'client_absent', 'quantite_totale' => $retourne, 'montant_retourne' => $retourne * 2000]);
        }

        return $commande;
    }

    /** Transfert parti avec ses lignes : [variante, chargé, reçu, retourné]. */
    private function transfert(Vehicule $vehicule, Site $source, Site $destination, string $depart, array $lignes = [], array $attributs = []): TransfertLogistique
    {
        $transfert = TransfertLogistique::create([
            'organization_id' => $vehicule->organization_id,
            'reference' => 'TRF-'.uniqid(),
            'site_source_id' => $source->id,
            'site_destination_id' => $destination->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutTransfert::CLOTURE->value,
            'date_depart_reelle' => $depart,
            'created_by' => User::factory()->create(['organization_id' => $vehicule->organization_id])->id,
            ...$attributs,
        ]);
        foreach ($lignes as [$variante, $charge, $recue, $retourne]) {
            $transfert->lignes()->create([
                'variante_id' => $variante->id,
                'quantite_demandee' => $charge,
                'quantite_chargee' => $charge,
                'quantite_recue' => $recue,
                'quantite_retournee' => $retourne,
            ]);
        }
        $retourne = array_sum(array_column($lignes, 3));
        if ($retourne > 0) {
            TransfertRetour::create(['organization_id' => $transfert->organization_id, 'transfert_logistique_id' => $transfert->id,
                'site_id' => $source->id, 'motif' => 'autre', 'quantite_totale' => $retourne]);
        }

        return $transfert;
    }

    private function depense(Vehicule $vehicule, ?Site $site, int $montant, string $statut = 'valide', ?User $auteur = null): Depense
    {
        $type = DepenseType::firstOrCreate(['organization_id' => $this->org->id, 'libelle' => 'Entretien'], ['categorie' => 'vehicule', 'code' => 'entretien']);

        return Depense::factory()->{$statut}()->create([
            'organization_id' => $this->org->id,
            'user_id' => ($auteur ?? User::factory()->create(['organization_id' => $this->org->id]))->id,
            'depense_type_id' => $type->id,
            'beneficiaire_type' => 'vehicule',
            'beneficiaire_id' => $vehicule->id,
            'site_id' => $site?->id,
            'montant' => $montant,
            'date_depense' => '2026-11-10',
        ]);
    }

    /** @return array<string, mixed> props de la page */
    private function page(User $user, array $parametres = []): array
    {
        return $this->actingAs($user)
            ->get(route('dashboard.vehicules', $parametres))
            ->assertOk()
            ->assertSessionHasNoErrors()
            ->assertInertia(fn (Assert $page) => $page->component('DashboardVehicules'))
            ->viewData('page')['props'];
    }

    /** @return array<string, mixed> ligne d'une opération, par code */
    private static function operation(array $props, string $code): array
    {
        return collect($props['operations'])->firstWhere('code', $code);
    }

    /** @return array<string, mixed> ligne d'une catégorie, par nom */
    private static function categorie(array $props, string $nom): array
    {
        return collect($props['categories'])->firstWhere('categorie', $nom);
    }

    // ── Accès ─────────────────────────────────────────────────────────────────────────────

    public function test_acces_reserve_a_la_lecture_des_vehicules_et_au_module(): void
    {
        $this->actingAs($this->agent(['ventes.read'], [$this->matoto]))
            ->get(route('dashboard.vehicules'))
            ->assertForbidden();

        $user = $this->utilisateur();
        $this->page($user);

        Feature::for($this->org)->deactivate(ModuleFeature::VEHICULES);
        $this->actingAs($user)->get(route('dashboard.vehicules'))->assertForbidden();
    }

    // ── Parc ──────────────────────────────────────────────────────────────────────────────

    public function test_etat_du_parc_selon_les_regles_d_usage_du_serveur(): void
    {
        $vente = $this->vehicule('Vente prête');
        $this->capacite($vente, $this->sachets, 100);
        $sansCapacite = $this->vehicule('Vente sans capacité sachets');
        $logistiqueSansEquipe = $this->vehicule('Camion sans équipe', null, ['livraison_vente' => false, 'livraison_logistique' => true]);
        $this->capacite($logistiqueSansEquipe, $this->sachets, 800);
        $logistique = $this->vehicule('Camion équipé', null, ['livraison_vente' => false, 'livraison_logistique' => true]);
        $this->capacite($logistique, $this->sachets, 800);
        $this->equipe($logistique);
        $chauffeurInactif = $this->vehicule('Camion chauffeur inactif', null, ['livraison_vente' => false, 'livraison_logistique' => true]);
        $this->capacite($chauffeurInactif, $this->sachets, 800);
        $this->equipe($chauffeurInactif, false);
        $grossiste = $this->vehicule('Grossiste', null, ['livraison_vente' => false, 'livraison_grossiste' => true]);
        $this->capacite($grossiste, $this->sachets, 300);
        $this->vehicule('Sans usage', null, ['livraison_vente' => false]);
        $this->vehicule('Inactif', null, ['is_active' => false]);

        $props = $this->page($this->utilisateur());

        $this->assertSame(['total' => 8, 'actifs' => 7, 'inactifs' => 1, 'operationnels' => 3, 'a_configurer' => 4], array_diff_key($props['parc'], ['en_mission' => true]));

        // Vente : usage Vente + capacités obligatoires ; aucun chauffeur exigé.
        $this->assertSame([2, 1, 1], [self::operation($props, 'vente')['vehicules'], self::operation($props, 'vente')['possibles'], self::operation($props, 'vente')['bloques']]);
        // Distribution : usage Logistique + chauffeur actif ; transfert : usage Logistique seul.
        $this->assertSame([3, 1], [self::operation($props, 'distribution')['vehicules'], self::operation($props, 'distribution')['possibles']]);
        $this->assertSame([3, 3], [self::operation($props, 'transfert')['vehicules'], self::operation($props, 'transfert')['possibles']]);
        $this->assertSame([1, 1], [self::operation($props, 'grossiste')['vehicules'], self::operation($props, 'grossiste')['possibles']]);

        $motifs = collect($props['a_configurer'])->pluck('motifs', 'nom');
        $this->assertSame(['Camion chauffeur inactif', 'Camion sans équipe', 'Sans usage', 'Vente sans capacité sachets'], $motifs->keys()->sort()->values()->all());
        $this->assertStringContainsString('Sachet eau', $motifs['Vente sans capacité sachets'][0]);
        $this->assertStringStartsWith('Distribution impossible', $motifs['Camion sans équipe'][0]);
        $this->assertStringContainsString('inactif', $motifs['Camion chauffeur inactif'][0]);
        $this->assertStringContainsString('Aucun usage', $motifs['Sans usage'][0]);

        $statuts = collect($props['vehicules'])->pluck('statut', 'nom');
        $this->assertSame('operationnel', $statuts['Vente prête']);
        $this->assertSame('a_configurer', $statuts['Camion sans équipe']);
        $this->assertSame('inactif', $statuts['Inactif']);

        // Capacité du parc actif par catégorie, jamais additionnée entre catégories.
        $this->assertSame(100 + 800 + 800 + 800 + 300, self::categorie($props, 'Sachet eau')['capacite_parc']);
        $this->assertSame(5, self::categorie($props, 'Sachet eau')['vehicules_plafonnes']);
    }

    public function test_vehicules_en_mission_dans_le_perimetre_des_modules(): void
    {
        $enVente = $this->vehicule('En livraison');
        $this->vente($enVente, $this->matoto, '2026-11-27 08:00:00', [], ['statut' => StatutCommandeVente::LIVRAISON_EN_COURS]);
        $enTransfert = $this->vehicule('En transit', null, ['livraison_logistique' => true]);
        $this->transfert($enTransfert, $this->matoto, $this->cba, '2026-11-27', [], ['statut' => StatutTransfert::TRANSIT->value]);
        // Vente en cours enregistrée à CBA : hors du périmètre Ventes d'un agent de Matoto.
        $horsPerimetre = $this->vehicule('Vente à CBA');
        $this->vente($horsPerimetre, $this->cba, '2026-11-27 08:00:00', [], ['statut' => StatutCommandeVente::CHARGEMENT_EN_COURS]);
        $this->vehicule('Au dépôt');

        $admin = $this->page($this->utilisateur());
        $this->assertSame(['vehicules' => 3, 'ventes' => 2, 'transferts' => 1], $admin['parc']['en_mission']);

        $agent = $this->page($this->agent(['vehicules.read', 'ventes.read'], [$this->matoto]));
        $this->assertSame(['vehicules' => 1, 'ventes' => 1, 'transferts' => null], $agent['parc']['en_mission']);

        $sansSource = $this->page($this->agent(['vehicules.read'], [$this->matoto]));
        $this->assertNull($sansSource['parc']['en_mission']);
    }

    // ── Activité ──────────────────────────────────────────────────────────────────────────

    public function test_rotations_par_operation_sans_compter_deux_fois_un_vehicule_le_meme_jour(): void
    {
        $tricycle = $this->vehicule('Tricycle 1');
        $camion = $this->vehicule('Camion 1', null, ['livraison_vente' => false, 'livraison_logistique' => true]);
        $grossiste = $this->vehicule('Grossiste 1', null, ['livraison_vente' => false, 'livraison_grossiste' => true]);
        $this->vehicule('Jamais sorti');
        $client = Client::factory()->create(['organization_id' => $this->org->id, 'type' => ClientType::GROSSISTE->value]);

        // Deux ventes le même jour : deux rotations, un seul jour d'activité.
        $this->vente($tricycle, $this->matoto, '2026-11-10 08:00:00');
        $this->vente($tricycle, $this->matoto, '2026-11-10 15:00:00', [], ['statut' => StatutCommandeVente::CLOTUREE]);
        // Vente au comptoir partie dès sa validation (PDV) : sans chargement_valide_at.
        $this->vente($tricycle, $this->matoto, '2026-11-12 09:00:00', [], ['chargement_valide_at' => null, 'validated_at' => '2026-11-12 09:00:00', 'statut' => StatutCommandeVente::LIVRAISON_EN_COURS]);
        // Distribution et transfert du même camion le même jour : un seul jour-véhicule.
        $this->vente($camion, $this->matoto, '2026-11-15 07:00:00', [], ['nature_operation' => NatureOperation::DISTRIBUTION_CLIENT->value]);
        $this->transfert($camion, $this->matoto, $this->cba, '2026-11-15');
        $this->vente($grossiste, $this->matoto, '2026-11-16 07:00:00', [], ['client_id' => $client->id, 'mode_remise_grossiste' => ModeRemiseGrossiste::LIVRAISON->value]);

        // Non comptées : pas encore partie, annulée pour erreur de saisie, hors période, transfert annulé.
        $this->vente($tricycle, $this->matoto, '2026-11-20 08:00:00', [], ['chargement_valide_at' => null, 'statut' => StatutCommandeVente::A_CHARGER]);
        $this->vente($tricycle, $this->matoto, '2026-11-21 08:00:00', [], ['statut' => StatutCommandeVente::ANNULEE_ERREUR_SAISIE]);
        $this->vente($tricycle, $this->matoto, '2026-10-31 18:00:00');
        $this->transfert($camion, $this->matoto, $this->cba, '2026-11-18', [], ['statut' => StatutTransfert::ANNULE->value]);

        $props = $this->page($this->utilisateur());

        $this->assertSame(3, self::operation($props, 'vente')['rotations']);
        $this->assertSame(2, self::operation($props, 'vente')['jours_vehicule']);
        $this->assertSame(1, self::operation($props, 'distribution')['rotations']);
        $this->assertSame(1, self::operation($props, 'grossiste')['rotations']);
        $this->assertSame(1, self::operation($props, 'transfert')['rotations']);

        $activite = $props['activite'];
        $this->assertSame(6, $activite['rotations']);
        // Tricycle : 10 et 12 nov ; camion : 15 nov (distribution + transfert) ; grossiste : 16 nov.
        $this->assertSame(4, $activite['jours_vehicule']);
        $this->assertSame([3, 4, 75.0], [$activite['actifs_utilises'], $activite['actifs'], $activite['taux_utilisation']]);

        $this->assertSame(['vente', 'distribution', 'grossiste', 'transfert'], array_column($props['evolution']['series'], 'code'));
        $this->assertSame('jour', $props['evolution']['granularite']);
        $this->assertCount(27, $props['evolution']['tranches']);

        $camionLigne = collect($props['vehicules'])->firstWhere('nom', 'Camion 1');
        $this->assertSame([2, 1], [$camionLigne['rotations'], $camionLigne['jours']]);
    }

    public function test_quantites_par_categorie_remplissage_retours_et_ecarts(): void
    {
        $tricycle = $this->vehicule('Tricycle 1');
        $this->capacite($tricycle, $this->sachets, 100);
        $camion = $this->vehicule('Camion 1', null, ['livraison_vente' => false, 'livraison_logistique' => true]);
        $this->capacite($camion, $this->bouteilles, 500);

        $this->vente($tricycle, $this->matoto, '2026-11-10 08:00:00', [[$this->sachet, 80, 5]]);
        $this->vente($tricycle, $this->matoto, '2026-11-11 08:00:00', [[$this->sachet, 100, 0], [$this->bouteille, 30, 0]]);
        // Reçu + retourné − chargé : 380 + 0 − 400 = 20 manquants ; 110 − 100 = 10 en surplus.
        $this->transfert($camion, $this->matoto, $this->cba, '2026-11-12', [[$this->bouteille, 400, 380, 0], [$this->sachet, 100, 110, 0]]);

        $props = $this->page($this->utilisateur());
        $sachets = self::categorie($props, 'Sachet eau');
        $bouteilles = self::categorie($props, 'Bouteille');

        $this->assertSame([280, 3, 5, 0, 10], [$sachets['charge'], $sachets['chargements'], $sachets['retourne'], $sachets['ecarts_manquants'], $sachets['ecarts_surplus']]);
        // Remplissage sur les seuls chargements d'un véhicule plafonné pour la catégorie : 180 / 200.
        $this->assertEqualsWithDelta(90.0, $sachets['remplissage'], 0.001);
        $this->assertEqualsWithDelta(5 / 280 * 100, $sachets['taux_retour'], 0.001);
        // Bouteilles : vente sans capacité (non comptée dans le remplissage), transfert 400 / 500.
        $this->assertSame([430, 20, 0], [$bouteilles['charge'], $bouteilles['ecarts_manquants'], $bouteilles['ecarts_surplus']]);
        $this->assertEqualsWithDelta(80.0, $bouteilles['remplissage'], 0.001);

        $this->assertSame(1, $props['activite']['avec_retour']);
        $this->assertSame(1, $props['activite']['transferts_avec_ecart']);
        // Aucune ligne « toutes catégories » : chaque ligne est une catégorie.
        $this->assertSame(['Bouteille', 'Sachet eau'], array_column($props['categories'], 'categorie'));
    }

    public function test_une_regularisation_n_est_ni_un_retour_ni_un_chargement(): void
    {
        $tricycle = $this->vehicule('Tricycle 1');
        // 2 700 packs saisis au lieu de 270, régularisés après encaissement (ADR 0043) : la quantité
        // retirée s'ajoute à `quantite_retournee`, mais n'a jamais été chargée ni retournée.
        $commande = $this->vente($tricycle, $this->matoto, '2026-11-10 08:00:00', [[$this->sachet, 2700, 0]]);
        $ligne = $commande->lignes()->firstOrFail();
        $ligne->update(['quantite_retournee' => 2430]);
        $regularisation = CommandeVenteRetour::create(['organization_id' => $this->org->id, 'commande_vente_id' => $commande->id,
            'nature' => CommandeVenteRetour::NATURE_REGULARISATION, 'motif' => 'regularisation', 'quantite_totale' => 2430, 'montant_retourne' => 0]);
        CommandeVenteRetourLigne::create(['commande_vente_retour_id' => $regularisation->id, 'commande_vente_ligne_id' => $ligne->id,
            'variante_id' => $this->sachet->id, 'quantite_retournee' => 2430, 'prix_unitaire' => 0, 'montant_retourne' => 0]);

        $props = $this->page($this->utilisateur());

        $this->assertSame([270, 0], [self::categorie($props, 'Sachet eau')['charge'], self::categorie($props, 'Sachet eau')['retourne']]);
        $this->assertSame([1, 0], [$props['activite']['rotations'], $props['activite']['avec_retour']]);
    }

    // ── Droits par bloc ───────────────────────────────────────────────────────────────────

    public function test_chaque_bloc_suit_la_permission_et_le_module_de_sa_source(): void
    {
        $tricycle = $this->vehicule('Tricycle 1');
        $this->capacite($tricycle, $this->sachets, 100);
        $camion = $this->vehicule('Camion 1', null, ['livraison_logistique' => true]);
        $this->vente($tricycle, $this->matoto, '2026-11-10 08:00:00', [[$this->sachet, 80, 0]]);
        $this->transfert($camion, $this->matoto, $this->cba, '2026-11-12', [[$this->sachet, 50, 50, 0]]);
        $this->depense($tricycle, $this->matoto, 20000);

        // Lecture des véhicules seule : parc, sans aucun chiffre d'activité ni de dépense.
        $seul = $this->page($this->agent(['vehicules.read'], [$this->matoto]));
        $this->assertSame(['ventes' => false, 'transferts' => false, 'depenses' => false], $seul['blocs']);
        $this->assertNull($seul['activite']);
        $this->assertNull($seul['evolution']);
        $this->assertNull($seul['depenses']);
        $this->assertNull(self::operation($seul, 'vente')['rotations']);
        $this->assertNull(self::categorie($seul, 'Sachet eau')['charge']);
        $this->assertSame([null], array_unique(array_column($seul['vehicules'], 'rotations')));
        $this->assertSame([null], array_unique(array_column($seul['vehicules'], 'depenses')));

        // Ventes seules : les transferts ne sont ni lus ni envoyés.
        $ventes = $this->page($this->agent(['vehicules.read', 'ventes.read'], [$this->matoto]));
        $this->assertSame(1, $ventes['activite']['rotations']);
        $this->assertNull($ventes['activite']['transferts_avec_ecart']);
        $this->assertNull(self::operation($ventes, 'transfert')['rotations']);
        $this->assertSame(80, self::categorie($ventes, 'Sachet eau')['charge']);
        $this->assertNull(self::categorie($ventes, 'Sachet eau')['ecarts_manquants']);

        // Logistique seule.
        $logistique = $this->page($this->agent(['vehicules.read', 'logistique.read'], [$this->matoto]));
        $this->assertSame(1, $logistique['activite']['rotations']);
        $this->assertNull(self::operation($logistique, 'vente')['rotations']);

        // Module désactivé : même effet que l'absence de permission.
        $admin = $this->utilisateur();
        Feature::for($this->org)->deactivate(ModuleFeature::VENTES);
        Feature::for($this->org)->deactivate(ModuleFeature::DEPENSES);
        $sansModules = $this->page($admin);
        $this->assertSame(['ventes' => false, 'transferts' => true, 'depenses' => false], $sansModules['blocs']);
        $this->assertSame(1, $sansModules['activite']['rotations']);
        $this->assertNull($sansModules['depenses']);
    }

    // ── Agences ───────────────────────────────────────────────────────────────────────────

    public function test_parc_a_l_agence_actuelle_et_activite_a_l_agence_de_l_operation(): void
    {
        $deMatoto = $this->vehicule('Tricycle Matoto');
        $deCba = $this->vehicule('Tricycle CBA', $this->cba);
        // Vente enregistrée à Matoto par un véhicule de CBA : activité de Matoto.
        $this->vente($deCba, $this->matoto, '2026-11-10 08:00:00');
        // Vente enregistrée à CBA par un véhicule de Matoto : activité de CBA.
        $this->vente($deMatoto, $this->cba, '2026-11-11 08:00:00');
        // Transfert parti de CBA vers Matoto : agence de départ, CBA.
        $this->transfert($deMatoto, $this->cba, $this->matoto, '2026-11-12');

        $agent = $this->agent(['vehicules.read', 'ventes.read', 'logistique.read'], [$this->matoto]);
        $props = $this->page($agent);

        $this->assertSame([['value' => (string) $this->matoto->id, 'label' => 'Matoto']], $props['agences']);
        $this->assertTrue($props['perimetre_limite']);
        $this->assertSame(1, $props['parc']['total']);
        $this->assertSame(1, $props['activite']['rotations']);
        $lignes = collect($props['vehicules'])->keyBy('nom');
        $this->assertSame(0, $lignes['Tricycle Matoto']['rotations']);
        // Véhicule d'une autre agence, présent pour son activité à Matoto.
        $this->assertSame([1, 'CBA'], [$lignes['Tricycle CBA']['rotations'], $lignes['Tricycle CBA']['site']]);

        // Une agence hors du périmètre est refusée avec un message, jamais remplacée en silence.
        $this->actingAs($agent)
            ->get(route('dashboard.vehicules', ['site_id' => $this->cba->id]))
            ->assertSessionHasErrors(['site_id' => 'Agence inconnue ou hors de votre périmètre.']);

        // Administrateur, agence CBA choisie : parc de CBA, activité enregistrée à CBA.
        $cba = $this->page($this->utilisateur(), ['site_id' => $this->cba->id]);
        $this->assertSame(1, $cba['parc']['total']);
        $this->assertSame(2, $cba['activite']['rotations']);
        $this->assertSame(1, self::operation($cba, 'transfert')['rotations']);
    }

    // ── Dépenses ──────────────────────────────────────────────────────────────────────────

    public function test_depenses_validees_du_perimetre_et_cout_par_rotation(): void
    {
        $tricycle = $this->vehicule('Tricycle 1');
        $this->vente($tricycle, $this->matoto, '2026-11-10 08:00:00');
        $this->vente($tricycle, $this->matoto, '2026-11-11 08:00:00');
        $this->depense($tricycle, $this->matoto, 30000);
        $this->depense($tricycle, $this->matoto, 5000, 'brouillon');
        $this->depense($tricycle, $this->matoto, 7000, 'soumis');
        $this->depense($tricycle, $this->cba, 40000);

        $agent = $this->page($this->agent(['vehicules.read', 'ventes.read', 'depenses.read'], [$this->matoto]));
        $this->assertSame(['montant' => 30000.0, 'nombre' => 1, 'vehicules' => 1, 'rotations' => 2, 'cout_par_rotation' => 15000.0], $agent['depenses']);
        $this->assertSame(30000.0, collect($agent['vehicules'])->firstWhere('nom', 'Tricycle 1')['depenses']);

        $admin = $this->page($this->utilisateur());
        $this->assertSame(70000.0, $admin['depenses']['montant']);

        // Sans accès à l'activité : pas de coût par rotation.
        $sansActivite = $this->page($this->agent(['vehicules.read', 'depenses.read'], [$this->matoto]));
        $this->assertSame([30000.0, null, null], [$sansActivite['depenses']['montant'], $sansActivite['depenses']['rotations'], $sansActivite['depenses']['cout_par_rotation']]);
    }

    public function test_depenses_toutes_agences_restent_limitees_aux_agences_du_tableau_de_bord(): void
    {
        $tricycle = $this->vehicule('Tricycle 1');
        $this->depense($tricycle, $this->cba, 40000);
        $this->depense($tricycle, $this->matoto, 10000);

        // Lecture des dépenses de toutes les agences, mais tableau de bord limité à Matoto.
        $props = $this->page($this->agent(['vehicules.read', 'depenses.read', DepensePerimetre::PERMISSION_TOUTES_AGENCES], [$this->matoto]));
        $this->assertSame(10000.0, $props['depenses']['montant']);
    }

    // ── Organisations ─────────────────────────────────────────────────────────────────────

    public function test_aucune_donnee_d_une_autre_organisation(): void
    {
        $autre = Organization::factory()->create();
        $siteAutre = Site::create(['organization_id' => $autre->id, 'nom' => 'Ailleurs', 'type' => 'depot']);
        $etranger = $this->vehicule('Étranger', $siteAutre, [], $autre);
        CommandeVente::create([
            'organization_id' => $autre->id,
            'site_id' => $siteAutre->id,
            'vehicule_id' => $etranger->id,
            'reference' => 'ETR-'.uniqid(),
            'total_commande' => 1000,
            'statut' => StatutCommandeVente::LIVREE,
            'chargement_valide_at' => '2026-11-10 08:00:00',
        ]);
        $this->vehicule('Tricycle 1');

        $props = $this->page($this->utilisateur());

        $this->assertSame(1, $props['parc']['total']);
        $this->assertSame(0, $props['activite']['rotations']);
        $this->assertSame(['Tricycle 1'], array_column($props['vehicules'], 'nom'));
        $this->assertNotContains((string) $siteAutre->id, array_column($props['agences'], 'value'));
    }
}
