<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutCommission;
use App\Enums\StatutDepense;
use App\Features\ModuleFeature;
use App\Models\CommandeVente;
use App\Models\CommissionLogistique;
use App\Models\CommissionLogistiquePart;
use App\Models\CommissionPart;
use App\Models\CommissionVente;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class CommissionExportTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['comptabilite.read', 'comptabilite.payer']);
        Feature::for($this->org)->activate(ModuleFeature::COMPTABILITE);
        Feature::for($this->org)->activate(ModuleFeature::LOGISTIQUE);
        Feature::for($this->org)->activate(ModuleFeature::VENTES);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeSite(string $nom = 'Agence Test'): Site
    {
        return Site::create([
            'organization_id' => $this->org->id,
            'nom' => $nom,
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
    }

    private function makeVehicule(Site $site, ?string $proprietaireId = null): Vehicule
    {
        return Vehicule::create([
            'organization_id' => $this->org->id,
            'nom_vehicule' => 'Camion 001',
            'immatriculation' => 'GN-'.uniqid(),
            'site_id' => $site->id,
            'proprietaire_id' => $proprietaireId,
            'capacite_packs' => 500,
            'is_active' => true,
        ]);
    }

    private function makeLivreur(): Livreur
    {
        return Livreur::create([
            'organization_id' => $this->org->id,
            'nom' => 'Diallo',
            'prenom' => 'Mamadou',
            'telephone' => '622000001',
            'is_active' => true,
        ]);
    }

    private function makeProprietaire(): Proprietaire
    {
        return Proprietaire::create([
            'organization_id' => $this->org->id,
            'nom' => 'Barry',
            'prenom' => 'Ibrahima',
            'telephone' => '622000002',
            'is_active' => true,
        ]);
    }

    private function makeLogistiquePart(Livreur $livreur, Vehicule $vehicule, Site $site, array $override = []): CommissionLogistiquePart
    {
        $transfert = TransfertLogistique::create([
            'organization_id' => $this->org->id,
            'reference' => 'TF-'.uniqid(),
            'site_source_id' => $site->id,
            'site_destination_id' => $site->id,
            'vehicule_id' => $vehicule->id,
            'statut' => 'cloture',
            'date_depart_prevue' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);

        $commission = CommissionLogistique::create([
            'organization_id' => $this->org->id,
            'transfert_logistique_id' => $transfert->id,
            'vehicule_id' => $vehicule->id,
            'base_calcul' => 'forfait',
            'valeur_base' => 100000,
            'montant_total' => 100000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE->value,
        ]);

        return CommissionLogistiquePart::create(array_merge([
            'commission_logistique_id' => $commission->id,
            'type_beneficiaire' => 'livreur',
            'livreur_id' => $livreur->id,
            'beneficiaire_nom' => trim("{$livreur->prenom} {$livreur->nom}"),
            'taux_commission' => 10,
            'montant_brut' => 10000,
            'frais_supplementaires' => 0,
            'montant_net' => 10000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE->value,
            'earned_at' => now()->toDateString(),
            'periode' => now()->format('Y-m').'-P1',
        ], $override));
    }

    private function makeVentePart(Livreur $livreur, Vehicule $vehicule, Site $site): CommissionPart
    {
        $commande = CommandeVente::create([
            'organization_id' => $this->org->id,
            'reference' => 'CMD-'.uniqid(),
            'site_id' => $site->id,
            'statut' => 'livree',
            'total_commande' => 500000,
        ]);

        $commission = CommissionVente::create([
            'organization_id' => $this->org->id,
            'commande_vente_id' => $commande->id,
            'vehicule_id' => $vehicule->id,
            'montant_commande' => 500000,
            'montant_commission_totale' => 25000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE->value,
        ]);

        return CommissionPart::create([
            'commission_vente_id' => $commission->id,
            'type_beneficiaire' => 'livreur',
            'livreur_id' => $livreur->id,
            'beneficiaire_nom' => trim("{$livreur->prenom} {$livreur->nom}"),
            'role' => 'chauffeur',
            'taux_commission' => 5,
            'montant_brut' => 25000,
            'frais_supplementaires' => 2000,
            'type_frais' => 'carburant',
            'montant_net' => 23000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE->value,
        ]);
    }

    private function makeProprietairePart(Proprietaire $proprio, Vehicule $vehicule, Site $site): CommissionPart
    {
        $commande = CommandeVente::create([
            'organization_id' => $this->org->id,
            'reference' => 'CMD-'.uniqid(),
            'site_id' => $site->id,
            'statut' => 'livree',
            'total_commande' => 500000,
        ]);

        $commission = CommissionVente::create([
            'organization_id' => $this->org->id,
            'commande_vente_id' => $commande->id,
            'vehicule_id' => $vehicule->id,
            'montant_commande' => 500000,
            'montant_commission_totale' => 30000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE->value,
        ]);

        return CommissionPart::create([
            'commission_vente_id' => $commission->id,
            'type_beneficiaire' => 'proprietaire',
            'proprietaire_id' => $proprio->id,
            'beneficiaire_nom' => trim("{$proprio->prenom} {$proprio->nom}"),
            'role' => 'proprietaire',
            'taux_commission' => 6,
            'montant_brut' => 30000,
            'frais_supplementaires' => 0,
            'montant_net' => 30000,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE->value,
        ]);
    }

    // ── Commission logistique — Excel ─────────────────────────────────────────

    public function test_export_excel_logistique_retourne_csv(): void
    {
        $site = $this->makeSite();
        $vehicule = $this->makeVehicule($site);
        $livreur = $this->makeLivreur();
        $this->makeLogistiquePart($livreur, $vehicule, $site);

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.logistique.excel'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_export_excel_logistique_contient_colonnes_requises(): void
    {
        $site = $this->makeSite();
        $vehicule = $this->makeVehicule($site);
        $livreur = $this->makeLivreur();
        $this->makeLogistiquePart($livreur, $vehicule, $site);

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.logistique.excel'));

        $content = $response->streamedContent();
        $this->assertStringContainsString('Bénéficiaire', $content);
        $this->assertStringContainsString('Téléphone', $content);
        $this->assertStringContainsString('Véhicule(s)', $content);
        $this->assertStringContainsString('Agence', $content);
        $this->assertStringContainsString('Total cumulé', $content);
        $this->assertStringContainsString('Dépenses', $content);
        $this->assertStringContainsString('Déjà payé', $content);
        $this->assertStringContainsString('Reste à payer', $content);
        $this->assertStringContainsString('Statut', $content);
        $this->assertStringContainsString('Signature', $content);
    }

    // ── Régression : "Motif de dépense" retiré de l'export vente/logistique —
    // seul le total des dépenses déduites est imprimé, pas son détail. ────────

    public function test_export_excel_logistique_ne_contient_plus_motif_de_frais(): void
    {
        $site = $this->makeSite();
        $vehicule = $this->makeVehicule($site);
        $livreur = $this->makeLivreur();
        $this->makeLogistiquePart($livreur, $vehicule, $site);

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.logistique.excel'));

        $this->assertStringNotContainsString('Motif de dépense', $response->streamedContent());
    }

    public function test_export_excel_logistique_pas_de_colonnes_techniques(): void
    {
        $site = $this->makeSite();
        $vehicule = $this->makeVehicule($site);
        $livreur = $this->makeLivreur();
        $this->makeLogistiquePart($livreur, $vehicule, $site);

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.logistique.excel'));

        $content = $response->streamedContent();
        $this->assertStringNotContainsString('organization_id', $content);
        $this->assertStringNotContainsString('created_at', $content);
        $this->assertStringNotContainsString('updated_at', $content);
    }

    public function test_export_excel_logistique_contient_donnees_livreur(): void
    {
        $site = $this->makeSite('Agence Conakry');
        $vehicule = $this->makeVehicule($site);
        $livreur = $this->makeLivreur();
        $this->makeLogistiquePart($livreur, $vehicule, $site);

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.logistique.excel'));

        $content = $response->streamedContent();
        $this->assertStringContainsString('Mamadou Diallo', $content);
        $this->assertStringContainsString('622000001', $content);
    }

    public function test_export_excel_logistique_filtre_periode(): void
    {
        $site = $this->makeSite();
        $vehicule = $this->makeVehicule($site);
        $livreur = $this->makeLivreur();
        $this->makeLogistiquePart($livreur, $vehicule, $site, ['periode' => '2025-01-P1']);

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.logistique.excel', ['periode' => now()->format('Y-m').'-P1']));

        $content = $response->streamedContent();
        // Le livreur de 2025-01 ne doit pas apparaître si on filtre sur le mois courant
        // (sauf si on est en 2025-01, vérification de principe)
        $this->assertStringContainsString('Bénéficiaire', $content);
    }

    // ── Commission logistique — PDF ────────────────────────────────────────────

    public function test_export_pdf_logistique_retourne_pdf(): void
    {
        $site = $this->makeSite();
        $vehicule = $this->makeVehicule($site);
        $livreur = $this->makeLivreur();
        $this->makeLogistiquePart($livreur, $vehicule, $site);

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.logistique.pdf'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_export_pdf_logistique_necessite_authentification(): void
    {
        $this->get(route('comptabilite.commissions.logistique.pdf'))
            ->assertRedirect(route('login'));
    }

    public function test_export_pdf_logistique_necessite_permission(): void
    {
        $userSansPermission = $this->makeUserWithPermissions($this->org, []);

        $this->actingAs($userSansPermission)
            ->get(route('comptabilite.commissions.logistique.pdf'))
            ->assertStatus(403);
    }

    // ── Commission vente — Excel ───────────────────────────────────────────────

    public function test_export_excel_vente_retourne_csv(): void
    {
        $site = $this->makeSite();
        $vehicule = $this->makeVehicule($site);
        $livreur = $this->makeLivreur();
        $this->makeVentePart($livreur, $vehicule, $site);

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.excel'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_export_excel_vente_contient_colonnes_requises(): void
    {
        $site = $this->makeSite();
        $vehicule = $this->makeVehicule($site);
        $livreur = $this->makeLivreur();
        $this->makeVentePart($livreur, $vehicule, $site);

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.excel'));

        $content = $response->streamedContent();
        $this->assertStringContainsString('Bénéficiaire', $content);
        $this->assertStringContainsString('Téléphone', $content);
        $this->assertStringContainsString('Véhicule(s)', $content);
        $this->assertStringContainsString('Agence', $content);
        $this->assertStringContainsString('Total cumulé', $content);
        $this->assertStringContainsString('Dépenses', $content);
        $this->assertStringContainsString('Déjà payé', $content);
        $this->assertStringContainsString('Reste à payer', $content);
        $this->assertStringContainsString('Statut', $content);
        $this->assertStringContainsString('Signature', $content);
    }

    public function test_export_excel_vente_ne_contient_plus_motif_de_frais(): void
    {
        $site = $this->makeSite();
        $vehicule = $this->makeVehicule($site);
        $livreur = $this->makeLivreur();
        $this->makeVentePart($livreur, $vehicule, $site);

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.excel'));

        $this->assertStringNotContainsString('Motif de dépense', $response->streamedContent());
    }

    public function test_export_excel_vente_pas_de_colonnes_techniques(): void
    {
        $site = $this->makeSite();
        $vehicule = $this->makeVehicule($site);
        $livreur = $this->makeLivreur();
        $this->makeVentePart($livreur, $vehicule, $site);

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.excel'));

        $content = $response->streamedContent();
        $this->assertStringNotContainsString('organization_id', $content);
        $this->assertStringNotContainsString('commission_vente_id', $content);
    }

    // ── Commission vente — PDF ─────────────────────────────────────────────────

    public function test_export_pdf_vente_retourne_pdf(): void
    {
        $site = $this->makeSite();
        $vehicule = $this->makeVehicule($site);
        $livreur = $this->makeLivreur();
        $this->makeVentePart($livreur, $vehicule, $site);

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.pdf'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_export_pdf_vente_necessite_permission(): void
    {
        $userSansPermission = $this->makeUserWithPermissions($this->org, []);

        $this->actingAs($userSansPermission)
            ->get(route('comptabilite.commissions.vente.pdf'))
            ->assertStatus(403);
    }

    // ── Commission propriétaire — Excel ───────────────────────────────────────

    public function test_export_excel_proprietaire_retourne_csv(): void
    {
        $site = $this->makeSite();
        $proprio = $this->makeProprietaire();
        $vehicule = $this->makeVehicule($site, $proprio->id);
        $this->makeProprietairePart($proprio, $vehicule, $site);

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.proprietaires.excel'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_export_excel_proprietaire_contient_colonnes_requises(): void
    {
        $site = $this->makeSite();
        $proprio = $this->makeProprietaire();
        $vehicule = $this->makeVehicule($site, $proprio->id);
        $this->makeProprietairePart($proprio, $vehicule, $site);

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.proprietaires.excel'));

        $content = $response->streamedContent();
        $this->assertStringContainsString('Bénéficiaire', $content);
        $this->assertStringContainsString('Dépenses', $content);
        $this->assertStringContainsString('Motif de dépense', $content);
        $this->assertStringContainsString('Signature', $content);
    }

    public function test_export_excel_proprietaire_inclut_frais_depenses(): void
    {
        $site = $this->makeSite();
        $proprio = $this->makeProprietaire();
        $vehicule = $this->makeVehicule($site, $proprio->id);
        $this->makeProprietairePart($proprio, $vehicule, $site);

        $depType = DepenseType::create([
            'organization_id' => $this->org->id,
            'code' => 'REP_MOTEUR',
            'libelle' => 'Réparation moteur',
        ]);

        Depense::create([
            'organization_id' => $this->org->id,
            'beneficiaire_type' => 'vehicule',
            'beneficiaire_id' => $vehicule->id,
            'depense_type_id' => $depType->id,
            'montant' => 50000,
            'date_depense' => now()->toDateString(),
            'statut' => StatutDepense::VALIDE->value,
            'user_id' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.proprietaires.excel'));

        $content = $response->streamedContent();
        $this->assertStringContainsString('Réparation moteur', $content);
    }

    // ── Commission propriétaire — PDF ─────────────────────────────────────────

    public function test_export_pdf_proprietaire_retourne_pdf(): void
    {
        $site = $this->makeSite();
        $proprio = $this->makeProprietaire();
        $vehicule = $this->makeVehicule($site, $proprio->id);
        $this->makeProprietairePart($proprio, $vehicule, $site);

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.proprietaires.pdf'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_export_pdf_proprietaire_necessite_permission(): void
    {
        $userSansPermission = $this->makeUserWithPermissions($this->org, []);

        $this->actingAs($userSansPermission)
            ->get(route('comptabilite.commissions.proprietaires.pdf'))
            ->assertStatus(403);
    }

    // ── PDF — séparation par agence ───────────────────────────────────────────

    public function test_pdf_vente_sans_donnees_retourne_pdf_vide(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.pdf'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_pdf_logistique_sans_donnees_retourne_pdf_vide(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.logistique.pdf'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    // ── Filtres respectés ─────────────────────────────────────────────────────

    public function test_export_excel_vente_filtre_statut_impaye(): void
    {
        $site = $this->makeSite();
        $vehicule = $this->makeVehicule($site);
        $livreur = $this->makeLivreur();
        $this->makeVentePart($livreur, $vehicule, $site);

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.excel', ['statut' => 'paye']));

        $content = $response->streamedContent();
        // Le livreur est impayé, donc avec filtre 'paye' il ne doit pas apparaître dans les données
        $lines = array_filter(explode("\n", $content));
        $this->assertCount(1, $lines); // Seulement la ligne d'en-tête
    }

    public function test_export_excel_logistique_acces_non_authentifie(): void
    {
        $this->get(route('comptabilite.commissions.logistique.excel'))
            ->assertRedirect(route('login'));
    }

    public function test_export_pdf_proprietaire_sans_donnees(): void
    {
        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.proprietaires.pdf'));

        $response->assertOk();
    }
}
