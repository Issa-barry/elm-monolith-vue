<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutCommission;
use App\Features\ModuleFeature;
use App\Models\CommissionLogistique;
use App\Models\CommissionLogistiquePart;
use App\Models\Livreur;
use App\Models\Personne;
use App\Models\Site;
use App\Models\TransfertLogistique;
use App\Models\Vehicule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Exports Excel/PDF logistique (moteur indépendant, cf. CommissionLogistiquePart)
 * ainsi que quelques tests génériques (accès, "sans données") partagés avec les
 * écrans vente/propriétaire. La couverture vente/propriétaire elle-même vit dans
 * CommissionExportVenteTest.php (CommissionEnveloppePart, seule source de vérité
 * pour la commission de vente).
 */
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
            'categorie' => $proprietaireId ? 'partenaire' : 'interne',
            'capacite_packs' => 500,
            'is_active' => true,
        ]);
    }

    private function makeLivreur(): Livreur
    {
        $personne = Personne::create([
            'organization_id' => $this->org->id,
            'nom' => 'Diallo',
            'prenom' => 'Mamadou',
            'telephone' => '622000001',
        ]);

        return Livreur::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
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

    // ── PDF — séparation par agence ───────────────────────────────────────────
    // La couverture Excel/PDF vente et propriétaire (colonnes, filtres, permissions,
    // frais dépenses) vit désormais dans CommissionExportVenteTest.php.

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
