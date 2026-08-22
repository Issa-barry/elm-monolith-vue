<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\StatutCommission;
use App\Enums\StatutDepense;
use App\Enums\StatutPeriodePaiement;
use App\Enums\TypePeriodePaiement;
use App\Features\ModuleFeature;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CommissionCibleType;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\Livreur;
use App\Models\PaiementFiche;
use App\Models\PaiementPeriode;
use App\Models\Personne;
use App\Models\Site;
use App\Models\User;
use App\Services\PeriodeCalculatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

class PaiementPeriodeTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private ?CommissionProcessus $processusVente = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['comptabilite.read', 'comptabilite.manage', 'comptabilite.payer']);
        Feature::for($this->org)->activate(ModuleFeature::COMPTABILITE);
    }

    private function defaultSite(): Site
    {
        return $this->user->sites()->wherePivot('is_default', true)->first();
    }

    private function makePeriode(array $override = []): PaiementPeriode
    {
        return PaiementPeriode::create(array_merge([
            'organization_id' => $this->org->id,
            'reference' => 'PAY-202606-0001',
            'type' => TypePeriodePaiement::LIVREUR->value,
            'date_debut' => '2026-06-01',
            'date_fin' => '2026-06-15',
            'statut' => StatutPeriodePaiement::BROUILLON->value,
            'created_by' => $this->user->id,
        ], $override));
    }

    /** Commission vente livreur (une seule cible, sans équipe réelle) : enveloppe + part IMPAYE. */
    private function makeEnveloppeAvecPart(float $montantNet, ?string $vehiculeId, Livreur $livreur): CommissionEnveloppePart
    {
        $commande = $this->makeCommande();
        if ($vehiculeId) {
            $commande->update(['vehicule_id' => $vehiculeId]);
        }

        $this->processusVente ??= CommissionProcessus::create([
            'organization_id' => $this->org->id,
            'code' => CommissionProcessus::CODE_VENTE,
            'libelle' => 'Vente',
            'declencheur' => 'chargement_valide',
            'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);

        $enveloppe = CommissionEnveloppe::create([
            'organization_id' => $this->org->id,
            'source_type' => CommandeVente::class,
            'source_id' => $commande->id,
            'processus_id' => $this->processusVente->id,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'cible_id' => (string) Str::ulid(),
            'montant_total' => $montantNet,
            'earned_at' => now(),
            'statut' => StatutCommission::IMPAYE->value,
        ]);

        return CommissionEnveloppePart::create([
            'enveloppe_id' => $enveloppe->id,
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_LIVREUR,
            'beneficiaire_id' => $livreur->id,
            'montant_brut' => $montantNet,
            'montant_net' => $montantNet,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE->value,
        ]);
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_index_retourne_200(): void
    {
        $this->actingAs($this->user)
            ->get(route('comptabilite.periodes.index'))
            ->assertStatus(200);
    }

    public function test_index_redirige_non_authentifie(): void
    {
        $this->get(route('comptabilite.periodes.index'))
            ->assertRedirect(route('login'));
    }

    // ── génération automatique ────────────────────────────────────────────────

    public function test_index_cree_automatiquement_la_periode_courante_de_chaque_type(): void
    {
        $this->travelTo('2026-07-10 12:00:00');

        $response = $this->actingAs($this->user)->get(route('comptabilite.periodes.index'));
        $response->assertStatus(200);

        $response->assertInertia(fn (Assert $page) => $page
            ->where('cycle.periode_courante_label', 'Juillet 2026 - P1')
            ->has('cycle.par_type', 4) // livreur + propriétaire + salarié + site
        );

        $this->assertDatabaseHas('paiement_periodes', [
            'organization_id' => $this->org->id,
            'type' => 'livreur',
            'reference' => 'PAY-202607-P1-LIV',
        ]);
        $this->assertDatabaseHas('paiement_periodes', [
            'organization_id' => $this->org->id,
            'type' => 'proprietaire',
            'reference' => 'PAY-202607-P1-PRO',
        ]);
        $this->assertDatabaseHas('paiement_periodes', [
            'organization_id' => $this->org->id,
            'type' => 'salarie',
            'reference' => 'PAY-202607-P1-SAL',
        ]);
    }

    public function test_voir_periode_inexistante_la_cree_puis_redirige(): void
    {
        $this->assertDatabaseMissing('paiement_periodes', [
            'organization_id' => $this->org->id,
            'reference' => 'PAY-202608-P2-LIV',
        ]);

        $response = $this->actingAs($this->user)
            ->get(route('comptabilite.periodes.voir', ['type' => 'livreur', 'annee' => 2026, 'mois' => 8, 'quinzaine' => 'P2']));

        $periode = PaiementPeriode::where('organization_id', $this->org->id)
            ->where('reference', 'PAY-202608-P2-LIV')
            ->first();

        $this->assertNotNull($periode);
        $this->assertSame('2026-08-16', $periode->date_debut->toDateString());
        $this->assertSame('2026-08-31', $periode->date_fin->toDateString());
        $response->assertRedirect(route('comptabilite.periodes.show', $periode));
    }

    public function test_voir_periode_existante_ne_duplique_pas(): void
    {
        $this->actingAs($this->user)
            ->get(route('comptabilite.periodes.voir', ['type' => 'livreur', 'annee' => 2026, 'mois' => 8, 'quinzaine' => 'P2']));
        $this->actingAs($this->user)
            ->get(route('comptabilite.periodes.voir', ['type' => 'livreur', 'annee' => 2026, 'mois' => 8, 'quinzaine' => 'P2']));

        $this->assertSame(
            1,
            PaiementPeriode::where('organization_id', $this->org->id)
                ->where('reference', 'PAY-202608-P2-LIV')
                ->count(),
        );
    }

    // ── calculer ──────────────────────────────────────────────────────────────

    public function test_calcul_genere_fiches_pour_livreurs_avec_commissions(): void
    {
        $this->travelTo('2026-06-10 12:00:00');

        $site = $this->defaultSite();
        $personne = Personne::create([
            'organization_id' => $this->org->id,
            'nom' => 'Diallo',
            'prenom' => 'Mamadou',
            'telephone' => '+224'.fake()->unique()->numerify('#########'),
        ]);

        $livreur = Livreur::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'nom_complet' => 'Mamadou Diallo',
            'is_active' => true,
        ]);

        $this->makeEnveloppeAvecPart(300000, null, $livreur);

        $periode = $this->makePeriode();

        $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.calculer', $periode))
            ->assertRedirect();

        $this->assertDatabaseHas('paiement_fiches', [
            'periode_id' => $periode->id,
            'beneficiaire_type' => 'livreur',
            'beneficiaire_id' => $livreur->id,
        ]);

        $fiche = PaiementFiche::where('periode_id', $periode->id)->first();
        $this->assertNotNull($fiche);
        $this->assertGreaterThan(0, (float) $fiche->montant_net);

        $periode->refresh();
        $this->assertSame(StatutPeriodePaiement::CALCULEE->value, $periode->statut->value);
    }

    public function test_recalculer_une_periode_deja_calculee_ne_plante_pas(): void
    {
        $this->travelTo('2026-06-10 12:00:00');

        $personne = Personne::create([
            'organization_id' => $this->org->id,
            'nom' => 'Diallo',
            'prenom' => 'Mamadou',
            'telephone' => '+224'.fake()->unique()->numerify('#########'),
        ]);

        $livreur = Livreur::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'nom_complet' => 'Mamadou Diallo',
            'is_active' => true,
        ]);

        $this->makeEnveloppeAvecPart(300000, null, $livreur);

        $periode = $this->makePeriode();

        // Premier calcul : crée les fiches (bouton "Générer les fiches").
        $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.calculer', $periode))
            ->assertRedirect();

        // Deuxième calcul (bouton "Mettre à jour les fiches") : ne doit pas planter
        // sur une violation de contrainte d'unicité periode/bénéficiaire (SoftDeletes
        // laissait la ligne physique en place avant la correction de forceDelete()).
        $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.calculer', $periode))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(1, PaiementFiche::where('periode_id', $periode->id)->count());
    }

    public function test_calcul_exclut_depenses_non_validees(): void
    {
        $this->travelTo('2026-06-10 12:00:00');

        $site = $this->defaultSite();
        $personne = Personne::create([
            'organization_id' => $this->org->id,
            'nom' => 'Barry',
            'prenom' => 'Ibrahima',
            'telephone' => '+224'.fake()->unique()->numerify('#########'),
        ]);

        $livreur = Livreur::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'nom_complet' => 'Ibrahima Barry',
            'is_active' => true,
        ]);

        $this->makeEnveloppeAvecPart(100000, null, $livreur);

        $depType = DepenseType::create([
            'organization_id' => $this->org->id,
            'code' => 'FUEL',
            'libelle' => 'Carburant',
            'categorie' => 'interne',
            'commentaire_obligatoire' => false,
            'justificatif_obligatoire' => false,
            'is_active' => true,
        ]);

        Depense::create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $depType->id,
            'beneficiaire_type' => 'livreur',
            'beneficiaire_id' => $livreur->id,
            'montant' => 20000,
            'date_depense' => '2026-06-05',
            'statut' => StatutDepense::SOUMIS->value,
        ]);

        $periode = $this->makePeriode();

        $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.calculer', $periode));

        $fiche = PaiementFiche::where('periode_id', $periode->id)
            ->where('beneficiaire_id', $livreur->id)
            ->first();

        if ($fiche) {
            $this->assertSame(0.0, (float) $fiche->total_deductions);
        }
    }

    public function test_periode_validee_ne_peut_pas_etre_recalculee(): void
    {
        $periode = $this->makePeriode([
            'statut' => StatutPeriodePaiement::VALIDEE->value,
        ]);

        $this->expectException(\LogicException::class);

        app(PeriodeCalculatorService::class)->calculer($periode);
    }

    // ── auto-calcul à l'ouverture de la page détail ──────────────────────────────

    public function test_ouverture_de_la_periode_genere_automatiquement_les_fiches(): void
    {
        $this->travelTo('2026-06-10 12:00:00');

        $personne = Personne::create([
            'organization_id' => $this->org->id,
            'nom' => 'Diallo',
            'prenom' => 'Mamadou',
            'telephone' => '+224'.fake()->unique()->numerify('#########'),
        ]);

        $livreur = Livreur::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'nom_complet' => 'Mamadou Diallo',
            'is_active' => true,
        ]);

        $this->makeEnveloppeAvecPart(300000, null, $livreur);

        $periode = $this->makePeriode();
        $this->assertSame(0, PaiementFiche::where('periode_id', $periode->id)->count());

        // Aucun clic sur "Générer les fiches" : la simple ouverture de la page détail doit
        // déclencher le calcul toute seule.
        $response = $this->actingAs($this->user)->get(route('comptabilite.periodes.show', $periode));
        $response->assertStatus(200);

        $this->assertDatabaseHas('paiement_fiches', [
            'periode_id' => $periode->id,
            'beneficiaire_type' => 'livreur',
            'beneficiaire_id' => $livreur->id,
        ]);

        $periode->refresh();
        $this->assertSame(StatutPeriodePaiement::CALCULEE->value, $periode->statut->value);
        $this->assertNotNull($periode->calcul_hash);
        $this->assertNotNull($periode->calculated_at);

        $response->assertInertia(fn (Assert $page) => $page
            ->where('recalcul.effectue', true)
            ->where('recalcul.nb_fiches', 1)
            ->where('stats.total_net', 300000)
        );
    }

    public function test_deuxieme_ouverture_de_la_periode_ne_cree_pas_de_doublons(): void
    {
        $this->travelTo('2026-06-10 12:00:00');

        $personne = Personne::create([
            'organization_id' => $this->org->id,
            'nom' => 'Diallo',
            'prenom' => 'Mamadou',
            'telephone' => '+224'.fake()->unique()->numerify('#########'),
        ]);

        $livreur = Livreur::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'nom_complet' => 'Mamadou Diallo',
            'is_active' => true,
        ]);

        $this->makeEnveloppeAvecPart(300000, null, $livreur);

        $periode = $this->makePeriode();

        $this->actingAs($this->user)->get(route('comptabilite.periodes.show', $periode));
        $premierHash = $periode->refresh()->calcul_hash;

        // Rien n'a changé côté données source : la deuxième ouverture ne doit ni recalculer,
        // ni recréer de fiche en double.
        $response = $this->actingAs($this->user)->get(route('comptabilite.periodes.show', $periode));

        $response->assertInertia(fn (Assert $page) => $page->where('recalcul.effectue', false));
        $this->assertSame(1, PaiementFiche::where('periode_id', $periode->id)->count());
        $this->assertSame($premierHash, $periode->refresh()->calcul_hash);
    }

    public function test_ajustement_met_a_jour_les_fiches_immediatement_sans_reouverture(): void
    {
        $this->travelTo('2026-06-10 12:00:00');

        $personne = Personne::create([
            'organization_id' => $this->org->id,
            'nom' => 'Diallo',
            'prenom' => 'Mamadou',
            'telephone' => '+224'.fake()->unique()->numerify('#########'),
        ]);

        $livreur = Livreur::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'nom_complet' => 'Mamadou Diallo',
            'is_active' => true,
        ]);

        $part = $this->makeEnveloppeAvecPart(300000, null, $livreur);

        $periode = $this->makePeriode();

        // Première ouverture : auto-calcul.
        $this->actingAs($this->user)->get(route('comptabilite.periodes.show', $periode));
        $ficheAvant = PaiementFiche::where('periode_id', $periode->id)->first();
        $this->assertSame(300000.0, (float) $ficheAvant->montant_net);

        // Un ajustement change la donnée source : la fiche doit être mise à jour tout de suite,
        // sans attendre qu'un utilisateur rouvre la page (cf. CommissionAdjustmentService::
        // ajusterMontant -> PeriodeCalculatorService::recalculerPeriodesConcernees).
        $this->actingAs($this->user)->patch(
            route('comptabilite.ajustements.ajuster', ['type' => 'vente', 'partId' => $part->id]),
            ['montant' => 200000, 'motif' => 'correction']
        );

        $this->assertSame(1, PaiementFiche::where('periode_id', $periode->id)->count());
        $ficheApres = PaiementFiche::where('periode_id', $periode->id)->first();
        $this->assertSame(200000.0, (float) $ficheApres->montant_net);

        // Ré-ouvrir la page ne doit déclencher aucun recalcul redondant : tout est déjà à jour.
        $response = $this->actingAs($this->user)->get(route('comptabilite.periodes.show', $periode));
        $response->assertInertia(fn (Assert $page) => $page->where('recalcul.effectue', false));
    }

    public function test_periode_cloturee_ne_recalcule_pas_automatiquement(): void
    {
        $this->travelTo('2026-06-10 12:00:00');

        $personne = Personne::create([
            'organization_id' => $this->org->id,
            'nom' => 'Diallo',
            'prenom' => 'Mamadou',
            'telephone' => '+224'.fake()->unique()->numerify('#########'),
        ]);

        $livreur = Livreur::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'nom_complet' => 'Mamadou Diallo',
            'is_active' => true,
        ]);

        $this->makeEnveloppeAvecPart(300000, null, $livreur);

        $periode = $this->makePeriode([
            'statut' => StatutPeriodePaiement::CLOTUREE->value,
        ]);

        // Aucune fiche n'existe pour cette période clôturée : elle a été clôturée sans jamais
        // avoir été calculée (scénario de test), ce qui doit rester le cas après ouverture.
        $response = $this->actingAs($this->user)->get(route('comptabilite.periodes.show', $periode));
        $response->assertStatus(200);

        $response->assertInertia(fn (Assert $page) => $page->where('recalcul.effectue', false));
        $this->assertSame(0, PaiementFiche::where('periode_id', $periode->id)->count());

        $periode->refresh();
        $this->assertSame(StatutPeriodePaiement::CLOTUREE->value, $periode->statut->value);
        $this->assertNull($periode->calcul_hash);
    }

    // ── valider ───────────────────────────────────────────────────────────────

    public function test_valider_periode_calculee(): void
    {
        $periode = $this->makePeriode([
            'statut' => StatutPeriodePaiement::CALCULEE->value,
        ]);

        $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.valider', $periode))
            ->assertRedirect();

        $this->assertDatabaseHas('paiement_periodes', [
            'id' => $periode->id,
            'statut' => StatutPeriodePaiement::VALIDEE->value,
        ]);
    }

    public function test_sans_droit_comptabilite_ne_peut_pas_resoudre_une_periode(): void
    {
        Role::firstOrCreate(['name' => 'employe', 'guard_name' => 'web']);
        $employe = User::factory()->create(['organization_id' => $this->org->id]);
        $employe->assignRole('employe');

        $this->actingAs($employe)
            ->get(route('comptabilite.periodes.voir', ['type' => 'livreur', 'annee' => 2026, 'mois' => 6, 'quinzaine' => 'P1']))
            ->assertStatus(403);
    }

    // ── show : la page est centrée véhicule (pas bénéficiaire) ──────────────────
    // Couverture détaillée (liste par véhicule, marquage écart, filtre nom/livreur)
    // déjà portée dans CommissionAjustementVenteTest.php — pas dupliquée ici.

    // ── clôturer ──────────────────────────────────────────────────────────────

    public function test_cloture_refusee_si_reste_a_payer(): void
    {
        $this->travelTo('2026-06-10 12:00:00');

        $personne = Personne::create([
            'organization_id' => $this->org->id,
            'nom' => 'Diallo',
            'prenom' => 'Mamadou',
            'telephone' => '+224'.fake()->unique()->numerify('#########'),
        ]);

        $livreur = Livreur::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'nom_complet' => 'Mamadou Diallo',
            'is_active' => true,
        ]);

        $this->makeEnveloppeAvecPart(300000, null, $livreur);

        $periode = $this->makePeriode();
        $this->actingAs($this->user)->post(route('comptabilite.periodes.calculer', $periode));
        $periode->update(['statut' => StatutPeriodePaiement::VALIDEE->value]);

        // Aucun paiement enregistré : la fiche a un reste à payer non nul.
        $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.cloturer', $periode))
            ->assertRedirect();

        $this->assertDatabaseHas('paiement_periodes', [
            'id' => $periode->id,
            'statut' => StatutPeriodePaiement::VALIDEE->value,
        ]);
    }

    public function test_cloture_autorisee_si_solde(): void
    {
        $this->travelTo('2026-06-10 12:00:00');

        $personne = Personne::create([
            'organization_id' => $this->org->id,
            'nom' => 'Diallo',
            'prenom' => 'Mamadou',
            'telephone' => '+224'.fake()->unique()->numerify('#########'),
        ]);

        $livreur = Livreur::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'nom_complet' => 'Mamadou Diallo',
            'is_active' => true,
        ]);

        $this->makeEnveloppeAvecPart(300000, null, $livreur);

        $periode = $this->makePeriode();
        $this->actingAs($this->user)->post(route('comptabilite.periodes.calculer', $periode));
        $periode->update(['statut' => StatutPeriodePaiement::VALIDEE->value]);

        $fiche = PaiementFiche::where('periode_id', $periode->id)->firstOrFail();
        $this->actingAs($this->user)->post(route('comptabilite.fiches.paiements.store', $fiche), [
            'montant' => 300000,
            'mode_paiement' => 'especes',
            'date_paiement' => '2026-06-15',
        ]);

        $this->actingAs($this->user)
            ->post(route('comptabilite.periodes.cloturer', $periode))
            ->assertRedirect();

        $this->assertDatabaseHas('paiement_periodes', [
            'id' => $periode->id,
            'statut' => StatutPeriodePaiement::CLOTUREE->value,
        ]);
    }

    // ── helper ────────────────────────────────────────────────────────────────

    private function makeCommande(): CommandeVente
    {
        $client = Client::create([
            'organization_id' => $this->org->id,
            'nom' => 'Client Test',
            'prenom' => 'Test',
            'is_active' => true,
            'cashback_eligible' => false,
        ]);

        return CommandeVente::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->defaultSite()->id,
            'client_id' => $client->id,
            'reference' => 'CMD-TEST-'.uniqid(),
            'statut' => 'livree',
            'total_commande' => 1000000,
        ]);
    }
}
