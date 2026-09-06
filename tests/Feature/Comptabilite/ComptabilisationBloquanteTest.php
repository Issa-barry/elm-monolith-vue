<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutContrat;
use App\Enums\StatutDepense;
use App\Enums\StatutPeriodePaie;
use App\Enums\StatutPeriodePaiement;
use App\Enums\TypeContrat;
use App\Enums\TypePeriodePaiement;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CompteMapping;
use App\Models\Contrat;
use App\Models\Depense;
use App\Models\DepenseType;
use App\Models\DroitCreationDepense;
use App\Models\Employe;
use App\Models\FactureVente;
use App\Models\Livreur;
use App\Models\PaieLigne;
use App\Models\PaiementFiche;
use App\Models\Personne;
use App\Services\PaieCalculService;
use App\Services\PeriodePaiementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Verrouille la correction de la revue Codex du 2026-08-22 : un événement qui
 * déplace de la trésorerie réelle (paiement fiche/salaire, dépense validée,
 * encaissement) ne doit plus jamais réussir côté métier si sa comptabilisation
 * échoue — sinon le disponible calculé par TresorerieDisponibiliteService
 * devient faux silencieusement. On simule l'échec en supprimant le mapping
 * comptable de l'événement concerné (cas réel : organisation mal configurée).
 */
class ComptabilisationBloquanteTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['comptabilite.payer', 'depenses.valider', 'rh-paie.pay']);

        // Admin Entreprise ($this->user) reste soumis au plafond de montant
        // depuis le 04/09/2026 (cf. docs/depenses-validation.md, DEPVAL-001).
        // Plafond très haut ici : test_validation_depense_echoue_si_comptabilisation_impossible
        // doit échouer à cause du mapping comptable cassé, pas d'un plafond.
        DroitCreationDepense::create([
            'organization_id' => $this->org->id,
            'role_name' => 'admin_entreprise',
            'perimetre' => 'toutes_agences',
            'sites' => null,
            'peut_valider' => true,
            'plafond_validation' => 999_999_999,
        ]);
    }

    private function casserMapping(string $evenement): void
    {
        CompteMapping::where('organization_id', $this->org->id)->where('evenement', $evenement)->delete();
    }

    public function test_paiement_fiche_echoue_et_ne_persiste_rien_si_comptabilisation_impossible(): void
    {
        $site = $this->user->sites()->first();
        [$debut] = PeriodePaiementService::dateRangeFor(2026, 8, PeriodePaiementService::P1);
        $periode = app(PeriodePaiementService::class)->getOrCreatePeriod($this->org->id, TypePeriodePaiement::LIVREUR, $debut);

        $personne = Personne::create(['organization_id' => $this->org->id, 'telephone' => '+224'.fake()->unique()->numerify('#########')]);
        $livreur = Livreur::create(['organization_id' => $this->org->id, 'personne_id' => $personne->id, 'nom_complet' => 'Livreur Test', 'is_active' => true]);

        $periode->update(['statut' => StatutPeriodePaiement::VALIDEE->value]);

        $fiche = PaiementFiche::create([
            'organization_id' => $this->org->id,
            'periode_id' => $periode->id,
            'reference' => 'FICHE-TEST-'.uniqid(),
            'beneficiaire_type' => 'livreur',
            'beneficiaire_id' => $livreur->id,
            'beneficiaire_nom' => 'Livreur Test',
            'site_id' => $site->id,
            'montant_brut' => 100_000,
            'montant_net' => 100_000,
            'statut' => 'a_payer',
        ]);

        $this->casserMapping('paiement_livreur');

        $response = $this->actingAs($this->user)->post(route('comptabilite.fiches.paiements.store', $fiche), [
            'montant' => 50_000,
            'mode_paiement' => 'especes',
            'date_paiement' => '2026-08-05',
        ]);

        $response->assertSessionHasErrors('comptabilisation');
        $this->assertDatabaseMissing('paiement_fiche_paiements', ['fiche_id' => $fiche->id]);
    }

    public function test_paiement_salaire_echoue_et_ne_persiste_rien_si_comptabilisation_impossible(): void
    {
        $site = $this->user->sites()->first();
        $personne = Personne::create(['organization_id' => $this->org->id, 'nom' => 'N', 'prenom' => 'P', 'telephone' => '+224'.fake()->unique()->numerify('#########')]);
        $employe = Employe::create([
            'organization_id' => $this->org->id, 'personne_id' => $personne->id,
            'matricule' => (string) random_int(100000, 999999), 'type_employe' => 'interne',
            'site_id' => $site->id, 'statut' => 'actif',
        ]);
        Contrat::create([
            'organization_id' => $this->org->id, 'employe_id' => $employe->id,
            'type_contrat' => TypeContrat::CDI->value, 'date_debut' => '2020-01-01',
            'salaire_base' => 1_000_000, 'statut_contrat' => StatutContrat::ACTIF->value,
        ]);

        $periode = app(PaieCalculService::class)->getOrGenererPeriode($this->org->id, 8, 2026);
        $periode->update(['statut' => StatutPeriodePaie::VALIDE_RH->value]);
        $ligne = PaieLigne::where('paie_periode_id', $periode->id)->where('employe_id', $employe->id)->firstOrFail();

        $this->casserMapping('paiement_salaire');

        $response = $this->actingAs($this->user)->post(route('paie-paiements.store', $ligne), [
            'montant' => 1_000_000,
            'date_paiement' => '2026-08-30',
            'mode_paiement' => 'especes',
        ]);

        $response->assertSessionHasErrors('comptabilisation');
        $this->assertDatabaseMissing('paie_paiements', ['paie_ligne_id' => $ligne->id]);
    }

    public function test_validation_depense_echoue_si_comptabilisation_impossible(): void
    {
        $site = $this->user->sites()->first();
        $type = DepenseType::create([
            'organization_id' => $this->org->id, 'code' => 'TEST', 'libelle' => 'Test',
            'categorie' => 'interne', 'actif' => true,
        ]);
        $depense = Depense::create([
            'organization_id' => $this->org->id, 'site_id' => $site->id,
            'depense_type_id' => $type->id, 'user_id' => $this->user->id,
            'montant' => 50_000, 'date_depense' => '2026-08-10',
            'statut' => StatutDepense::SOUMIS->value,
        ]);

        $this->casserMapping('depense_interne_validee');

        $response = $this->actingAs($this->user)->patch(route('depenses.valider', $depense));

        $response->assertSessionHasErrors();
        $this->assertSame(StatutDepense::SOUMIS, $depense->fresh()->statut);
    }

    public function test_encaissement_echoue_et_ne_persiste_rien_si_comptabilisation_impossible(): void
    {
        $site = $this->user->sites()->first();
        $client = Client::create([
            'organization_id' => $this->org->id, 'nom' => 'Client', 'prenom' => 'Test',
            'is_active' => true, 'cashback_eligible' => false,
        ]);
        $commande = CommandeVente::create([
            'organization_id' => $this->org->id, 'site_id' => $site->id, 'client_id' => $client->id,
            'reference' => 'CMD-TEST-'.uniqid(), 'statut' => 'livraison_en_cours', 'total_commande' => 200_000,
        ]);
        $facture = FactureVente::create([
            'organization_id' => $this->org->id, 'site_id' => $site->id, 'commande_vente_id' => $commande->id,
            'reference' => 'FACT-TEST-'.uniqid(),
            'montant_brut' => 200_000, 'montant_net' => 200_000,
        ]);

        $this->casserMapping('encaissement_vente_recu');

        $response = $this->actingAs($this->user)->post(route('encaissements.store', $facture), [
            'montant' => 100_000,
            'mode_paiement' => 'especes',
        ]);

        $response->assertSessionHasErrors('comptabilisation');
        $this->assertDatabaseMissing('encaissements_ventes', ['facture_vente_id' => $facture->id]);
    }
}
