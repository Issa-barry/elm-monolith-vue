<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\StatutCommission;
use App\Enums\StatutDepense;
use App\Enums\StatutFichePaiement;
use App\Enums\StatutPeriodePaiement;
use App\Enums\TypeLignePaiement;
use App\Enums\TypePeriodePaiement;
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
use App\Models\PaiementFicheLigne;
use App\Models\PaiementPeriode;
use App\Models\Personne;
use App\Services\PeriodeCalculatorService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * ADR 0010, lot 1 — une fiche ayant reçu un paiement (même partiel) est définitivement figée :
 * jamais supprimée ni recréée, en base comme dans le modèle et le recalcul. Tout ce qui arrive
 * ensuite pour le bénéficiaire va sur une fiche complémentaire ; une déduction qui dépasse le
 * solde est reportée sur sa fiche suivante.
 */
class FicheFigeeEtComplementaireTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private ?CommissionProcessus $processus = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['comptabilite.read', 'comptabilite.manage', 'comptabilite.payer']);
        $this->travelTo('2026-06-10 12:00:00');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeLivreur(string $nom = 'Mamadou Diallo'): Livreur
    {
        $personne = Personne::create([
            'organization_id' => $this->org->id,
            'nom' => $nom,
            'prenom' => 'Test',
            'telephone' => '+224'.fake()->unique()->numerify('#########'),
        ]);

        return Livreur::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'nom_complet' => $nom,
            'is_active' => true,
        ]);
    }

    private function makeCommission(float $montant, Livreur $livreur, ?string $earnedAt = null): CommissionEnveloppePart
    {
        $client = Client::create([
            'organization_id' => $this->org->id,
            'nom' => 'Client',
            'prenom' => 'Test',
            'is_active' => true,
            'cashback_eligible' => false,
        ]);
        $commande = CommandeVente::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->user->sites()->first()->id,
            'client_id' => $client->id,
            'reference' => 'CMD-'.uniqid(),
            'statut' => 'livree',
            'total_commande' => 1000000,
        ]);

        $this->processus ??= CommissionProcessus::create([
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
            'processus_id' => $this->processus->id,
            'cible_type' => CommissionCibleType::CODE_EQUIPE_LIVRAISON,
            'cible_id' => (string) Str::ulid(),
            'montant_total' => $montant,
            'earned_at' => $earnedAt ?? now(),
            'statut' => StatutCommission::IMPAYE->value,
        ]);

        return CommissionEnveloppePart::create([
            'enveloppe_id' => $enveloppe->id,
            'beneficiaire_type' => CommissionEnveloppePart::TYPE_LIVREUR,
            'beneficiaire_id' => $livreur->id,
            'montant_brut' => $montant,
            'montant_net' => $montant,
            'montant_verse' => 0,
            'statut' => StatutCommission::IMPAYE->value,
        ]);
    }

    private function makeDepense(float $montant, Livreur $livreur, string $date = '2026-06-05'): Depense
    {
        $type = DepenseType::firstOrCreate(
            ['organization_id' => $this->org->id, 'code' => 'FUEL'],
            ['libelle' => 'Carburant', 'categorie' => 'interne', 'commentaire_obligatoire' => false, 'justificatif_obligatoire' => false, 'is_active' => true],
        );

        return Depense::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->user->sites()->first()->id,
            'user_id' => $this->user->id,
            'depense_type_id' => $type->id,
            'beneficiaire_type' => 'livreur',
            'beneficiaire_id' => $livreur->id,
            'montant' => $montant,
            'date_depense' => $date,
            'statut' => StatutDepense::VALIDE->value,
        ]);
    }

    private function makePeriode(string $debut = '2026-06-01', string $fin = '2026-06-15', string $ref = 'PAY-202606-P1-LIV'): PaiementPeriode
    {
        return PaiementPeriode::create([
            'organization_id' => $this->org->id,
            'reference' => $ref,
            'type' => TypePeriodePaiement::LIVREUR->value,
            'date_debut' => $debut,
            'date_fin' => $fin,
            'statut' => StatutPeriodePaiement::BROUILLON->value,
            'created_by' => $this->user->id,
        ]);
    }

    /**
     * Paiement posé directement en base, sans les événements du modèle (ni recalcul, ni
     * comptabilité) : c'est le cas le plus exigeant pour les protections, qui ne doivent pas
     * dépendre d'un chemin applicatif particulier.
     */
    private function payer(PaiementFiche $fiche, float $montant): void
    {
        DB::table('paiement_fiche_paiements')->insert([
            'id' => (string) Str::ulid(),
            'fiche_id' => $fiche->id,
            'organization_id' => $fiche->organization_id,
            'montant' => $montant,
            'mode_paiement' => 'especes',
            'date_paiement' => '2026-06-16',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('paiement_fiches')->where('id', $fiche->id)->update([
            'montant_paye' => $montant,
            'statut' => $montant >= (float) $fiche->montant_net
                ? StatutFichePaiement::PAYE->value
                : StatutFichePaiement::PARTIELLEMENT_PAYE->value,
        ]);
    }

    private function calculer(PaiementPeriode $periode): void
    {
        $periode->refresh()->update(['statut' => StatutPeriodePaiement::CALCULEE->value]);
        app(PeriodeCalculatorService::class)->calculer($periode->refresh());
    }

    private function fiches(PaiementPeriode $periode, Livreur $livreur)
    {
        return PaiementFiche::where('periode_id', $periode->id)
            ->where('beneficiaire_id', $livreur->id)
            ->orderBy('rang')
            ->get();
    }

    // ── Protection base de données ────────────────────────────────────────────

    public function test_la_base_refuse_de_supprimer_une_fiche_ayant_recu_un_paiement(): void
    {
        $livreur = $this->makeLivreur();
        $this->makeCommission(300000, $livreur);
        $periode = $this->makePeriode();
        $this->calculer($periode);
        $fiche = $this->fiches($periode, $livreur)->first();
        $this->payer($fiche, 100000);

        $this->expectException(QueryException::class);
        DB::table('paiement_fiches')->where('id', $fiche->id)->delete();
    }

    // ── Protection modèle ─────────────────────────────────────────────────────

    public function test_le_modele_refuse_suppression_douce_et_definitive_d_une_fiche_payee(): void
    {
        $livreur = $this->makeLivreur();
        $this->makeCommission(300000, $livreur);
        $periode = $this->makePeriode();
        $this->calculer($periode);
        $fiche = $this->fiches($periode, $livreur)->first();
        $this->payer($fiche, 100000);
        $fiche->refresh();

        foreach (['delete', 'forceDelete'] as $methode) {
            try {
                $fiche->{$methode}();
                $this->fail("{$methode}() aurait dû être refusé");
            } catch (\LogicException) {
                $this->assertDatabaseHas('paiement_fiches', ['id' => $fiche->id, 'deleted_at' => null]);
            }
        }
    }

    public function test_le_modele_refuse_de_modifier_ce_que_doit_une_fiche_payee(): void
    {
        $livreur = $this->makeLivreur();
        $this->makeCommission(300000, $livreur);
        $periode = $this->makePeriode();
        $this->calculer($periode);
        $fiche = $this->fiches($periode, $livreur)->first();
        $this->payer($fiche, 100000);
        $fiche->refresh();

        $this->expectException(\LogicException::class);
        $fiche->update(['montant_net' => 999]);
    }

    public function test_les_lignes_d_une_fiche_payee_sont_intouchables(): void
    {
        $livreur = $this->makeLivreur();
        $this->makeCommission(300000, $livreur);
        $periode = $this->makePeriode();
        $this->calculer($periode);
        $fiche = $this->fiches($periode, $livreur)->first();
        $this->payer($fiche, 100000);

        $this->expectException(\LogicException::class);
        $fiche->lignes()->first()->delete();
    }

    public function test_le_paiement_d_une_fiche_met_toujours_a_jour_son_statut(): void
    {
        $livreur = $this->makeLivreur();
        $this->makeCommission(300000, $livreur);
        $periode = $this->makePeriode();
        $this->calculer($periode);
        $fiche = $this->fiches($periode, $livreur)->first();
        $this->payer($fiche, 100000);

        // Le statut et le montant payé ne sont pas « ce que doit » la fiche : toujours modifiables.
        $fiche->refresh()->recalculStatut();
        $this->assertSame(StatutFichePaiement::PARTIELLEMENT_PAYE, $fiche->fresh()->statut);
    }

    // ── Recalcul ──────────────────────────────────────────────────────────────

    public function test_le_recalcul_ne_touche_jamais_une_fiche_partiellement_payee(): void
    {
        $livreur = $this->makeLivreur();
        $this->makeCommission(300000, $livreur);
        $periode = $this->makePeriode();
        $this->calculer($periode);
        $origine = $this->fiches($periode, $livreur)->first();
        $this->payer($origine, 100000);

        $this->makeCommission(50000, $livreur);
        $this->calculer($periode);

        $fiches = $this->fiches($periode, $livreur);
        $this->assertCount(2, $fiches);

        $initiale = $fiches->first();
        $this->assertSame($origine->id, $initiale->id, 'la fiche payée garde son identité');
        $this->assertSame(300000.0, (float) $initiale->montant_net);
        $this->assertSame(100000.0, (float) $initiale->montant_paye);
        $this->assertSame(1, DB::table('paiement_fiche_paiements')->where('fiche_id', $origine->id)->count());

        $complement = $fiches->last();
        $this->assertSame(2, $complement->rang);
        $this->assertSame($origine->id, $complement->fiche_origine_id);
        $this->assertSame(50000.0, (float) $complement->montant_net, 'seule la nouvelle commission');
        $this->assertCount(1, $complement->lignes);
    }

    public function test_une_fiche_totalement_payee_recoit_une_fiche_complementaire(): void
    {
        $livreur = $this->makeLivreur();
        $this->makeCommission(300000, $livreur);
        $periode = $this->makePeriode();
        $this->calculer($periode);
        $origine = $this->fiches($periode, $livreur)->first();
        $this->payer($origine, 300000);

        $this->makeCommission(200000, $livreur);
        $this->calculer($periode);

        $fiches = $this->fiches($periode, $livreur);
        $this->assertCount(2, $fiches);
        $this->assertSame(StatutFichePaiement::PAYE, $fiches->first()->statut);
        $this->assertSame(200000.0, (float) $fiches->last()->montant_net);
        $this->assertSame(StatutFichePaiement::A_PAYER, $fiches->last()->statut);
    }

    public function test_le_recalcul_repete_ne_cree_pas_de_fiche_supplementaire(): void
    {
        $livreur = $this->makeLivreur();
        $this->makeCommission(300000, $livreur);
        $periode = $this->makePeriode();
        $this->calculer($periode);
        $this->payer($this->fiches($periode, $livreur)->first(), 300000);
        $this->makeCommission(200000, $livreur);

        $this->calculer($periode);
        $this->calculer($periode);

        $this->assertSame([1, 2], $this->fiches($periode, $livreur)->pluck('rang')->all());
    }

    public function test_une_fiche_non_payee_reste_reconstruite_a_chaque_recalcul(): void
    {
        $livreur = $this->makeLivreur();
        $part = $this->makeCommission(300000, $livreur);
        $periode = $this->makePeriode();
        $this->calculer($periode);

        $part->update(['montant_actuel' => 250000]);
        $this->calculer($periode);

        $fiches = $this->fiches($periode, $livreur);
        $this->assertCount(1, $fiches);
        $this->assertSame(1, $fiches->first()->rang);
        $this->assertSame(250000.0, (float) $fiches->first()->montant_net);
    }

    /** Avant le lot 1, garder la fiche payée puis recréer celle du même bénéficiaire violait l'unicité. */
    public function test_une_depense_tardive_sur_une_fiche_payee_ne_bloque_plus_le_recalcul(): void
    {
        $livreur = $this->makeLivreur();
        $this->makeCommission(300000, $livreur);
        $periode = $this->makePeriode();
        $this->calculer($periode);
        $this->payer($this->fiches($periode, $livreur)->first(), 300000);

        $this->makeDepense(20000, $livreur);
        $this->makeCommission(100000, $livreur);
        $this->calculer($periode);

        $complement = $this->fiches($periode, $livreur)->last();
        $this->assertSame(2, $complement->rang);
        $this->assertSame(80000.0, (float) $complement->montant_net, '100 000 − 20 000 de dépense tardive');
    }

    // ── Report de déduction ──────────────────────────────────────────────────

    public function test_une_depense_apres_paiement_complet_est_reportee_sur_la_fiche_suivante(): void
    {
        $livreur = $this->makeLivreur();
        $this->makeCommission(1000000, $livreur);
        $p1 = $this->makePeriode();
        $this->calculer($p1);
        $origine = $this->fiches($p1, $livreur)->first();
        $this->payer($origine, 1000000);

        $this->makeDepense(100000, $livreur);
        $this->calculer($p1);

        $fichesP1 = $this->fiches($p1, $livreur);
        $this->assertSame(1000000.0, (float) $fichesP1->first()->fresh()->montant_net, 'fiche payée intacte');
        $report = $fichesP1->last();
        $this->assertSame(2, $report->rang);
        $this->assertSame(0.0, (float) $report->montant_net, 'rien à payer');
        $this->assertSame(100000.0, (float) $report->report_a_deduire);
        $this->assertTrue($report->estFigee(), 'une fiche portant un report est figée');

        // Période suivante : la déduction est imputée sur la prochaine fiche du bénéficiaire.
        $this->travelTo('2026-06-20 12:00:00');
        $this->makeCommission(500000, $livreur);
        $p2 = $this->makePeriode('2026-06-16', '2026-06-30', 'PAY-202606-P2-LIV');
        $this->calculer($p2);

        $ficheP2 = $this->fiches($p2, $livreur)->first();
        $this->assertSame(400000.0, (float) $ficheP2->montant_net, '500 000 − 100 000 reportés');
        $this->assertTrue($ficheP2->lignes->contains(fn (PaiementFicheLigne $l) => $l->type_ligne === TypeLignePaiement::REPORT
            && $l->source_id === $report->id));

        // Recalculer la période suivante n'impute jamais deux fois le même report.
        $this->calculer($p2);
        $this->assertSame(400000.0, (float) $this->fiches($p2, $livreur)->first()->montant_net);
    }

    public function test_un_report_reste_en_attente_tant_qu_aucune_fiche_ne_l_impute(): void
    {
        $livreur = $this->makeLivreur();
        $this->makeCommission(1000000, $livreur);
        $p1 = $this->makePeriode();
        $this->calculer($p1);
        $this->payer($this->fiches($p1, $livreur)->first(), 1000000);
        $this->makeDepense(100000, $livreur);
        $this->calculer($p1);

        // Recalcul de la même période sans nouvelle ligne : le report n'est ni imputé ni recréé.
        $this->calculer($p1);

        $fiches = $this->fiches($p1, $livreur);
        $this->assertCount(2, $fiches);
        $this->assertSame(100000.0, (float) $fiches->last()->report_a_deduire);
        $this->assertSame(0, PaiementFicheLigne::where('type_ligne', TypeLignePaiement::REPORT->value)->count());
    }

    // ── Reprise des périodes validées ────────────────────────────────────────

    public function test_la_reprise_marque_validees_les_fiches_des_periodes_validees_sans_rien_modifier_d_autre(): void
    {
        $livreur = $this->makeLivreur();
        $this->makeCommission(300000, $livreur);
        $validee = $this->makePeriode();
        $this->calculer($validee);
        $validee->update([
            'statut' => StatutPeriodePaiement::VALIDEE->value,
            'validated_at' => '2026-06-16 09:00:00',
            'validated_by' => $this->user->id,
        ]);
        $fiche = $this->fiches($validee, $livreur)->first();
        $this->payer($fiche, 100000);

        $autre = $this->makeLivreur('Oumar Bah');
        $this->travelTo('2026-06-20 12:00:00');
        $this->makeCommission(200000, $autre);
        $calculee = $this->makePeriode('2026-06-16', '2026-06-30', 'PAY-202606-P2-LIV');
        $this->calculer($calculee);

        DB::table('paiement_fiches')->update(['validated_at' => null, 'validated_by' => null]);
        $avant = DB::table('paiement_fiches')->orderBy('id')->get(['id', 'montant_net', 'montant_paye', 'statut'])->toArray();
        $paiementsAvant = DB::table('paiement_fiche_paiements')->count();
        $piecesAvant = DB::table('compta_pieces')->count();

        $migration = require database_path('migrations/2026_09_28_100100_reprise_validation_fiches_periodes_validees.php');
        $migration->up();
        $migration->up();

        $this->assertSame('2026-06-16 09:00:00', $fiche->fresh()->validated_at->toDateTimeString());
        $this->assertSame($this->user->id, $fiche->fresh()->validated_by);
        $this->assertNull($this->fiches($calculee, $autre)->first()->validated_at, 'période non validée : fiche non validée');

        $this->assertEquals($avant, DB::table('paiement_fiches')->orderBy('id')->get(['id', 'montant_net', 'montant_paye', 'statut'])->toArray());
        $this->assertSame($paiementsAvant, DB::table('paiement_fiche_paiements')->count());
        $this->assertSame($piecesAvant, DB::table('compta_pieces')->count());
    }
}
