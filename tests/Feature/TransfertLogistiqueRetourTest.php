<?php

namespace Tests\Feature;

use App\Enums\MotifRetourTransfert;
use App\Enums\StatutTransfert;
use App\Enums\TypeEcartLogistique;
use App\Features\ModuleFeature;
use App\Models\MouvementStock;
use App\Models\Organization;
use App\Models\Produit;
use App\Models\Site;
use App\Models\TransfertActivite;
use App\Models\TransfertLigne;
use App\Models\TransfertLogistique;
use App\Models\TransfertRetour;
use App\Models\TransfertRetourLigne;
use App\Models\User;
use App\Models\VarianteStock;
use App\Models\Vehicule;
use App\Services\MouvementStockMotifService;
use App\Services\TransfertLogistiqueService;
use App\Services\TransfertRetourService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\HasProduitVariante;
use Tests\TestCase;

/**
 * Retour au site source d'une marchandise partie sur un transfert logistique (ADR 0029) : entrée
 * de stock distincte au site source, quantités chargée/reçue intactes, statut RETOURNE en cas de
 * retour total avant réception, réception bornée à chargé − retourné, invalidation autorisée,
 * permission dédiée réservée au site source.
 */
class TransfertLogistiqueRetourTest extends TestCase
{
    use HasProduitVariante, RefreshDatabase;

    private const PERMISSIONS = ['logistique.read', 'logistique.update', 'logistique.enregistrer_retour'];

    private Organization $org;

    private Site $source;

    private Site $destination;

    private Vehicule $vehicule;

    private Produit $produit;

    private string $varianteId;

    private User $agentSource;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $this->org = Organization::factory()->create();
        Feature::for($this->org)->activate(ModuleFeature::LOGISTIQUE);

        foreach (self::PERMISSIONS as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        foreach (['super_admin', 'admin_entreprise', 'manager'] as $role) {
            Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        }

        $this->source = $this->makeSite('Cba');
        $this->destination = $this->makeSite('Sonfonia');
        $this->vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'livraison_vente' => false,
            'livraison_logistique' => true,
            'is_active' => true,
        ]);

        $this->produit = $this->makeProduitAvecVariante($this->org, ['nom' => 'Eau 19L']);
        $this->varianteId = $this->produit->variantePrincipale()->first()->id;

        $this->agentSource = $this->makeUser('manager', $this->source);
        $this->actingAs($this->agentSource);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeSite(string $nom): Site
    {
        return Site::create([
            'organization_id' => $this->org->id,
            'nom' => $nom,
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
    }

    private function makeUser(string $role, ?Site $site, array $permissions = self::PERMISSIONS, ?Organization $org = null): User
    {
        $user = User::factory()->create(['organization_id' => ($org ?? $this->org)->id]);
        $user->assignRole($role);
        $user->givePermissionTo($permissions);
        if ($site) {
            $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);
        }

        return $user;
    }

    private function stock(Site $site): int
    {
        return (int) VarianteStock::where('produit_variante_id', $this->varianteId)->where('site_id', $site->id)->value('qte_stock');
    }

    /** Transfert parti (TRANSIT) : 30 chargés, sortis du stock source (100 → 70). */
    private function makeTransfertEnTransit(int $qteChargee = 30): TransfertLogistique
    {
        VarianteStock::create([
            'organization_id' => $this->org->id,
            'produit_variante_id' => $this->varianteId,
            'site_id' => $this->source->id,
            'qte_stock' => 100,
        ]);

        $transfert = TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'site_source_id' => $this->source->id,
            'site_destination_id' => $this->destination->id,
            'vehicule_id' => $this->vehicule->id,
            'statut' => StatutTransfert::CHARGEMENT,
        ]);

        TransfertLigne::create([
            'transfert_logistique_id' => $transfert->id,
            'variante_id' => $this->varianteId,
            'quantite_demandee' => $qteChargee,
            'quantite_chargee' => $qteChargee,
        ]);

        TransfertLogistiqueService::avancerStatut($transfert);

        return $transfert->fresh();
    }

    private function ligne(TransfertLogistique $transfert): TransfertLigne
    {
        return $transfert->fresh()->lignes()->firstOrFail();
    }

    private function retourner(TransfertLogistique $transfert, int $quantite, MotifRetourTransfert $motif = MotifRetourTransfert::REFUS_RECEPTION, ?string $commentaire = null): TransfertRetour
    {
        return TransfertRetourService::enregistrer(
            $transfert,
            [['id' => $this->ligne($transfert)->id, 'quantite' => $quantite]],
            $motif,
            $commentaire,
        );
    }

    private function receptionner(TransfertLogistique $transfert, int $recue, TypeEcartLogistique $ecart = TypeEcartLogistique::CONFORME): TransfertLogistique
    {
        $this->ligne($transfert)->update(['quantite_recue' => $recue, 'ecart_type' => $ecart->value]);

        return TransfertLogistiqueService::avancerStatut($transfert->fresh());
    }

    private function assertRetourRefuse(callable $operation, string $cle): void
    {
        try {
            $operation();
            $this->fail('Le retour aurait dû être refusé.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($cle, $e->errors());
        }
    }

    // ── Stock et quantités ────────────────────────────────────────────────────

    public function test_retour_partiel_en_transit_reintegre_le_site_source_par_une_entree_distincte(): void
    {
        $transfert = $this->makeTransfertEnTransit(30);
        $this->assertSame(70, $this->stock($this->source));

        $retour = $this->retourner($transfert, 10, MotifRetourTransfert::ERREUR_CHARGEMENT, 'Mauvais produit');

        $this->assertSame(80, $this->stock($this->source));
        $this->assertSame(0, $this->stock($this->destination));
        $this->assertSame(StatutTransfert::TRANSIT, $transfert->fresh()->statut);

        $ligne = $this->ligne($transfert);
        $this->assertSame(30, $ligne->quantite_chargee);
        $this->assertNull($ligne->quantite_recue);
        $this->assertSame(10, $ligne->quantite_retournee);

        $retourLigne = $retour->lignes->first();
        $this->assertDatabaseHas('mouvements_stock', [
            'site_id' => $this->source->id,
            'type' => 'entree',
            'quantite' => 10,
            'source_type' => TransfertRetourLigne::class,
            'source_id' => $retourLigne->id,
        ]);
        // La sortie du départ reste intacte dans le journal.
        $this->assertDatabaseHas('mouvements_stock', [
            'site_id' => $this->source->id,
            'type' => 'sortie',
            'quantite' => 30,
            'source_type' => TransfertLigne::class,
            'annule_par_id' => null,
        ]);

        $this->assertSame(MotifRetourTransfert::ERREUR_CHARGEMENT, $retour->motif);
        $this->assertSame('Mauvais produit', $retour->commentaire);
        $this->assertSame($this->agentSource->id, $retour->created_by);
        $this->assertSame($this->source->id, $retour->site_id);
        $this->assertFalse($retour->retour_total);
        $this->assertTrue(TransfertActivite::where('transfert_logistique_id', $transfert->id)->where('action', 'retour_enregistre')->exists());
    }

    public function test_retours_successifs_jusqu_au_retour_total_passent_le_transfert_en_retourne(): void
    {
        $transfert = $this->makeTransfertEnTransit(30);

        $this->retourner($transfert, 10);
        $dernier = $this->retourner($transfert, 20, MotifRetourTransfert::TRANSFERT_IMPOSSIBLE);

        $this->assertTrue($dernier->retour_total);
        $this->assertSame(StatutTransfert::RETOURNE, $transfert->fresh()->statut);
        $this->assertTrue($transfert->fresh()->isTerminal());
        $this->assertSame(100, $this->stock($this->source));
        $this->assertSame(2, MouvementStock::where('source_type', TransfertRetourLigne::class)->count());
        $this->assertTrue(TransfertActivite::where('transfert_logistique_id', $transfert->id)->where('action', 'retourne')->exists());

        $this->assertRetourRefuse(fn () => $this->retourner($transfert, 1), 'retour');
    }

    public function test_retour_superieur_a_la_quantite_retournable_est_refuse_sans_mouvement(): void
    {
        $transfert = $this->makeTransfertEnTransit(30);
        $this->retourner($transfert, 20);

        $this->assertRetourRefuse(fn () => $this->retourner($transfert, 11), 'lignes');

        $this->assertSame(20, $this->ligne($transfert)->quantite_retournee);
        $this->assertSame(90, $this->stock($this->source));
        $this->assertSame(1, TransfertRetour::count());
    }

    public function test_double_soumission_du_meme_retour_ne_depasse_jamais_la_quantite_chargee(): void
    {
        $transfert = $this->makeTransfertEnTransit(30);
        $ligneId = $this->ligne($transfert)->id;

        // Deux requêtes construites sur la même instance périmée : la seconde relit les quantités
        // sous verrou et est refusée.
        TransfertRetourService::enregistrer($transfert, [['id' => $ligneId, 'quantite' => 30]], MotifRetourTransfert::REFUS_RECEPTION);
        $this->assertRetourRefuse(
            fn () => TransfertRetourService::enregistrer($transfert, [['id' => $ligneId, 'quantite' => 30]], MotifRetourTransfert::REFUS_RECEPTION),
            'retour',
        );

        $this->assertSame(100, $this->stock($this->source));
        $this->assertSame(1, TransfertRetour::count());
    }

    public function test_motif_autre_exige_un_commentaire(): void
    {
        $transfert = $this->makeTransfertEnTransit(30);

        $this->assertRetourRefuse(fn () => $this->retourner($transfert, 5, MotifRetourTransfert::AUTRE, '  '), 'commentaire');
        $this->assertSame(0, TransfertRetour::count());
    }

    public function test_ligne_etrangere_au_transfert_est_refusee(): void
    {
        $transfert = $this->makeTransfertEnTransit(30);

        $this->assertRetourRefuse(
            fn () => TransfertRetourService::enregistrer($transfert, [['id' => 'inconnue', 'quantite' => 1]], MotifRetourTransfert::REFUS_RECEPTION),
            'lignes',
        );
    }

    /** @return array<string, array{StatutTransfert}> */
    public static function statutsNonEligibles(): array
    {
        return [
            'brouillon' => [StatutTransfert::BROUILLON],
            'chargement' => [StatutTransfert::CHARGEMENT],
            'annule' => [StatutTransfert::ANNULE],
            'cloture' => [StatutTransfert::CLOTURE],
            'retourne' => [StatutTransfert::RETOURNE],
        ];
    }

    #[DataProvider('statutsNonEligibles')]
    public function test_retour_refuse_hors_transit_et_reception(StatutTransfert $statut): void
    {
        $transfert = $this->makeTransfertEnTransit(30);
        $transfert->forceFill(['statut' => $statut->value])->save();

        $this->assertFalse($transfert->fresh()->isRetournable());
        $this->assertRetourRefuse(fn () => $this->retourner($transfert->fresh(), 1), 'retour');
    }

    // ── Réception ─────────────────────────────────────────────────────────────

    public function test_reception_apres_retour_est_bornee_a_charge_moins_retourne(): void
    {
        $transfert = $this->makeTransfertEnTransit(30);
        $this->retourner($transfert, 10);

        try {
            $this->receptionner($transfert, 25);
            $this->fail('La réception aurait dû être refusée.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('statut', $e->errors());
        }
        $this->assertSame(StatutTransfert::TRANSIT, $transfert->fresh()->statut);

        $this->receptionner($transfert, 20);

        $this->assertSame(StatutTransfert::RECEPTION, $transfert->fresh()->statut);
        $this->assertSame(20, $this->stock($this->destination));
        $this->assertSame(80, $this->stock($this->source));
        // Ni manquant ni surplus : 20 reçus + 10 revenus = 30 chargés.
        $this->assertSame(0, $this->ligne($transfert)->ecart);
    }

    public function test_surplus_reste_permis_sans_retour(): void
    {
        $transfert = $this->makeTransfertEnTransit(30);

        $this->receptionner($transfert, 32, TypeEcartLogistique::SURPLUS);

        $this->assertSame(StatutTransfert::RECEPTION, $transfert->fresh()->statut);
        $this->assertSame(2, $this->ligne($transfert)->ecart);
    }

    public function test_retour_en_reception_porte_seulement_sur_le_non_recu(): void
    {
        $transfert = $this->makeTransfertEnTransit(30);
        $this->receptionner($transfert, 25, TypeEcartLogistique::MANQUANT);
        $this->assertSame(-5, $this->ligne($transfert)->ecart);

        $transfert = $transfert->fresh();
        $transfert->lignes->each->setRelation('transfert', $transfert);
        $this->assertSame(5, $transfert->lignes->first()->quantite_retournable);

        $this->assertRetourRefuse(fn () => $this->retourner($transfert, 6), 'lignes');
        $retour = $this->retourner($transfert, 5);

        $this->assertFalse($retour->retour_total);
        $this->assertSame(StatutTransfert::RECEPTION, $transfert->fresh()->statut);
        $this->assertSame(75, $this->stock($this->source));
        $this->assertSame(25, $this->stock($this->destination));
        $this->assertSame(0, $this->ligne($transfert)->ecart);
        $this->assertFalse($transfert->fresh()->isRetournable());
    }

    public function test_invalidation_de_la_reception_reste_permise_et_la_nouvelle_reception_reste_bornee(): void
    {
        $admin = $this->makeUser('admin_entreprise', $this->destination);
        $transfert = $this->makeTransfertEnTransit(30);
        $this->retourner($transfert, 10);
        $this->receptionner($transfert, 20);

        $this->actingAs($admin)
            ->post("/backoffice/logistique/{$transfert->id}/validation-reception", ['decision' => 'invalider'])
            ->assertRedirect("/backoffice/logistique/{$transfert->id}");

        $this->assertSame(StatutTransfert::TRANSIT, $transfert->fresh()->statut);
        $this->assertSame(0, $this->stock($this->destination));
        // Le retour reste acquis : 80 au site source, 20 encore retournables.
        $this->assertSame(80, $this->stock($this->source));
        $transfert = $transfert->fresh();
        $transfert->lignes->each->setRelation('transfert', $transfert);
        $this->assertSame(20, $transfert->lignes->first()->quantite_retournable);

        $this->assertRetourRefuse(fn () => $this->receptionner($transfert, 21), 'statut');
    }

    public function test_invalidation_hors_reception_est_refusee_meme_pour_le_super_admin(): void
    {
        $superAdmin = $this->makeUser('super_admin', null);
        $transfert = $this->makeTransfertEnTransit(30);

        $this->actingAs($superAdmin)
            ->from("/backoffice/logistique/{$transfert->id}")
            ->post("/backoffice/logistique/{$transfert->id}/validation-reception", ['decision' => 'invalider'])
            ->assertSessionHasErrors('decision');

        $this->assertSame(StatutTransfert::TRANSIT, $transfert->fresh()->statut);
        $this->assertSame(70, $this->stock($this->source));
    }

    public function test_la_quantite_chargee_ne_peut_plus_etre_modifiee_apres_le_depart(): void
    {
        $admin = $this->makeUser('admin_entreprise', $this->destination);
        $transfert = $this->makeTransfertEnTransit(30);
        $ligne = $this->ligne($transfert);

        $this->actingAs($admin)->post("/backoffice/logistique/{$transfert->id}/statut/avancer", [
            'lignes' => [[
                'id' => $ligne->id,
                'quantite_chargee' => 50,
                'quantite_recue' => 30,
                'ecart_type' => TypeEcartLogistique::CONFORME->value,
            ]],
        ]);

        $ligne->refresh();
        $this->assertSame(30, $ligne->quantite_chargee);
        $this->assertSame(30, $ligne->quantite_recue);
    }

    // ── HTTP, permission, isolation ───────────────────────────────────────────

    private function postRetour(TransfertLogistique $transfert, int $quantite, array $extra = [])
    {
        return $this->from("/backoffice/logistique/{$transfert->id}")
            ->post("/backoffice/logistique/{$transfert->id}/retour", array_merge([
                'motif' => MotifRetourTransfert::REFUS_RECEPTION->value,
                'lignes' => [['id' => $this->ligne($transfert)->id, 'quantite' => $quantite]],
            ], $extra));
    }

    public function test_agent_du_site_source_enregistre_un_retour(): void
    {
        $transfert = $this->makeTransfertEnTransit(30);

        $this->actingAs($this->agentSource)
            ->get("/backoffice/logistique/{$transfert->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('can_enregistrer_retour', true)
                ->has('motifs_retour', 4)
                ->where('transfert.lignes.0.quantite_retournable', 30)
            );

        $this->postRetour($transfert, 12)
            ->assertRedirect("/backoffice/logistique/{$transfert->id}")
            ->assertSessionHas('success');

        $this->assertSame(82, $this->stock($this->source));

        $this->get("/backoffice/logistique/{$transfert->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->has('transfert.retours', 1)
                ->where('transfert.retours.0.quantite_totale', 12)
                ->where('transfert.retours.0.motif', MotifRetourTransfert::REFUS_RECEPTION->value)
                ->where('transfert.lignes.0.quantite_retournee', 12)
                ->where('transfert.lignes.0.quantite_retournable', 18)
            );
    }

    public function test_retour_total_affiche_le_statut_retourne_en_orange(): void
    {
        $transfert = $this->makeTransfertEnTransit(30);

        $this->postRetour($transfert, 30)->assertSessionHas('success');

        $this->get("/backoffice/logistique/{$transfert->id}")
            ->assertInertia(fn (Assert $page) => $page
                ->where('transfert.statut', 'retourne')
                ->where('transfert.statut_label', 'Retourné')
                ->where('transfert.statut_dot_class', 'bg-orange-500')
                ->where('can_enregistrer_retour', false)
            );
    }

    public function test_sans_la_permission_dediee_le_retour_est_interdit(): void
    {
        $sansPermission = $this->makeUser('manager', $this->source, ['logistique.read', 'logistique.update']);
        $transfert = $this->makeTransfertEnTransit(30);

        $this->actingAs($sansPermission)
            ->get("/backoffice/logistique/{$transfert->id}")
            ->assertInertia(fn (Assert $page) => $page->where('can_enregistrer_retour', false));

        $this->postRetour($transfert, 5)->assertForbidden();
        $this->assertSame(70, $this->stock($this->source));
    }

    public function test_un_agent_du_seul_site_destination_ne_peut_pas_enregistrer_le_retour(): void
    {
        $agentDestination = $this->makeUser('manager', $this->destination);
        $transfert = $this->makeTransfertEnTransit(30);

        $this->actingAs($agentDestination)->postRetour($transfert, 5)->assertForbidden();
        $this->assertSame(0, TransfertRetour::count());
    }

    public function test_un_administrateur_peut_enregistrer_le_retour_sans_rattachement_au_site_source(): void
    {
        $admin = $this->makeUser('admin_entreprise', $this->destination);
        $transfert = $this->makeTransfertEnTransit(30);

        $this->actingAs($admin)->postRetour($transfert, 5)->assertSessionHas('success');
        $this->assertSame(75, $this->stock($this->source));
    }

    public function test_isolation_organisationnelle(): void
    {
        $autreOrg = Organization::factory()->create();
        Feature::for($autreOrg)->activate(ModuleFeature::LOGISTIQUE);
        $etranger = $this->makeUser('admin_entreprise', null, self::PERMISSIONS, $autreOrg);
        $transfert = $this->makeTransfertEnTransit(30);

        $this->actingAs($etranger)->postRetour($transfert, 5);

        $this->assertSame(0, TransfertRetour::count());
        $this->assertSame(70, $this->stock($this->source));
    }

    public function test_super_admin_ne_voit_pas_le_bouton_sur_un_transfert_non_eligible(): void
    {
        $superAdmin = $this->makeUser('super_admin', null);
        $transfert = $this->makeTransfertEnTransit(30);
        $transfert->forceFill(['statut' => StatutTransfert::CLOTURE->value])->save();

        $this->actingAs($superAdmin)
            ->get("/backoffice/logistique/{$transfert->id}")
            ->assertInertia(fn (Assert $page) => $page->where('can_enregistrer_retour', false));

        $this->actingAs($superAdmin)->postRetour($transfert, 5)->assertSessionHasErrors('retour');
    }

    public function test_motif_invalide_ou_commentaire_manquant_refuses_a_la_validation(): void
    {
        $transfert = $this->makeTransfertEnTransit(30);

        $this->postRetour($transfert, 5, ['motif' => 'produit_endommage'])->assertSessionHasErrors('motif');
        $this->postRetour($transfert, 5, ['motif' => MotifRetourTransfert::AUTRE->value])->assertSessionHasErrors('commentaire');
        $this->assertSame(0, TransfertRetour::count());
    }

    // ── Journal de stock, liste ───────────────────────────────────────────────

    public function test_journal_de_stock_libelle_le_retour_de_transfert(): void
    {
        $transfert = $this->makeTransfertEnTransit(30);
        $this->retourner($transfert, 4);

        $mouvements = MouvementStockMotifService::annoter(
            MouvementStock::where('source_type', TransfertRetourLigne::class)->get()
        );

        $this->assertSame(MouvementStockMotifService::KEY_RETOUR_TRANSFERT, $mouvements->first()->motif_type);
        $this->assertSame("Retour transfert — {$transfert->reference}", $mouvements->first()->motif_label);

        $filtre = MouvementStockMotifService::appliquerFiltre(MouvementStock::query(), MouvementStockMotifService::KEY_RETOUR_TRANSFERT);
        $this->assertSame(1, $filtre->count());
    }

    public function test_un_transfert_retourne_apparait_dans_la_liste_des_transferts(): void
    {
        $transfert = $this->makeTransfertEnTransit(30);
        $this->retourner($transfert, 30);

        $this->get('/backoffice/logistique/transferts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('transferts.0.id', $transfert->id)
                ->where('transferts.0.is_retourne', true)
                ->where('transferts.0.statut_dot_class', 'bg-orange-500')
            );
    }

    // ── Migration de données ──────────────────────────────────────────────────

    public function test_migration_accorde_la_permission_aux_roles_qui_font_avancer_les_transferts(): void
    {
        $avance = Role::create(['name' => 'role_logistique', 'guard_name' => 'web']);
        $avance->givePermissionTo('logistique.update');
        $lecture = Role::create(['name' => 'role_lecture', 'guard_name' => 'web']);
        $lecture->givePermissionTo('logistique.read');

        $migration = $this->migration('2026_10_16_100100_backfill_logistique_enregistrer_retour_permission.php');
        $migration->up();
        $migration->up();

        $this->assertTrue($avance->fresh()->hasPermissionTo('logistique.enregistrer_retour'));
        $this->assertFalse($lecture->fresh()->hasPermissionTo('logistique.enregistrer_retour'));
        $this->assertTrue($avance->fresh()->hasPermissionTo('logistique.update'));
        $this->assertSame(1, Permission::where('name', 'logistique.enregistrer_retour')->count());
        $this->assertSame(1, $avance->fresh()->permissions()->where('name', 'logistique.enregistrer_retour')->count());
    }

    private function migration(string $fichier): Migration
    {
        return require database_path('migrations/'.$fichier);
    }
}
