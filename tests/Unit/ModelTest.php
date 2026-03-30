<?php

namespace Tests\Unit;

use App\Enums\PackingStatut;
use App\Enums\ProduitStatut;
use App\Enums\StatutCommandeAchat;
use App\Enums\StatutCommandeVente;
use App\Enums\TypeVehicule;
use App\Models\CommandeAchat;
use App\Models\CommandeAchatLigne;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\EncaissementVente;
use App\Models\Organization;
use App\Models\Packing;
use App\Models\Parametre;
use App\Models\Prestataire;
use App\Models\Produit;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use App\Models\Vehicule;
use App\Models\Versement;
use App\Models\VersementCommission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModelTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrg(): Organization
    {
        return Organization::factory()->create();
    }

    private function makeUser(Organization $org): User
    {
        return User::factory()->create(['organization_id' => $org->id]);
    }

    private function makePrestataire(Organization $org): Prestataire
    {
        return Prestataire::create([
            'organization_id' => $org->id,
            'nom' => 'PREST'.uniqid(),
            'type' => 'fournisseur',
        ]);
    }

    private function makePacking(Organization $org, array $overrides = []): Packing
    {
        $prestataire = $this->makePrestataire($org);

        return Packing::create(array_merge([
            'organization_id' => $org->id,
            'prestataire_id' => $prestataire->id,
            'date' => now()->toDateString(),
            'nb_rouleaux' => 10,
            'prix_par_rouleau' => 500,
        ], $overrides));
    }

    // ── Prestataire ───────────────────────────────────────────────────────────

    public function test_prestataire_nom_complet_returns_raison_sociale_if_set(): void
    {
        $p = Prestataire::create([
            'organization_id' => $this->makeOrg()->id,
            'raison_sociale' => 'Société ABC',
            'type' => 'fournisseur',
        ]);

        $this->assertSame('Société Abc', $p->nom_complet);
    }

    public function test_prestataire_nom_complet_returns_prenom_nom(): void
    {
        $p = Prestataire::create([
            'organization_id' => $this->makeOrg()->id,
            'nom' => 'DIALLO',
            'prenom' => 'Mamadou',
            'type' => 'fournisseur',
        ]);

        $this->assertNotNull($p->nom_complet);
        $this->assertStringContainsString('DIALLO', $p->nom_complet);
    }

    public function test_prestataire_type_label_returns_string(): void
    {
        $p = Prestataire::create([
            'organization_id' => $this->makeOrg()->id,
            'nom' => 'TEST',
            'type' => 'mecanicien',
        ]);

        $this->assertSame('Mécanicien', $p->type_label);
    }

    public function test_prestataire_scope_actifs(): void
    {
        $org = $this->makeOrg();
        Prestataire::create(['organization_id' => $org->id, 'nom' => 'ACTIF', 'type' => 'fournisseur', 'is_active' => true]);
        Prestataire::create(['organization_id' => $org->id, 'nom' => 'INACTIF', 'type' => 'fournisseur', 'is_active' => false]);

        $actifs = Prestataire::actifs()->where('organization_id', $org->id)->get();
        $this->assertCount(1, $actifs);
        $this->assertSame('ACTIF', $actifs->first()->nom);
    }

    public function test_prestataire_scope_par_type(): void
    {
        $org = $this->makeOrg();
        Prestataire::create(['organization_id' => $org->id, 'nom' => 'FOUR', 'type' => 'fournisseur']);
        Prestataire::create(['organization_id' => $org->id, 'nom' => 'MECA', 'type' => 'mecanicien']);

        $fournisseurs = Prestataire::parType('fournisseur')->where('organization_id', $org->id)->get();
        $this->assertCount(1, $fournisseurs);
        $this->assertSame('FOUR', $fournisseurs->first()->nom);
    }

    public function test_prestataire_scope_par_type_accepts_enum(): void
    {
        $org = $this->makeOrg();
        Prestataire::create(['organization_id' => $org->id, 'nom' => 'CONS', 'type' => 'consultant']);

        $consultants = Prestataire::parType(\App\Enums\PrestataireType::CONSULTANT)
            ->where('organization_id', $org->id)
            ->get();

        $this->assertCount(1, $consultants);
    }

    public function test_prestataire_normalize_email(): void
    {
        $this->assertSame('test@example.com', Prestataire::normalizeEmail('  TEST@EXAMPLE.COM  '));
        $this->assertNull(Prestataire::normalizeEmail(null));
        $this->assertNull(Prestataire::normalizeEmail('   '));
    }

    public function test_prestataire_normalize_iso_country_code(): void
    {
        $this->assertSame('GN', Prestataire::normalizeIsoCountryCode('gn'));
        $this->assertSame('FR', Prestataire::normalizeIsoCountryCode(' FR '));
        $this->assertNull(Prestataire::normalizeIsoCountryCode(null));
        $this->assertNull(Prestataire::normalizeIsoCountryCode('   '));
    }

    public function test_prestataire_normalize_dial_code(): void
    {
        $this->assertSame('+224', Prestataire::normalizeDialCode('+224'));
        $this->assertSame('+224', Prestataire::normalizeDialCode('00224'));
        $this->assertNull(Prestataire::normalizeDialCode(null));
        $this->assertNull(Prestataire::normalizeDialCode(''));
    }

    public function test_prestataire_normalize_phone_e164(): void
    {
        $this->assertSame('+224622000001', Prestataire::normalizePhoneE164('622000001', '+224'));
        $this->assertSame('+224622000001', Prestataire::normalizePhoneE164('+224622000001'));
        $this->assertNull(Prestataire::normalizePhoneE164(null));
        $this->assertNull(Prestataire::normalizePhoneE164(''));
    }

    public function test_prestataire_normalize_phone_with_00_prefix(): void
    {
        $result = Prestataire::normalizePhoneE164('00224622000001');
        $this->assertSame('+224622000001', $result);
    }

    public function test_prestataire_generate_reference_unique(): void
    {
        $ref1 = Prestataire::generateReference();
        $ref2 = Prestataire::generateReference();
        $this->assertStringStartsWith('P', $ref1);
        $this->assertStringStartsWith('P', $ref2);
        $this->assertSame(7, strlen($ref1));
    }

    public function test_prestataire_organization_relation(): void
    {
        $org = $this->makeOrg();
        $p = Prestataire::create(['organization_id' => $org->id, 'nom' => 'TEST', 'type' => 'fournisseur']);

        $this->assertInstanceOf(Organization::class, $p->organization);
        $this->assertEquals($org->id, $p->organization->id);
    }

    // ── Produit ───────────────────────────────────────────────────────────────

    public function test_produit_is_archived_returns_true_when_archived(): void
    {
        $org = $this->makeOrg();
        $p = Produit::create([
            'organization_id' => $org->id,
            'nom' => 'Test',
            'type' => 'materiel',
            'statut' => 'archive',
        ]);

        $this->assertTrue($p->is_archived);
    }

    public function test_produit_in_stock_returns_true_for_service(): void
    {
        $org = $this->makeOrg();
        $p = Produit::create([
            'organization_id' => $org->id,
            'nom' => 'Service test',
            'type' => 'service',
            'statut' => 'actif',
            'qte_stock' => 0,
        ]);

        $this->assertTrue($p->in_stock);
    }

    public function test_produit_in_stock_returns_false_when_zero_stock(): void
    {
        $org = $this->makeOrg();
        $p = Produit::create([
            'organization_id' => $org->id,
            'nom' => 'Materiel test',
            'type' => 'materiel',
            'statut' => 'actif',
            'qte_stock' => 0,
        ]);

        $this->assertFalse($p->in_stock);
    }

    public function test_produit_is_low_stock_returns_false_for_service(): void
    {
        $org = $this->makeOrg();
        $p = Produit::create([
            'organization_id' => $org->id,
            'nom' => 'Service',
            'type' => 'service',
            'statut' => 'actif',
            'qte_stock' => 5,
        ]);

        $this->assertFalse($p->is_low_stock);
    }

    public function test_produit_is_low_stock_returns_false_when_zero_stock(): void
    {
        $org = $this->makeOrg();
        $p = Produit::create([
            'organization_id' => $org->id,
            'nom' => 'Materiel',
            'type' => 'materiel',
            'statut' => 'actif',
            'qte_stock' => 0,
        ]);

        $this->assertFalse($p->is_low_stock);
    }

    public function test_produit_changer_statut_returns_true_on_valid_transition(): void
    {
        $org = $this->makeOrg();
        $p = Produit::create([
            'organization_id' => $org->id,
            'nom' => 'Materiel',
            'type' => 'materiel',
            'statut' => 'actif',
        ]);

        $result = $p->changerStatut(ProduitStatut::INACTIF);
        $this->assertTrue($result);
        $this->assertSame(ProduitStatut::INACTIF, $p->fresh()->statut);
    }

    public function test_produit_changer_statut_returns_false_on_invalid_transition(): void
    {
        $org = $this->makeOrg();
        $p = Produit::create([
            'organization_id' => $org->id,
            'nom' => 'Materiel',
            'type' => 'materiel',
            'statut' => 'actif',
        ]);

        $result = $p->changerStatut(ProduitStatut::ACTIF);
        $this->assertFalse($result);
    }

    public function test_produit_scope_actifs(): void
    {
        $org = $this->makeOrg();
        Produit::create(['organization_id' => $org->id, 'nom' => 'Actif', 'type' => 'materiel', 'statut' => 'actif']);
        Produit::create(['organization_id' => $org->id, 'nom' => 'Inactif', 'type' => 'materiel', 'statut' => 'inactif']);

        $actifs = Produit::actifs()->where('organization_id', $org->id)->get();
        $this->assertCount(1, $actifs);
    }

    public function test_produit_scope_non_archives(): void
    {
        $org = $this->makeOrg();
        Produit::create(['organization_id' => $org->id, 'nom' => 'Normal', 'type' => 'materiel', 'statut' => 'actif']);
        Produit::create(['organization_id' => $org->id, 'nom' => 'Archive', 'type' => 'materiel', 'statut' => 'archive']);

        $nonArchives = Produit::nonArchives()->where('organization_id', $org->id)->get();
        $this->assertCount(1, $nonArchives);
    }

    public function test_produit_updating_to_archive_sets_archived_at(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $this->actingAs($user);

        $p = Produit::create([
            'organization_id' => $org->id,
            'nom' => 'Prod',
            'type' => 'materiel',
            'statut' => 'actif',
        ]);

        $p->update(['statut' => 'archive']);
        $this->assertNotNull($p->fresh()->archived_at);
    }

    public function test_produit_updating_from_archive_clears_archived_at(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $this->actingAs($user);

        $p = Produit::create([
            'organization_id' => $org->id,
            'nom' => 'Prod',
            'type' => 'materiel',
            'statut' => 'archive',
        ]);
        $p->archived_at = now();
        $p->saveQuietly();

        $p->update(['statut' => 'actif']);
        $this->assertNull($p->fresh()->archived_at);
    }

    public function test_produit_deleting_sets_deleted_by(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $this->actingAs($user);

        $p = Produit::create([
            'organization_id' => $org->id,
            'nom' => 'Prod',
            'type' => 'materiel',
            'statut' => 'actif',
        ]);

        $p->delete();
        $deleted = Produit::withTrashed()->find($p->id);
        $this->assertEquals($user->id, $deleted->deleted_by);
    }

    // ── Packing ───────────────────────────────────────────────────────────────

    public function test_packing_peut_etre_modifie_returns_true_when_impayee(): void
    {
        $org = $this->makeOrg();
        $packing = $this->makePacking($org, ['statut' => PackingStatut::IMPAYEE]);

        $this->assertTrue($packing->peutEtreModifie());
    }

    public function test_packing_peut_etre_modifie_returns_false_when_payee(): void
    {
        $org = $this->makeOrg();
        $packing = $this->makePacking($org, ['statut' => PackingStatut::PAYEE]);

        $this->assertFalse($packing->peutEtreModifie());
    }

    public function test_packing_peut_etre_annule_returns_false_when_already_annulee(): void
    {
        $org = $this->makeOrg();
        $packing = $this->makePacking($org, ['statut' => PackingStatut::ANNULEE]);

        $this->assertFalse($packing->peutEtreAnnule());
    }

    public function test_packing_peut_etre_annule_returns_true_when_impayee(): void
    {
        $org = $this->makeOrg();
        $packing = $this->makePacking($org, ['nb_rouleaux' => 5, 'prix_par_rouleau' => 200]);

        $this->assertTrue($packing->peutEtreAnnule());
    }

    public function test_packing_mettre_a_jour_statut_ignores_annulee(): void
    {
        $org = $this->makeOrg();
        $packing = $this->makePacking($org, ['statut' => PackingStatut::ANNULEE]);

        $result = $packing->mettreAJourStatut();
        $this->assertFalse($result);
        $this->assertSame(PackingStatut::ANNULEE, $packing->fresh()->statut);
    }

    public function test_packing_statut_label_accessor(): void
    {
        $org = $this->makeOrg();
        $packing = $this->makePacking($org);

        $this->assertSame('Impayée', $packing->statut_label);
    }

    public function test_packing_montant_restant_is_calculated(): void
    {
        $org = $this->makeOrg();
        $packing = $this->makePacking($org, ['nb_rouleaux' => 10, 'prix_par_rouleau' => 500]);

        $this->assertEquals(5000, $packing->montant_restant);
    }

    public function test_packing_scope_non_annules(): void
    {
        $org = $this->makeOrg();
        $this->makePacking($org, ['statut' => PackingStatut::IMPAYEE]);
        $this->makePacking($org, ['statut' => PackingStatut::ANNULEE]);

        $nonAnnules = Packing::nonAnnules()->where('organization_id', $org->id)->get();
        $this->assertCount(1, $nonAnnules);
    }

    public function test_packing_scope_non_payes(): void
    {
        $org = $this->makeOrg();
        $this->makePacking($org, ['statut' => PackingStatut::IMPAYEE]);
        $this->makePacking($org, ['statut' => PackingStatut::PAYEE]);

        $nonPayes = Packing::nonPayes()->where('organization_id', $org->id)->get();
        $this->assertCount(1, $nonPayes);
    }

    // ── Parametre ─────────────────────────────────────────────────────────────

    public function test_parametre_get_returns_default_when_not_found(): void
    {
        $org = $this->makeOrg();
        $result = Parametre::get($org->id, 'inexistant', 'defaut');
        $this->assertSame('defaut', $result);
    }

    public function test_parametre_get_returns_cast_value(): void
    {
        $org = $this->makeOrg();
        Parametre::create([
            'organization_id' => $org->id,
            'cle' => 'seuil_stock_faible',
            'valeur' => '15',
            'type' => Parametre::TYPE_INTEGER,
            'groupe' => Parametre::GROUPE_GENERAL,
        ]);

        $result = Parametre::get($org->id, 'seuil_stock_faible');
        $this->assertSame(15, $result);
    }

    public function test_parametre_set_updates_value(): void
    {
        $org = $this->makeOrg();
        Parametre::create([
            'organization_id' => $org->id,
            'cle' => 'seuil_stock_faible',
            'valeur' => '10',
            'type' => Parametre::TYPE_INTEGER,
            'groupe' => Parametre::GROUPE_GENERAL,
        ]);

        Parametre::set($org->id, 'seuil_stock_faible', 20);

        $this->assertDatabaseHas('parametres', [
            'organization_id' => $org->id,
            'cle' => 'seuil_stock_faible',
            'valeur' => '20',
        ]);
    }

    public function test_parametre_cast_value_integer(): void
    {
        $this->assertSame(42, Parametre::castValue('42', Parametre::TYPE_INTEGER));
    }

    public function test_parametre_cast_value_boolean_true(): void
    {
        $this->assertTrue(Parametre::castValue('1', Parametre::TYPE_BOOLEAN));
        $this->assertTrue(Parametre::castValue('true', Parametre::TYPE_BOOLEAN));
        $this->assertTrue(Parametre::castValue('yes', Parametre::TYPE_BOOLEAN));
    }

    public function test_parametre_cast_value_boolean_false(): void
    {
        $this->assertFalse(Parametre::castValue('0', Parametre::TYPE_BOOLEAN));
        $this->assertFalse(Parametre::castValue('false', Parametre::TYPE_BOOLEAN));
    }

    public function test_parametre_cast_value_json(): void
    {
        $result = Parametre::castValue('{"key":"value"}', Parametre::TYPE_JSON);
        $this->assertSame(['key' => 'value'], $result);
    }

    public function test_parametre_cast_value_string(): void
    {
        $this->assertSame('hello', Parametre::castValue('hello', Parametre::TYPE_STRING));
    }

    public function test_parametre_cast_value_returns_null_for_null_input(): void
    {
        $this->assertNull(Parametre::castValue(null, Parametre::TYPE_INTEGER));
    }

    public function test_parametre_get_seuil_stock_faible(): void
    {
        $org = $this->makeOrg();
        $result = Parametre::getSeuilStockFaible($org->id);
        $this->assertSame(10, $result);
    }

    public function test_parametre_is_notifications_stock_actives_defaults_true(): void
    {
        $org = $this->makeOrg();
        $result = Parametre::isNotificationsStockActives($org->id);
        $this->assertTrue($result);
    }

    public function test_parametre_get_prix_rouleau_defaut(): void
    {
        $org = $this->makeOrg();
        $result = Parametre::getPrixRouleauDefaut($org->id);
        $this->assertSame(500, $result);
    }

    public function test_parametre_get_produit_rouleau_id_returns_null_by_default(): void
    {
        $org = $this->makeOrg();
        $result = Parametre::getProduitRouleauId($org->id);
        $this->assertNull($result);
    }

    public function test_parametre_clear_cache_runs_without_error(): void
    {
        $org = $this->makeOrg();
        Parametre::clearCache($org->id);
        $this->assertTrue(true);
    }

    // ── CommandeVente ─────────────────────────────────────────────────────────

    public function test_commande_vente_is_annulee_returns_true(): void
    {
        $org = $this->makeOrg();
        $c = CommandeVente::create([
            'organization_id' => $org->id,
            'total_commande' => 1000,
            'statut' => StatutCommandeVente::ANNULEE,
        ]);

        $this->assertTrue($c->isAnnulee());
    }

    public function test_commande_vente_is_annulee_returns_false_when_en_cours(): void
    {
        $org = $this->makeOrg();
        $c = CommandeVente::create([
            'organization_id' => $org->id,
            'total_commande' => 1000,
            'statut' => StatutCommandeVente::EN_COURS,
        ]);

        $this->assertFalse($c->isAnnulee());
    }

    public function test_commande_vente_get_montant_label(): void
    {
        $org = $this->makeOrg();
        $c = CommandeVente::create([
            'organization_id' => $org->id,
            'total_commande' => 50000,
            'statut' => StatutCommandeVente::EN_COURS,
        ]);

        $this->assertStringContainsString('GNF', $c->getMontantLabel());
    }

    public function test_commande_vente_statut_label(): void
    {
        $org = $this->makeOrg();
        $c = CommandeVente::create([
            'organization_id' => $org->id,
            'total_commande' => 1000,
            'statut' => StatutCommandeVente::EN_COURS,
        ]);

        $this->assertSame('En cours', $c->statut_label);
    }

    public function test_commande_vente_cloturer_si_complete_returns_false_when_annulee(): void
    {
        $org = $this->makeOrg();
        $c = CommandeVente::create([
            'organization_id' => $org->id,
            'total_commande' => 1000,
            'statut' => StatutCommandeVente::ANNULEE,
        ]);

        $result = $c->cloturerSiComplete();
        $this->assertFalse($result);
    }

    public function test_commande_vente_cloturer_si_complete_returns_false_without_facture(): void
    {
        $org = $this->makeOrg();
        $c = CommandeVente::create([
            'organization_id' => $org->id,
            'total_commande' => 1000,
            'statut' => StatutCommandeVente::EN_COURS,
        ]);

        $result = $c->cloturerSiComplete();
        $this->assertFalse($result);
    }

    // ── CommandeVenteLigne ────────────────────────────────────────────────────

    public function test_commande_vente_ligne_relations(): void
    {
        $org = $this->makeOrg();
        $commande = CommandeVente::create([
            'organization_id' => $org->id,
            'total_commande' => 2000,
            'statut' => StatutCommandeVente::EN_COURS,
        ]);
        $produit = Produit::create([
            'organization_id' => $org->id,
            'nom' => 'Prod',
            'type' => 'materiel',
            'statut' => 'actif',
        ]);
        $ligne = CommandeVenteLigne::create([
            'commande_vente_id' => $commande->id,
            'produit_id' => $produit->id,
            'qte' => 2,
            'prix_usine_snapshot' => 500,
            'prix_vente_snapshot' => 1000,
            'total_ligne' => 2000,
        ]);

        $this->assertInstanceOf(CommandeVente::class, $ligne->commande);
        $this->assertInstanceOf(Produit::class, $ligne->produit);
    }

    // ── CommandeAchat ─────────────────────────────────────────────────────────

    public function test_commande_achat_is_annulee(): void
    {
        $org = $this->makeOrg();
        $c = CommandeAchat::create([
            'organization_id' => $org->id,
            'total_commande' => 1000,
            'statut' => StatutCommandeAchat::ANNULEE,
        ]);

        $this->assertTrue($c->isAnnulee());
        $this->assertFalse($c->isReceptionnee());
    }

    public function test_commande_achat_is_receptionnee(): void
    {
        $org = $this->makeOrg();
        $c = CommandeAchat::create([
            'organization_id' => $org->id,
            'total_commande' => 1000,
            'statut' => StatutCommandeAchat::RECEPTIONNEE,
        ]);

        $this->assertTrue($c->isReceptionnee());
        $this->assertFalse($c->isAnnulee());
    }

    public function test_commande_achat_statut_label(): void
    {
        $org = $this->makeOrg();
        $c = CommandeAchat::create([
            'organization_id' => $org->id,
            'total_commande' => 1000,
            'statut' => StatutCommandeAchat::EN_COURS,
        ]);

        $this->assertSame('En cours', $c->statut_label);
    }

    // ── CommandeAchatLigne ────────────────────────────────────────────────────

    public function test_commande_achat_ligne_relations(): void
    {
        $org = $this->makeOrg();
        $commande = CommandeAchat::create([
            'organization_id' => $org->id,
            'total_commande' => 1000,
            'statut' => StatutCommandeAchat::EN_COURS,
        ]);
        $produit = Produit::create([
            'organization_id' => $org->id,
            'nom' => 'Prod',
            'type' => 'materiel',
            'statut' => 'actif',
        ]);
        $ligne = CommandeAchatLigne::create([
            'commande_achat_id' => $commande->id,
            'produit_id' => $produit->id,
            'qte' => 5,
            'prix_achat_snapshot' => 200,
            'total_ligne' => 1000,
        ]);

        $this->assertInstanceOf(CommandeAchat::class, $ligne->commande);
        $this->assertInstanceOf(Produit::class, $ligne->produit);
    }

    // ── Site ──────────────────────────────────────────────────────────────────

    public function test_site_is_siege_returns_true(): void
    {
        $org = $this->makeOrg();
        $site = Site::create([
            'organization_id' => $org->id,
            'nom' => 'HQ',
            'type' => 'siege',
            'localisation' => 'Conakry',
        ]);

        $this->assertTrue($site->isSiege());
    }

    public function test_site_is_siege_returns_false_for_depot(): void
    {
        $org = $this->makeOrg();
        $site = Site::create([
            'organization_id' => $org->id,
            'nom' => 'Depot',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);

        $this->assertFalse($site->isSiege());
    }

    public function test_site_is_active_returns_true(): void
    {
        $org = $this->makeOrg();
        $site = Site::create([
            'organization_id' => $org->id,
            'nom' => 'Actif',
            'type' => 'depot',
            'localisation' => 'Conakry',
            'statut' => 'active',
        ]);

        $this->assertTrue($site->isActive());
    }

    public function test_site_is_active_returns_false_when_inactive(): void
    {
        $org = $this->makeOrg();
        $site = Site::create([
            'organization_id' => $org->id,
            'nom' => 'Inactif',
            'type' => 'depot',
            'localisation' => 'Conakry',
            'statut' => 'inactive',
        ]);

        $this->assertFalse($site->isActive());
    }

    public function test_site_scope_actives(): void
    {
        $org = $this->makeOrg();
        Site::create(['organization_id' => $org->id, 'nom' => 'A', 'type' => 'depot', 'localisation' => 'X', 'statut' => 'active']);
        Site::create(['organization_id' => $org->id, 'nom' => 'B', 'type' => 'depot', 'localisation' => 'X', 'statut' => 'inactive']);

        $actives = Site::actives()->where('organization_id', $org->id)->get();
        $this->assertCount(1, $actives);
    }

    public function test_site_scope_du_type(): void
    {
        $org = $this->makeOrg();
        Site::create(['organization_id' => $org->id, 'nom' => 'D', 'type' => 'depot', 'localisation' => 'X']);
        Site::create(['organization_id' => $org->id, 'nom' => 'S', 'type' => 'siege', 'localisation' => 'X']);

        $depots = Site::duType('depot')->where('organization_id', $org->id)->get();
        $this->assertCount(1, $depots);
    }

    // ── Vehicule ──────────────────────────────────────────────────────────────

    public function test_vehicule_photo_url_returns_null_when_no_photo(): void
    {
        $org = $this->makeOrg();
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaire->id,
            'photo_path' => null,
        ]);

        $this->assertNull($vehicule->photo_url);
    }

    public function test_vehicule_photo_url_returns_storage_path(): void
    {
        $org = $this->makeOrg();
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaire->id,
            'photo_path' => 'vehicules/test.webp',
        ]);

        $this->assertSame('/storage/vehicules/test.webp', $vehicule->photo_url);
    }

    public function test_vehicule_type_label(): void
    {
        $org = $this->makeOrg();
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $org->id,
            'proprietaire_id' => $proprietaire->id,
            'type_vehicule' => TypeVehicule::CAMION,
        ]);

        $this->assertSame('Camion', $vehicule->type_label);
    }

    // ── Versement ─────────────────────────────────────────────────────────────

    public function test_versement_packing_relation(): void
    {
        $org = $this->makeOrg();
        $packing = $this->makePacking($org, ['nb_rouleaux' => 5, 'prix_par_rouleau' => 200]);

        $versement = Versement::create([
            'packing_id' => $packing->id,
            'date' => now()->toDateString(),
            'montant' => 500,
        ]);

        $this->assertInstanceOf(Packing::class, $versement->packing);
        $this->assertEquals($packing->id, $versement->packing->id);
    }

    public function test_versement_created_updates_packing_statut(): void
    {
        $org = $this->makeOrg();
        $packing = $this->makePacking($org, ['nb_rouleaux' => 5, 'prix_par_rouleau' => 200]);

        Versement::create([
            'packing_id' => $packing->id,
            'date' => now()->toDateString(),
            'montant' => 1000,
        ]);

        $this->assertSame(PackingStatut::PAYEE, $packing->fresh()->statut);
    }

    // ── VersementCommission ───────────────────────────────────────────────────

    public function test_versement_commission_creator_relation(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $this->actingAs($user);

        $commission = \App\Models\CommissionVente::factory()->create([
            'organization_id' => $org->id,
            'montant_commission' => 5000,
            'montant_part_livreur' => 5000,
            'montant_part_proprietaire' => 0,
        ]);

        $vc = VersementCommission::create([
            'commission_vente_id' => $commission->id,
            'montant' => 1000,
            'beneficiaire' => 'livreur',
            'date_versement' => now()->toDateString(),
            'mode_paiement' => 'especes',
        ]);

        $this->assertInstanceOf(\App\Models\CommissionVente::class, $vc->commission);
    }

    // ── EncaissementVente ─────────────────────────────────────────────────────

    public function test_encaissement_vente_creator_relation(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $this->actingAs($user);

        $site = Site::create(['organization_id' => $org->id, 'nom' => 'S', 'type' => 'depot', 'localisation' => 'X']);
        $commande = CommandeVente::create(['organization_id' => $org->id, 'site_id' => $site->id, 'total_commande' => 2000, 'statut' => StatutCommandeVente::EN_COURS]);

        $facture = \App\Models\FactureVente::create([
            'organization_id' => $org->id,
            'commande_vente_id' => $commande->id,
            'montant_brut' => 2000,
            'montant_net' => 2000,
        ]);

        $encaissement = EncaissementVente::create([
            'facture_vente_id' => $facture->id,
            'montant' => 2000,
            'date_encaissement' => now()->toDateString(),
            'mode_paiement' => 'especes',
        ]);

        $this->assertInstanceOf(\App\Models\FactureVente::class, $encaissement->facture);
        $this->assertInstanceOf(User::class, $encaissement->creator);
    }

    // ── User ──────────────────────────────────────────────────────────────────

    public function test_user_is_super_admin_returns_false_for_regular_user(): void
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $user->assignRole('admin_entreprise');

        $this->assertFalse($user->isSuperAdmin());
    }

    public function test_user_is_super_admin_returns_true_for_super_admin(): void
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $user->assignRole('super_admin');

        $this->assertTrue($user->isSuperAdmin());
    }

    public function test_user_permissions_map_returns_array(): void
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $user->assignRole('admin_entreprise');

        $map = $user->permissionsMap();
        $this->assertIsArray($map);
        $this->assertArrayHasKey('clients.read', $map);
        $this->assertArrayHasKey('produits.create', $map);
    }

    public function test_user_permissions_map_returns_true_for_super_admin(): void
    {
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $org = $this->makeOrg();
        $user = $this->makeUser($org);
        $user->assignRole('super_admin');

        $map = $user->permissionsMap();
        $this->assertTrue($map['clients.read']);
        $this->assertTrue($map['produits.create']);
    }

    public function test_user_name_attribute(): void
    {
        $user = User::factory()->make(['prenom' => 'Mamadou', 'nom' => 'DIALLO']);
        $this->assertSame('Mamadou DIALLO', $user->name);
    }

    public function test_user_organization_relation(): void
    {
        $org = $this->makeOrg();
        $user = $this->makeUser($org);

        $this->assertInstanceOf(Organization::class, $user->organization);
        $this->assertEquals($org->id, $user->organization->id);
    }
}
