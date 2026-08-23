<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionMode;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\CommissionUniteCalcul;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutCommission;
use App\Enums\StatutDepense;
use App\Enums\TypePeriodePaiement;
use App\Models\Categorie;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use App\Services\Commission\CommissionEnveloppeGenerator;
use App\Services\CommissionAdjustmentService;
use App\Services\PeriodeCalculatorService;
use App\Services\PeriodePaiementService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Exports Excel/PDF vente et propriétaire, sur CommissionEnveloppePart — seule
 * source de vérité pour la commission de vente. Les exports logistique restent
 * couverts séparément par CommissionExportTest.php (moteur indépendant, non
 * concerné par ce fichier).
 *
 * Les exports reprennent les mêmes bénéficiaires et les mêmes statuts que
 * l'écran Commission vente. Une part CREEE doit donc être exportée comme
 * « Partage à valider », au même titre que les parts validées ou payées.
 */
class CommissionExportVenteTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private Site $defaultSite;

    private CommissionProcessus $processus;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read', 'ventes.create', 'ventes.update', 'comptabilite.read', 'comptabilite.payer']);

        $this->defaultSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->defaultSite->id, ['role' => 'employe', 'is_default' => true]);

        $this->processus = CommissionProcessus::create([
            'organization_id' => $this->org->id,
            'code' => CommissionProcessus::CODE_VENTE,
            'libelle' => 'Vente',
            'declencheur' => 'facture_encaissee',
            'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);
    }

    /** @return array{vehicule: Vehicule, equipe: EquipeLivraison, livreur: Livreur, proprietaire: Proprietaire} */
    private function makeVehiculeAvecEquipe(): array
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
            'capacite_packs' => 100,
        ]);

        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'is_active' => true,
        ]);

        $livreur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $livreur->id, 'role' => 'chauffeur', 'ordre' => 0]);

        return ['vehicule' => $vehicule->fresh(), 'equipe' => $equipe, 'livreur' => $livreur, 'proprietaire' => $proprietaire];
    }

    private function creerCommandeEtGenererCommission(Vehicule $vehicule, EquipeLivraison $equipe, Livreur $livreur): CommandeVente
    {
        $categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachets', 'statut' => 'actif']);
        EquipeLivraisonPartageCategorie::create([
            'equipe_id' => $equipe->id,
            'categorie_id' => $categorie->id,
            'livreur_id' => $livreur->id,
            'part_pourcentage' => 0,
            'montant_unitaire' => 1000,
            'effective_from' => now()->subDay(),
        ]);

        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $this->processus->id,
            'libelle' => 'Propriétaire',
            'scope_type' => CommissionScopeType::CATEGORIE->value,
            'scope_id' => $categorie->id,
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
            'mode' => CommissionMode::DIRECT->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 500,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);
        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $this->processus->id,
            'libelle' => 'Livraison',
            'scope_type' => CommissionScopeType::CATEGORIE->value,
            'scope_id' => $categorie->id,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'mode' => CommissionMode::A_REPARTIR->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 1000,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);

        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Produit Test', 'categorie_id' => $categorie->id],
            ['prix_vente' => 2000, 'prix_usine' => 1500],
        );

        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite->id,
            'vehicule_id' => $vehicule->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 20000,
        ]);

        $variante = $produit->variantePrincipale()->first();
        $ligne = $commande->lignes()->create([
            'variante_id' => $variante->id,
            'quantite_demandee' => 10,
            'prix_usine_snapshot' => (float) $variante->prix_usine,
            'prix_vente_snapshot' => (float) $variante->prix_vente,
            'total_ligne' => 10 * (float) $variante->prix_vente,
        ]);

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande);
        CommandeVenteService::validerChargement($commande, [
            ['id' => $ligne->id, 'quantite_chargee' => 10, 'type_ecart' => 'conforme'],
        ]);

        $commande = $commande->fresh();
        CommissionEnveloppeGenerator::genererPourCommandeVente($commande);

        return $commande;
    }

    /** Génère une vente puis calcule + valide sa période, pour sortir du statut CREEE. */
    private function genererEtActiver(): void
    {
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreur' => $livreur] = $this->makeVehiculeAvecEquipe();
        $this->creerCommandeEtGenererCommission($vehicule, $equipe, $livreur);

        $periode = app(PeriodePaiementService::class)->getOrCreatePeriod(
            $this->org->id,
            TypePeriodePaiement::LIVREUR,
            now(),
            $this->user->id,
        );
        app(PeriodeCalculatorService::class)->calculer($periode);
        CommissionAdjustmentService::validerLot(CommissionAdjustmentService::partsPourPeriode($periode), $this->user);
        $this->actingAs($this->user)->post(route('comptabilite.periodes.valider', $periode))->assertSessionHas('success');
    }

    /** @test */
    public function export_excel_vente_retourne_csv_avec_les_colonnes_requises(): void
    {
        $this->genererEtActiver();

        $response = $this->actingAs($this->user)->get(route('comptabilite.commissions.vente.excel'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        foreach (['Bénéficiaire', 'Téléphone', 'Véhicule(s)', 'Agence', 'Généré', 'Brut validé', 'Dépenses', 'Net validé', 'Déjà payé', 'Reste à payer', 'Statut', 'Signature'] as $colonne) {
            $this->assertStringContainsString($colonne, $content);
        }
        $this->assertStringContainsString('10 000', $content);
    }

    /** @test */
    public function export_excel_vente_ne_contient_plus_motif_de_frais(): void
    {
        $this->genererEtActiver();

        $content = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.excel'))
            ->streamedContent();

        $this->assertStringNotContainsString('Motif de dépense', $content);
    }

    /** @test */
    public function export_excel_vente_pas_de_colonnes_techniques(): void
    {
        $this->genererEtActiver();

        $content = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.excel'))
            ->streamedContent();

        $this->assertStringNotContainsString('organization_id', $content);
        $this->assertStringNotContainsString('enveloppe_id', $content);
    }

    /** @test */
    public function export_excel_vente_filtre_statut_impaye(): void
    {
        $this->genererEtActiver();

        $content = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.excel', ['statut' => 'paye']))
            ->streamedContent();

        // La commission générée est impayée : avec le filtre 'paye', seule la ligne d'en-tête reste.
        $lines = array_filter(explode("\n", $content));
        $this->assertCount(1, $lines);
    }

    /** @test */
    public function export_excel_vente_inclut_les_parts_encore_creee(): void
    {
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreur' => $livreur] = $this->makeVehiculeAvecEquipe();
        $this->creerCommandeEtGenererCommission($vehicule, $equipe, $livreur);

        $content = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.excel'))
            ->streamedContent();

        $this->assertStringContainsString($livreur->nom_complet, $content);
        $this->assertStringContainsString('10 000', $content);
        $this->assertStringContainsString('Partage à valider', $content);
    }

    /** @test */
    public function export_excel_vente_respecte_tous_les_filtres_de_statut_affiches(): void
    {
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreur' => $livreur] = $this->makeVehiculeAvecEquipe();
        $this->creerCommandeEtGenererCommission($vehicule, $equipe, $livreur);
        $part = CommissionEnveloppePart::query()
            ->where('beneficiaire_type', CommissionEnveloppePart::TYPE_LIVREUR)
            ->where('beneficiaire_id', $livreur->id)
            ->firstOrFail();

        $montantsVerses = [
            StatutCommission::CREEE->value => 0,
            StatutCommission::IMPAYE->value => 0,
            StatutCommission::PARTIEL->value => 5000,
            StatutCommission::PAYE->value => 10000,
        ];

        foreach ([StatutCommission::CREEE, StatutCommission::IMPAYE, StatutCommission::PARTIEL, StatutCommission::PAYE] as $statut) {
            $part->update([
                'statut' => $statut->value,
                'montant_verse' => $montantsVerses[$statut->value],
            ]);

            $content = $this->actingAs($this->user)
                ->get(route('comptabilite.commissions.vente.excel', ['statut' => $statut->value]))
                ->streamedContent();

            $this->assertStringContainsString(
                $livreur->nom_complet,
                $content,
                "Le statut {$statut->value} affiché doit aussi être exporté.",
            );
        }
    }

    /** @test */
    public function export_pdf_vente_retourne_pdf(): void
    {
        $this->genererEtActiver();

        $response = $this->actingAs($this->user)->get(route('comptabilite.commissions.vente.pdf'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    /** @test */
    public function export_pdf_vente_recoit_les_parts_encore_creee_affichees(): void
    {
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreur' => $livreur] = $this->makeVehiculeAvecEquipe();
        $this->creerCommandeEtGenererCommission($vehicule, $equipe, $livreur);

        $document = Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $document->shouldReceive('setPaper')->once()->with('a4', 'landscape')->andReturnSelf();
        $document->shouldReceive('download')->once()->andReturn(
            response('pdf-test', 200, ['Content-Type' => 'application/pdf'])
        );

        Pdf::shouldReceive('loadView')
            ->once()
            ->with('pdf.commissions.index', Mockery::on(function (array $data) use ($livreur): bool {
                $rows = collect($data['sites'])->flatMap(fn (array $site) => $site['rows']);
                $row = $rows->firstWhere('beneficiaire_id', $livreur->id);

                $this->assertNotNull($row);
                $this->assertSame('Partage à valider', $row['statut']);
                $this->assertSame(10000.0, $row['total_genere']);
                $this->assertSame(0.0, $row['total_cumule']);
                $this->assertTrue($data['show_validation_columns']);

                $html = view('pdf.commissions.index', $data)->render();

                $this->assertStringNotContainsString(
                    '<th class="col-tel center">Téléphone</th>',
                    $html,
                );
                $this->assertStringContainsString('class="ben-phone"', $html);
                $this->assertStringContainsString((string) $livreur->telephone, $html);

                return true;
            }))
            ->andReturn($document);

        $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.vente.pdf'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    /** @test */
    public function export_pdf_vente_necessite_permission(): void
    {
        $this->genererEtActiver();
        $userSansPermission = $this->makeUserWithPermissions($this->org, []);

        $this->actingAs($userSansPermission)
            ->get(route('comptabilite.commissions.vente.pdf'))
            ->assertStatus(403);
    }

    // ── Commission propriétaire ──────────────────────────────────────────────

    /** @test */
    public function export_excel_proprietaire_retourne_csv_avec_les_colonnes_requises(): void
    {
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreur' => $livreur] = $this->makeVehiculeAvecEquipe();
        $this->creerCommandeEtGenererCommission($vehicule, $equipe, $livreur);

        $response = $this->actingAs($this->user)->get(route('comptabilite.commissions.proprietaires.excel'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();
        foreach (['Bénéficiaire', 'Téléphone', 'Véhicule(s)', 'Agence', 'Généré', 'Brut validé', 'Dépenses', 'Net validé', 'Déjà payé', 'Reste à payer', 'Statut', 'Signature'] as $colonne) {
            $this->assertStringContainsString($colonne, $content);
        }
        $this->assertStringContainsString('5 000', $content);
        $this->assertStringContainsString('Partage à valider', $content);
    }

    /** @test */
    public function export_excel_proprietaire_inclut_frais_depenses(): void
    {
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreur' => $livreur] = $this->makeVehiculeAvecEquipe();
        $this->creerCommandeEtGenererCommission($vehicule, $equipe, $livreur);

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

        $content = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.proprietaires.excel'))
            ->streamedContent();

        $this->assertStringContainsString('50 000', $content);
    }

    /** @test */
    public function export_excel_proprietaire_respecte_les_filtres_agence_et_statut_du_drawer(): void
    {
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreur' => $livreur, 'proprietaire' => $proprietaire] = $this->makeVehiculeAvecEquipe();
        $this->creerCommandeEtGenererCommission($vehicule, $equipe, $livreur);

        $autreSite = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Autre site',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);

        $creees = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.proprietaires.excel', [
                'statut' => ['creee'],
                'site_ids' => [$this->defaultSite->id],
            ]))
            ->streamedContent();

        $this->assertStringContainsString($proprietaire->nom_complet, $creees);
        $this->assertStringContainsString('Partage à valider', $creees);

        $autreAgence = $this->actingAs($this->user)
            ->get(route('comptabilite.commissions.proprietaires.excel', [
                'statut' => ['creee'],
                'site_ids' => [$autreSite->id],
            ]))
            ->streamedContent();

        $this->assertStringNotContainsString($proprietaire->nom_complet, $autreAgence);
    }

    /** @test */
    public function export_excel_proprietaire_necessite_permission(): void
    {
        $userSansPermission = $this->makeUserWithPermissions($this->org, []);

        $this->actingAs($userSansPermission)
            ->get(route('comptabilite.commissions.proprietaires.excel'))
            ->assertStatus(403);
    }

    /** @test */
    public function export_pdf_proprietaire_retourne_pdf(): void
    {
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreur' => $livreur] = $this->makeVehiculeAvecEquipe();
        $this->creerCommandeEtGenererCommission($vehicule, $equipe, $livreur);

        $response = $this->actingAs($this->user)->get(route('comptabilite.commissions.proprietaires.pdf'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    /** @test */
    public function export_pdf_proprietaire_necessite_permission(): void
    {
        ['vehicule' => $vehicule, 'equipe' => $equipe, 'livreur' => $livreur] = $this->makeVehiculeAvecEquipe();
        $this->creerCommandeEtGenererCommission($vehicule, $equipe, $livreur);
        $userSansPermission = $this->makeUserWithPermissions($this->org, []);

        $this->actingAs($userSansPermission)
            ->get(route('comptabilite.commissions.proprietaires.pdf'))
            ->assertStatus(403);
    }
}
