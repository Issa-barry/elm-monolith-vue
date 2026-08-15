<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\EvenementComptable;
use App\Models\CompteMapping;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Models\PaiementFiche;
use App\Models\PaiementPeriode;
use App\Models\PieceComptable;
use App\Models\Proprietaire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Rattrapage historique — cf. Phase 4 de la conception. Simule des données
 * "historiques" en écrivant directement en base (bypass des triggers Eloquent
 * quand ils existent au niveau modèle — EncaissementVente/PaiementFichePaiement
 * comptabilisent déjà à la création, cf. leurs booted() respectifs) : c'est
 * exactement ce que représentent de vraies données créées avant l'introduction
 * du module `compta_*` (12/08/2026).
 */
class ComptabiliteRattrapageCommandTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->org = Organization::factory()->create();
    }

    /** Dépense VALIDE créée en un seul INSERT (factory) : DepenseObserver n'écoute que `updated`, jamais comptabilisée. */
    private function creerDepenseHistorique(float $montant = 25000, string $date = '2026-05-01'): Depense
    {
        $type = DepenseType::factory()->interne()->create(['organization_id' => $this->org->id]);

        return Depense::factory()->create([
            'organization_id' => $this->org->id,
            'depense_type_id' => $type->id,
            'statut' => 'valide',
            'montant' => $montant,
            'date_depense' => $date,
        ]);
    }

    /** Facture déjà IMPAYEE créée hors CommandeVenteService : aucun trigger ne passe par ce chemin en test. */
    private function creerFactureHistorique(float $montant = 100000): FactureVente
    {
        return FactureVente::factory()->create([
            'organization_id' => $this->org->id,
            'montant_brut' => $montant,
            'montant_net' => $montant,
            'statut_facture' => 'impayee',
        ]);
    }

    /** Encaissement inséré en direct (bypass Eloquent) : EncaissementVente::booted() comptabilise dès create(). */
    private function creerEncaissementHistorique(FactureVente $facture, float $montant = 100000): string
    {
        $id = (string) Str::ulid();
        DB::table('encaissements_ventes')->insert([
            'id' => $id,
            'facture_vente_id' => $facture->id,
            'montant' => $montant,
            'date_encaissement' => '2026-05-02',
            'mode_paiement' => 'especes',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /** Fiche déjà en base, période déjà validée : aucun trigger modèle sur PaiementFiche. */
    private function creerFicheHistorique(): PaiementFiche
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $periode = PaiementPeriode::create([
            'organization_id' => $this->org->id,
            'reference' => 'PER-'.uniqid(),
            'type' => 'proprietaire',
            'date_debut' => '2026-05-01',
            'date_fin' => '2026-05-31',
            'statut' => 'validee',
        ]);

        return PaiementFiche::create([
            'organization_id' => $this->org->id,
            'periode_id' => $periode->id,
            'reference' => 'FIC-'.uniqid(),
            'beneficiaire_type' => 'proprietaire',
            'beneficiaire_id' => $proprietaire->id,
            'beneficiaire_nom' => $proprietaire->nom_complet,
            'montant_brut' => 50000,
            'total_deductions' => 0,
            'montant_net' => 50000,
            'montant_paye' => 0,
            'statut' => 'a_payer',
        ]);
    }

    /** Paiement de fiche inséré en direct (bypass Eloquent) : PaiementFichePaiement::booted() comptabilise dès create(). */
    private function creerPaiementFicheHistorique(PaiementFiche $fiche): string
    {
        $id = (string) Str::ulid();
        DB::table('paiement_fiche_paiements')->insert([
            'id' => $id,
            'fiche_id' => $fiche->id,
            'organization_id' => $this->org->id,
            'montant' => 50000,
            'mode_paiement' => 'especes',
            'date_paiement' => '2026-06-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    // ── Dry-run n'écrit rien ─────────────────────────────────────────────────

    public function test_dry_run_necrit_aucune_piece(): void
    {
        $this->creerDepenseHistorique();
        $facture = $this->creerFactureHistorique();
        $this->creerEncaissementHistorique($facture);
        $fiche = $this->creerFicheHistorique();
        $this->creerPaiementFicheHistorique($fiche);

        // L'encaissement et le paiement de fiche ont déjà été comptabilisés à leur
        // création (triggers modèle) : seuls la dépense/facture/fiche restent à traiter.
        $avant = PieceComptable::count();

        $this->artisan('comptabilite:rattraper', ['--organization' => [$this->org->id], '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertSame($avant, PieceComptable::count(), 'Le dry-run ne doit jamais écrire en base.');
    }

    // ── Exécution réelle + idempotence ──────────────────────────────────────

    public function test_execution_reelle_comptabilise_toutes_les_donnees_historiques_eligibles(): void
    {
        $depense = $this->creerDepenseHistorique();
        $facture = $this->creerFactureHistorique();
        $fiche = $this->creerFicheHistorique();

        $this->artisan('comptabilite:rattraper', ['--organization' => [$this->org->id]])
            ->assertExitCode(0);

        $this->assertDatabaseHas('compta_pieces', [
            'source_type' => Depense::class,
            'source_id' => $depense->id,
        ]);
        $this->assertDatabaseHas('compta_pieces', [
            'source_type' => FactureVente::class,
            'source_id' => $facture->id,
            'type_evenement' => EvenementComptable::VENTE_FACTUREE->value,
        ]);
        $this->assertDatabaseHas('compta_pieces', [
            'source_type' => PaiementFiche::class,
            'source_id' => $fiche->id,
            'type_evenement' => EvenementComptable::FICHE_PROPRIETAIRE_VALIDEE->value,
        ]);
    }

    public function test_deuxieme_execution_ne_cree_aucun_doublon(): void
    {
        $this->creerDepenseHistorique();
        $this->creerFactureHistorique();
        $this->creerFicheHistorique();

        $this->artisan('comptabilite:rattraper', ['--organization' => [$this->org->id]])->assertExitCode(0);
        $apresPremiereFois = PieceComptable::count();

        $this->artisan('comptabilite:rattraper', ['--organization' => [$this->org->id]])->assertExitCode(0);

        $this->assertSame($apresPremiereFois, PieceComptable::count(), 'Une deuxième exécution ne doit créer aucune pièce supplémentaire.');
    }

    public function test_dates_historiques_reelles_sont_preservees_pas_la_date_du_rattrapage(): void
    {
        $depense = $this->creerDepenseHistorique(25000, '2026-03-15');

        $this->artisan('comptabilite:rattraper', ['--organization' => [$this->org->id]])->assertExitCode(0);

        $piece = PieceComptable::where('source_type', Depense::class)->where('source_id', $depense->id)->first();
        $this->assertSame('2026-03-15', $piece->date_piece->format('Y-m-d'));
    }

    // ── Filtres ──────────────────────────────────────────────────────────────

    public function test_filtre_type_limite_le_perimetre_traite(): void
    {
        $this->creerDepenseHistorique();
        $this->creerFactureHistorique();

        $this->artisan('comptabilite:rattraper', ['--organization' => [$this->org->id], '--type' => ['depense']])
            ->assertExitCode(0);

        $this->assertDatabaseHas('compta_pieces', ['source_type' => Depense::class]);
        $this->assertDatabaseMissing('compta_pieces', ['source_type' => FactureVente::class]);
    }

    public function test_filtre_organisation_isole_les_organisations(): void
    {
        $orgB = Organization::factory()->create();
        $depenseA = $this->creerDepenseHistorique();

        $typeB = DepenseType::factory()->interne()->create(['organization_id' => $orgB->id]);
        $depenseB = Depense::factory()->create([
            'organization_id' => $orgB->id,
            'depense_type_id' => $typeB->id,
            'statut' => 'valide',
            'montant' => 10000,
        ]);

        $this->artisan('comptabilite:rattraper', ['--organization' => [$this->org->id]])->assertExitCode(0);

        $this->assertDatabaseHas('compta_pieces', ['source_type' => Depense::class, 'source_id' => $depenseA->id]);
        $this->assertDatabaseMissing('compta_pieces', ['source_type' => Depense::class, 'source_id' => $depenseB->id]);
    }

    public function test_filtre_dates_exclut_les_enregistrements_hors_plage(): void
    {
        $dedans = $this->creerDepenseHistorique(10000, '2026-05-15');
        $dehors = $this->creerDepenseHistorique(10000, '2026-01-01');

        $this->artisan('comptabilite:rattraper', [
            '--organization' => [$this->org->id],
            '--depuis' => '2026-05-01',
            '--jusqua' => '2026-05-31',
        ])->assertExitCode(0);

        $this->assertDatabaseHas('compta_pieces', ['source_type' => Depense::class, 'source_id' => $dedans->id]);
        $this->assertDatabaseMissing('compta_pieces', ['source_type' => Depense::class, 'source_id' => $dehors->id]);
    }

    public function test_categorie_employe_reste_hors_perimetre(): void
    {
        $typeSalaire = DepenseType::factory()->employe()->create(['organization_id' => $this->org->id]);
        $depenseSalaire = Depense::factory()->create([
            'organization_id' => $this->org->id,
            'depense_type_id' => $typeSalaire->id,
            'beneficiaire_type' => 'employe',
            'statut' => 'valide',
            'montant' => 500000,
        ]);

        $this->artisan('comptabilite:rattraper', ['--organization' => [$this->org->id]])->assertExitCode(0);

        $this->assertDatabaseMissing('compta_pieces', ['source_type' => Depense::class, 'source_id' => $depenseSalaire->id]);
    }

    // ── Anomalies ────────────────────────────────────────────────────────────

    public function test_mapping_manquant_est_remonte_comme_anomalie_et_echoue(): void
    {
        $this->creerDepenseHistorique();

        // Désactive le mapping nécessaire à la dépense interne : simule un plan
        // comptable incomplet (le vrai scénario du ticket d'origine).
        CompteMapping::where('organization_id', $this->org->id)
            ->where('evenement', EvenementComptable::DEPENSE_INTERNE_VALIDEE->value)
            ->update(['actif' => false]);

        $this->artisan('comptabilite:rattraper', ['--organization' => [$this->org->id]])
            ->assertExitCode(1);

        $this->assertDatabaseMissing('compta_pieces', ['source_type' => Depense::class]);
    }

    public function test_type_invalide_echoue_proprement(): void
    {
        $this->artisan('comptabilite:rattraper', ['--organization' => [$this->org->id], '--type' => ['inconnu']])
            ->assertExitCode(1);
    }

    public function test_organisation_introuvable_echoue_proprement(): void
    {
        $this->artisan('comptabilite:rattraper', ['--organization' => ['org-inexistante']])
            ->assertExitCode(1);
    }
}
