<?php

namespace Tests\Feature\Achats;

use App\Enums\StatutCommandeAchat;
use App\Enums\StatutFactureFournisseur;
use App\Models\CommandeAchat;
use App\Models\EntrepriseTierce;
use App\Models\FactureFournisseur;
use App\Models\FactureFournisseurLigne;
use App\Models\Fournisseur;
use App\Models\Organization;
use App\Models\ReceptionAchat;
use App\Models\RegleValidationRole;
use App\Models\Site;
use App\Models\User;
use App\Services\Achats\FactureFournisseurService;
use App\Services\Achats\PaiementFournisseurService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\TestCase;

/**
 * Preuve de concurrence RÉELLE sur MySQL (InnoDB, REPEATABLE READ) — ADR 0022 : deux validations
 * de factures sur la même quantité reçue. La validation B a déjà lu la base dans sa transaction
 * (sa vue cohérente est figée) quand la facture A est validée et commitée par une AUTRE connexion.
 * B doit voir A et refuser : le « déjà facturé » doit être lu par une lecture verrouillante (dernière
 * version validée), jamais dans la vue cohérente de B, qui ignorerait A.
 *
 * Même infrastructure que Stock\VarianteStockConcurrenceTest : base dédiée
 * elm_monolithe_concurrency_test (connexions mysql_testing / mysql_testing_2), jamais la base de
 * dev ni le sqlite de la suite ; skip propre si indisponible.
 */
class FactureFournisseurConcurrenceTest extends TestCase
{
    use HasCaissesDediees, HasProduitVariante;

    private Organization $org;

    private User $user;

    private const CONNECTION = 'mysql_testing';

    private ?string $defautPrecedent = null;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            DB::connection(self::CONNECTION)->getPdo();
            DB::connection('mysql_testing_2')->getPdo();
        } catch (\Throwable) {
            $this->markTestSkipped('Connexions mysql_testing indisponibles (base elm_monolithe_concurrency_test).');
        }
        if (! DB::connection(self::CONNECTION)->getSchemaBuilder()->hasTable('factures_fournisseurs')) {
            $this->markTestSkipped('Base elm_monolithe_concurrency_test non migrée (factures_fournisseurs absente).');
        }

        $this->defautPrecedent = config('database.default');
        config(['database.default' => self::CONNECTION]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    protected function tearDown(): void
    {
        if ($this->defautPrecedent !== null) {
            config(['database.default' => $this->defautPrecedent]);
        }
        DB::purge('mysql_testing');
        DB::purge('mysql_testing_2');

        parent::tearDown();
    }

    public function test_une_validation_concurrente_ne_facture_jamais_deux_fois_la_meme_quantite(): void
    {
        $org = Organization::factory()->create();
        $site = Site::factory()->for($org)->create();
        $entreprise = EntrepriseTierce::create(['organization_id' => $org->id, 'raison_sociale' => 'FOURNISSEUR CONCURRENCE']);
        $fournisseur = Fournisseur::create(['organization_id' => $org->id, 'entreprise_tierce_id' => $entreprise->id, 'is_active' => true]);
        $variante = $this->makeProduitAvecVariante($org, ['nom' => 'Préformes', 'type' => 'materiel'])->variantes()->first();

        $commande = CommandeAchat::create([
            'organization_id' => $org->id, 'site_id' => $site->id, 'fournisseur_id' => $fournisseur->id,
            'total_commande' => 100_000, 'statut' => StatutCommandeAchat::RECEPTIONNEE, 'validee_at' => now(),
        ]);
        $ligneCommande = $commande->lignes()->create(['variante_id' => $variante->id, 'qte' => 100, 'qte_recue' => 100, 'prix_achat_snapshot' => 1000, 'total_ligne' => 100_000]);
        $reception = ReceptionAchat::create([
            'organization_id' => $org->id, 'commande_achat_id' => $commande->id, 'site_id' => $site->id,
            'reference' => 'RCA-CONC-'.uniqid(), 'date_reception' => now()->toDateString(),
        ]);
        $ligneRecue = $reception->lignes()->create(['commande_achat_ligne_id' => $ligneCommande->id, 'variante_id' => $variante->id, 'qte_recue' => 100, 'cout_unitaire' => 1000]);

        $factureA = $this->brouillon($org, $commande, $fournisseur, $site, $ligneRecue, 'A');
        $factureB = $this->brouillon($org, $commande, $fournisseur, $site, $ligneRecue, 'B');
        $validateur = $this->validateur($org, $site);

        $refus = null;
        DB::connection(self::CONNECTION)->beginTransaction();
        try {
            // B a déjà lu la base dans sa transaction : sa vue cohérente est figée ici.
            FactureFournisseurLigne::query()->count();

            // A est validée et commitée par une AUTRE connexion pendant ce temps.
            DB::connection('mysql_testing_2')->table('factures_fournisseurs')
                ->where('id', $factureA->id)
                ->update(['statut' => StatutFactureFournisseur::VALIDEE->value, 'validee_at' => now()]);

            try {
                app(FactureFournisseurService::class)->valider($factureB, $validateur);
            } catch (ValidationException $e) {
                $refus = $e->errors()['validation'][0] ?? null;
            }
        } finally {
            DB::connection(self::CONNECTION)->rollBack();
        }

        $this->assertNotNull($refus, 'La seconde validation aurait facturé une deuxième fois les 100 unités reçues.');
        $this->assertStringContainsString('seulement 0 reçus et non encore facturés', $refus);
    }

    /**
     * ADR 0024 : le paiement B a déjà lu la base quand un paiement A soldant la facture est commité
     * par une autre connexion — B relit la facture sous verrou (dernière version) et refuse : la
     * facture n'est jamais payée deux fois.
     */
    public function test_un_paiement_concurrent_ne_paie_jamais_deux_fois_la_meme_facture(): void
    {
        $org = Organization::factory()->create();
        $site = Site::factory()->for($org)->create();
        $entreprise = EntrepriseTierce::create(['organization_id' => $org->id, 'raison_sociale' => 'FOURNISSEUR PAIEMENT']);
        $fournisseur = Fournisseur::create(['organization_id' => $org->id, 'entreprise_tierce_id' => $entreprise->id, 'is_active' => true]);
        $commande = CommandeAchat::create([
            'organization_id' => $org->id, 'site_id' => $site->id, 'fournisseur_id' => $fournisseur->id,
            'total_commande' => 100_000, 'statut' => StatutCommandeAchat::RECEPTIONNEE, 'validee_at' => now(),
        ]);
        $facture = FactureFournisseur::create([
            'organization_id' => $org->id, 'commande_achat_id' => $commande->id, 'fournisseur_id' => $fournisseur->id,
            'site_id' => $site->id, 'reference' => 'FAF-CONC-PAY-'.uniqid(), 'numero_facture_fournisseur' => 'CONC-PAY',
            'date_facture' => now()->toDateString(), 'montant_ht' => 100_000, 'montant_ttc' => 100_000,
            'statut' => StatutFactureFournisseur::VALIDEE, 'validee_at' => now(),
        ]);

        $permission = Permission::firstOrCreate(['name' => 'factures-fournisseurs.payer', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'tresorier_concurrence', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        RegleValidationRole::create([
            'organization_id' => $org->id, 'domaine' => RegleValidationRole::DOMAINE_ACHATS,
            'role_name' => 'tresorier_concurrence', 'perimetre' => 'toutes_agences',
        ]);
        $payeur = User::factory()->create(['organization_id' => $org->id]);
        $payeur->assignRole($role);
        $payeur->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);
        // HasCaissesDediees lit $this->org / $this->user (auteur de l'alimentation de la caisse).
        $this->org = $org;
        $this->user = $payeur->fresh();
        $this->equiperPayeurEspeces($this->user, $site->id, 500_000);

        $refus = null;
        DB::connection(self::CONNECTION)->beginTransaction();
        try {
            // B a déjà lu la facture (encore « validée ») : sa vue cohérente est figée ici.
            $lueParB = FactureFournisseur::findOrFail($facture->id);

            // A solde la facture et commite par une AUTRE connexion pendant ce temps.
            DB::connection('mysql_testing_2')->table('factures_fournisseurs')
                ->where('id', $facture->id)
                ->update(['montant_paye' => 100_000, 'statut' => StatutFactureFournisseur::PAYEE->value]);

            try {
                app(PaiementFournisseurService::class)->payer($lueParB, $payeur->fresh(), [
                    'montant' => 100_000, 'mode_paiement' => 'especes', 'date_paiement' => now()->toDateString(),
                ]);
            } catch (ValidationException $e) {
                $refus = $e->errors()['paiement'][0] ?? $e->errors()['montant'][0] ?? null;
            }
        } finally {
            DB::connection(self::CONNECTION)->rollBack();
        }

        $this->assertNotNull($refus, 'Le second paiement aurait payé une deuxième fois la facture déjà soldée.');
    }

    private function brouillon(Organization $org, CommandeAchat $commande, Fournisseur $fournisseur, Site $site, $ligneRecue, string $suffixe): FactureFournisseur
    {
        $facture = FactureFournisseur::create([
            'organization_id' => $org->id, 'commande_achat_id' => $commande->id, 'fournisseur_id' => $fournisseur->id,
            'site_id' => $site->id, 'reference' => "FAF-CONC-{$suffixe}-".uniqid(), 'numero_facture_fournisseur' => "CONC-{$suffixe}",
            'date_facture' => now()->toDateString(), 'montant_ht' => 100_000, 'montant_ttc' => 100_000,
            'statut' => StatutFactureFournisseur::BROUILLON,
        ]);
        $facture->lignes()->create([
            'reception_achat_ligne_id' => $ligneRecue->id, 'commande_achat_ligne_id' => $ligneRecue->commande_achat_ligne_id,
            'variante_id' => $ligneRecue->variante_id, 'libelle_snapshot' => 'Préformes', 'qte_facturee' => 100,
            'prix_unitaire' => 1000, 'total_ht' => 100_000,
        ]);

        return $facture;
    }

    private function validateur(Organization $org, Site $site): User
    {
        $permission = Permission::firstOrCreate(['name' => 'factures-fournisseurs.valider', 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'comptable_concurrence', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);
        RegleValidationRole::create([
            'organization_id' => $org->id, 'domaine' => RegleValidationRole::DOMAINE_ACHATS,
            'role_name' => 'comptable_concurrence', 'perimetre' => 'toutes_agences',
        ]);
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole($role);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);

        return $user->fresh();
    }
}
