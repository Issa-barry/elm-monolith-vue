<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutContrat;
use App\Enums\TypeContrat;
use App\Models\CompteComptable;
use App\Models\Contrat;
use App\Models\EcritureComptable;
use App\Models\Employe;
use App\Models\PaieLigne;
use App\Models\PaiePaiement;
use App\Models\Personne;
use App\Models\Site;
use App\Services\PaieCalculService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Avant le chantier Financement des agences, un paiement de salaire n'avait
 * AUCUNE écriture dans compta_ecritures (seulement JournalTresorerie) — ce
 * test verrouille la correction : sans elle, TresorerieDisponibiliteService
 * ignorerait silencieusement les salaires payés localement et surestimerait
 * le disponible d'une agence.
 */
class PaieComptabilisationServiceTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private Site $site;

    private Employe $employe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser([]);

        $this->site = $this->user->sites()->first();

        $personne = Personne::create([
            'organization_id' => $this->org->id,
            'nom' => 'Nom', 'prenom' => 'Prenom',
            'telephone' => '+224'.fake()->unique()->numerify('#########'),
        ]);
        $this->employe = Employe::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'matricule' => (string) random_int(100000, 999999),
            'type_employe' => 'interne',
            'site_id' => $this->site->id,
            'statut' => 'actif',
        ]);
        Contrat::create([
            'organization_id' => $this->org->id,
            'employe_id' => $this->employe->id,
            'type_contrat' => TypeContrat::CDI->value,
            'date_debut' => '2020-01-01',
            'salaire_base' => 1_000_000,
            'statut_contrat' => StatutContrat::ACTIF->value,
        ]);
    }

    public function test_paiement_salaire_genere_une_ecriture_equilibree_au_site_de_l_employe(): void
    {
        $periode = app(PaieCalculService::class)->getOrGenererPeriode($this->org->id, 8, 2026);
        $ligne = PaieLigne::where('paie_periode_id', $periode->id)->where('employe_id', $this->employe->id)->firstOrFail();

        $paiement = PaiePaiement::create([
            'paie_ligne_id' => $ligne->id,
            'montant' => 1_000_000,
            'date_paiement' => '2026-08-30',
            'mode_paiement' => 'especes',
        ]);

        $compteCaisse = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();
        $compteCharge = CompteComptable::where('organization_id', $this->org->id)->where('numero', '661000')->firstOrFail();

        $ligneTresorerie = EcritureComptable::where('compte_comptable_id', $compteCaisse->id)
            ->whereHas('piece', fn ($q) => $q->where('source_id', $paiement->id))
            ->firstOrFail();
        $this->assertSame(1_000_000.0, (float) $ligneTresorerie->credit);
        $this->assertSame($this->site->id, $ligneTresorerie->site_id);

        $ligneCharge = EcritureComptable::where('compte_comptable_id', $compteCharge->id)
            ->whereHas('piece', fn ($q) => $q->where('source_id', $paiement->id))
            ->firstOrFail();
        $this->assertSame(1_000_000.0, (float) $ligneCharge->debit);
    }
}
