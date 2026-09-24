<?php

namespace Tests\Feature\Tresorerie;

use App\Enums\EvenementComptable;
use App\Exceptions\Comptabilite\MappingComptableIndisponibleException;
use App\Models\CommandeVente;
use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\Organization;
use App\Models\PieceComptable;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\Comptabilite\EcritureComptableService;
use App\Services\Comptabilite\VenteComptabilisationService;
use App\Services\Tresorerie\CaisseAgentResolver;
use App\Services\Tresorerie\CaisseAgentService;
use App\Services\Tresorerie\SoldeOuvertureTresorerieService;
use App\Services\Tresorerie\SupportTresorerieValidationService;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Phase 2 du chantier caisses dédiées (ADR 0001) : les encaissements EN ESPÈCES enregistrés
 * par un agent qui a une caisse dédiée active sur le site de la facture alimentent SA caisse
 * (sous-compte propre) au lieu du compte 571000 partagé. Tout autre cas garde le comportement
 * historique. Règles verrouillées ici : espèces uniquement, caisse du site de la facture,
 * caisse active, jamais de reclassement de l'historique, contrepassation sur la même caisse,
 * isolation entre organisations.
 *
 * Ce fichier exerce la couche comptable en créant les encaissements directement : les cas « comportement
 * historique » sont ceux des encaissements déjà enregistrés et des rattrapages. Le refus d'un NOUVEL
 * encaissement en espèces sans caisse active (23/09/2026) est porté par le contrôleur — cf.
 * EncaissementEspecesCaisseObligatoireTest.
 */
class CaisseAgentEncaissementTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, RefreshDatabase;

    private TresorerieDisponibiliteService $disponibilite;

    private Site $site;

    private User $agent;

    private CompteTresorerie $caisseAgence;

    private CompteTresorerie $caisseAgent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['tresorerie.read']);
        $this->disponibilite = app(TresorerieDisponibiliteService::class);
        $this->site = $this->user->sites()->first();

        $this->caisseAgence = CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'compte_comptable_id' => CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail()->id,
            'type' => 'caisse',
            'libelle' => 'Caisse agence',
        ]);

        $this->agent = $this->creerAgent($this->site);
        $this->caisseAgent = $this->creerCaisseActive($this->site->id, $this->agent->id);
    }

    private function facture(?Site $site = null, ?Organization $org = null): FactureVente
    {
        return FactureVente::factory()->create([
            'organization_id' => ($org ?? $this->org)->id,
            'site_id' => ($site ?? $this->site)->id,
            'montant_net' => 500_000,
        ]);
    }

    private function encaisser(?User $auteur, float $montant = 100_000, string $mode = 'especes', ?FactureVente $facture = null, ?string $date = null): EncaissementVente
    {
        return EncaissementVente::create([
            'facture_vente_id' => ($facture ?? $this->facture())->id,
            'montant' => $montant,
            'date_encaissement' => $date ?? now()->toDateString(),
            'mode_paiement' => $mode,
            'reference_paiement' => in_array($mode, ['mobile_money', 'virement'], true) ? 'REF-TEST' : null,
            'created_by' => $auteur?->id,
        ]);
    }

    /** @return array<string, array{debit: float, credit: float}> lignes de la pièce, indexées par numéro de compte */
    private function lignes(EncaissementVente $encaissement): array
    {
        $piece = PieceComptable::where('source_type', $encaissement->getMorphClass())
            ->where('source_id', $encaissement->id)
            ->where('type_evenement', EvenementComptable::ENCAISSEMENT_VENTE_RECU->value)
            ->firstOrFail();

        return $piece->lignes()->with('compte')->get()
            ->mapWithKeys(fn ($l) => [$l->compte->numero => ['debit' => (float) $l->debit, 'credit' => (float) $l->credit]])
            ->all();
    }

    // ── Routage nominal ──────────────────────────────────────────────────────

    public function test_les_especes_d_un_agent_alimentent_sa_caisse_dediee(): void
    {
        $encaissement = $this->encaisser($this->agent, 100_000);

        $lignes = $this->lignes($encaissement);

        $this->assertSame(100_000.0, $lignes[$this->caisseAgent->compte->numero]['debit']);
        $this->assertSame('571001', $this->caisseAgent->compte->numero);
        $this->assertSame(100_000.0, $lignes['411000']['credit'], 'le crédit reste sur le compte client');
        $this->assertArrayNotHasKey('571000', $lignes, 'jamais le compte partagé de l\'agence');

        $this->assertSame(100_000.0, $this->disponibilite->soldePourSupport($this->caisseAgent));
        $this->assertSame(0.0, $this->disponibilite->soldePourSupport($this->caisseAgence));
    }

    public function test_la_ligne_de_tresorerie_porte_le_nom_de_la_caisse_et_le_site_de_la_facture(): void
    {
        $encaissement = $this->encaisser($this->agent);

        $piece = PieceComptable::where('source_id', $encaissement->id)->firstOrFail();
        $ligneCaisse = $piece->lignes()->with('compte')->get()->firstWhere('compte.numero', '571001');

        $this->assertStringContainsString($this->caisseAgent->libelle, $ligneCaisse->libelle);
        $this->assertSame($this->site->id, $ligneCaisse->site_id);
    }

    public function test_deux_agents_du_meme_site_alimentent_chacun_leur_caisse(): void
    {
        $autreAgent = $this->creerAgent($this->site, 'Abdoulaye', 'Diallo');
        $autreCaisse = $this->creerCaisseActive($this->site->id, $autreAgent->id);

        $this->encaisser($this->agent, 100_000);
        $this->encaisser($autreAgent, 40_000);
        $this->encaisser($this->agent, 25_000);

        $this->assertSame(125_000.0, $this->disponibilite->soldePourSupport($this->caisseAgent));
        $this->assertSame(40_000.0, $this->disponibilite->soldePourSupport($autreCaisse));
        $this->assertSame(0.0, $this->disponibilite->soldePourSupport($this->caisseAgence));
    }

    // ── Cas qui gardent le comportement historique ───────────────────────────

    public function test_les_autres_moyens_de_paiement_gardent_leurs_supports_habituels(): void
    {
        foreach (['mobile_money' => '561000', 'virement' => '521000', 'cheque' => '521000'] as $mode => $compteAttendu) {
            $lignes = $this->lignes($this->encaisser($this->agent, 10_000, $mode));

            $this->assertArrayHasKey($compteAttendu, $lignes, "mode : {$mode}");
            $this->assertArrayNotHasKey('571001', $lignes, "mode : {$mode} ne doit jamais alimenter la caisse d'un agent");
        }

        $this->assertSame(0.0, $this->disponibilite->soldePourSupport($this->caisseAgent));
    }

    public function test_un_agent_sans_caisse_dediee_suit_le_comportement_historique(): void
    {
        $sansCaisse = $this->creerAgent($this->site, 'Bakary', 'Camara');

        $lignes = $this->lignes($this->encaisser($sansCaisse, 30_000));

        $this->assertSame(30_000.0, $lignes['571000']['debit']);
        $this->assertSame(0.0, $this->disponibilite->soldePourSupport($this->caisseAgent));
    }

    public function test_un_encaissement_sans_auteur_suit_le_comportement_historique(): void
    {
        $lignes = $this->lignes($this->encaisser(null, 30_000));

        $this->assertSame(30_000.0, $lignes['571000']['debit']);
        $this->assertSame(0.0, $this->disponibilite->soldePourSupport($this->caisseAgent));
    }

    public function test_une_caisse_desactivee_ne_recoit_plus_d_encaissement(): void
    {
        app(CaisseAgentService::class)->mettreAJour($this->caisseAgent, ['libelle' => $this->caisseAgent->libelle, 'actif' => false]);

        $lignes = $this->lignes($this->encaisser($this->agent, 30_000));

        $this->assertSame(30_000.0, $lignes['571000']['debit']);
        $this->assertSame(0.0, $this->disponibilite->soldePourSupport($this->caisseAgent));
    }

    // ── Site de la facture ───────────────────────────────────────────────────

    public function test_la_caisse_choisie_est_celle_du_site_de_la_facture(): void
    {
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kouria', 'type' => 'agence', 'localisation' => 'Coyah']);
        $this->agent->sites()->attach($autreSite->id, ['role' => 'employe', 'is_default' => false]);
        $caisseKouria = $this->creerCaisseActive($autreSite->id, $this->agent->id);

        $lignesKouria = $this->lignes($this->encaisser($this->agent, 70_000, 'especes', $this->facture($autreSite)));
        $lignesMatoto = $this->lignes($this->encaisser($this->agent, 20_000, 'especes', $this->facture($this->site)));

        $this->assertSame(70_000.0, $lignesKouria[$caisseKouria->compte->numero]['debit']);
        $this->assertSame(20_000.0, $lignesMatoto[$this->caisseAgent->compte->numero]['debit']);
        $this->assertSame(70_000.0, $this->disponibilite->soldePourSupport($caisseKouria));
        $this->assertSame(20_000.0, $this->disponibilite->soldePourSupport($this->caisseAgent));
    }

    public function test_un_encaissement_sur_un_site_ou_l_agent_n_a_pas_de_caisse_suit_le_comportement_historique(): void
    {
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kouria', 'type' => 'agence', 'localisation' => 'Coyah']);

        $lignes = $this->lignes($this->encaisser($this->agent, 55_000, 'especes', $this->facture($autreSite)));

        $this->assertSame(55_000.0, $lignes['571000']['debit']);
        $this->assertSame(0.0, $this->disponibilite->soldePourSupport($this->caisseAgent));
    }

    // ── Jamais de reclassement de l'historique ───────────────────────────────

    public function test_un_encaissement_date_avant_la_mise_en_service_de_la_caisse_n_est_pas_reclasse(): void
    {
        $this->caisseAgent->forceFill(['valide_le' => '2026-08-20 09:00:00', 'created_at' => '2026-08-20 09:00:00'])->saveQuietly();

        $avant = $this->lignes($this->encaisser($this->agent, 10_000, 'especes', null, '2026-08-14'));
        $memeJour = $this->lignes($this->encaisser($this->agent, 20_000, 'especes', null, '2026-08-20'));
        $apres = $this->lignes($this->encaisser($this->agent, 30_000, 'especes', null, '2026-08-25'));

        $this->assertSame(10_000.0, $avant['571000']['debit'], 'date antérieure à la caisse : historique conservé');
        $this->assertSame(20_000.0, $memeJour['571001']['debit'], 'le jour même de la mise en service, la caisse existe');
        $this->assertSame(30_000.0, $apres['571001']['debit']);
        $this->assertSame(50_000.0, $this->disponibilite->soldePourSupport($this->caisseAgent));
    }

    public function test_un_encaissement_deja_comptabilise_avant_la_caisse_n_est_jamais_reclasse_par_un_rattrapage(): void
    {
        $agentSansCaisse = $this->creerAgent($this->site, 'Bakary', 'Camara');
        $encaissement = $this->encaisser($agentSansCaisse, 45_000);
        $caisse = $this->creerCaisseActive($this->site->id, $agentSansCaisse->id);

        // Rattrapage comptable : repasse sur l'encaissement APRÈS la création de la caisse.
        app(VenteComptabilisationService::class)->comptabiliserEncaissementVente($encaissement->fresh());

        $lignes = $this->lignes($encaissement);
        $this->assertSame(45_000.0, $lignes['571000']['debit']);
        $this->assertSame(0.0, $this->disponibilite->soldePourSupport($caisse));
    }

    public function test_un_encaissement_enregistre_avant_la_caisse_n_est_pas_eligible_meme_avec_une_date_posterieure(): void
    {
        $resolver = app(CaisseAgentResolver::class);
        $agent = $this->creerAgent($this->site, 'Bakary', 'Camara');

        $this->travelTo(Carbon::parse('2026-09-19 10:00:00'));
        $facture = $this->facture();
        $avant = $this->encaisser($agent, 10_000, 'especes', $facture, '2026-09-19');

        $this->travelTo(Carbon::parse('2026-09-19 11:00:00'));
        $this->creerCaisseActive($this->site->id, $agent->id);
        $apres = $this->encaisser($agent, 10_000, 'especes', $facture, '2026-09-19');
        $this->travelBack();

        $this->assertNull($resolver->pourEncaissement($avant->fresh(), $facture), 'enregistré à 10h, la caisse date de 11h');
        $this->assertNotNull($resolver->pourEncaissement($apres->fresh(), $facture), 'enregistré après la création de la caisse');
    }

    public function test_une_caisse_en_brouillon_ne_recoit_aucun_encaissement_et_ne_reclasse_rien_une_fois_validee(): void
    {
        $resolver = app(CaisseAgentResolver::class);
        $agent = $this->creerAgent($this->site, 'Bakary', 'Camara');
        $facture = $this->facture();

        $this->travelTo(Carbon::parse('2026-09-19 10:00:00'));
        $brouillon = app(CaisseAgentService::class)->creer($this->org->id, $this->site->id, $agent->id);

        $this->travelTo(Carbon::parse('2026-09-19 10:30:00'));
        $pendantBrouillon = $this->encaisser($agent, 45_000, 'especes', $facture, '2026-09-19');

        $this->travelTo(Carbon::parse('2026-09-19 11:00:00'));
        app(SupportTresorerieValidationService::class)->valider($brouillon, $this->user);

        $this->travelTo(Carbon::parse('2026-09-19 11:30:00'));
        $apresValidation = $this->encaisser($agent, 20_000, 'especes', $facture, '2026-09-19');
        $this->travelBack();

        $this->assertSame(45_000.0, $this->lignes($pendantBrouillon)['571000']['debit'], 'brouillon : comportement historique');
        $this->assertNull($resolver->pourEncaissement($pendantBrouillon->fresh(), $facture), 'la validation ne reclasse pas un encaissement antérieur, même rejoué par un rattrapage');
        $this->assertSame(20_000.0, $this->lignes($apresValidation)[$brouillon->compte->numero]['debit'], 'après validation : la caisse reçoit les espèces');
        $this->assertSame(20_000.0, $this->disponibilite->soldePourSupport($brouillon->fresh()));
    }

    // ── Journal comptable (option journal_role du moteur) ────────────────────

    public function test_la_piece_d_un_encaissement_route_est_postee_dans_le_journal_caisse_comme_un_encaissement_especes_classique(): void
    {
        $route = PieceComptable::where('source_id', $this->encaisser($this->agent, 10_000)->id)->firstOrFail();
        $classique = PieceComptable::where('source_id', $this->encaisser($this->creerAgent($this->site, 'Bakary', 'Camara'), 10_000)->id)->firstOrFail();

        $this->assertSame('CA', $route->journal->code);
        $this->assertSame($classique->journal_comptable_id, $route->journal_comptable_id);
    }

    public function test_journal_role_refuse_un_role_sans_mapping(): void
    {
        $this->expectException(MappingComptableIndisponibleException::class);

        app(EcritureComptableService::class)->comptabiliser(
            evenement: EvenementComptable::ENCAISSEMENT_VENTE_RECU,
            source: $this->caisseAgent,
            organizationId: $this->org->id,
            dateComptable: Carbon::now(),
            libelle: 'Test journal_role inconnu',
            lignes: [
                ['compte_comptable_id' => $this->caisseAgent->compte_comptable_id, 'journal_role' => 'role_inexistant', 'sens' => 'debit', 'montant' => 100],
                ['role' => 'client', 'sens' => 'credit', 'montant' => 100],
            ],
        );
    }

    public function test_deux_comptes_imposes_sans_journal_role_restent_refuses(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Aucun journal résolu');

        app(EcritureComptableService::class)->comptabiliser(
            evenement: EvenementComptable::ENCAISSEMENT_VENTE_RECU,
            source: $this->caisseAgent,
            organizationId: $this->org->id,
            dateComptable: Carbon::now(),
            libelle: 'Test sans journal',
            lignes: [
                ['compte_comptable_id' => $this->caisseAgent->compte_comptable_id, 'sens' => 'debit', 'montant' => 100],
                ['compte_comptable_id' => $this->caisseAgence->compte_comptable_id, 'sens' => 'credit', 'montant' => 100],
            ],
        );
    }

    // ── Contrepassation, financement, situation ──────────────────────────────

    public function test_la_suppression_d_un_encaissement_contrepasse_sur_la_meme_caisse(): void
    {
        $encaissement = $this->encaisser($this->agent, 100_000);
        $this->assertSame(100_000.0, $this->disponibilite->soldePourSupport($this->caisseAgent));

        $encaissement->delete();

        $this->assertSame(0.0, $this->disponibilite->soldePourSupport($this->caisseAgent), 'l\'extourne vise le sous-compte de la caisse');
        $this->assertSame(0.0, $this->disponibilite->soldePourSupport($this->caisseAgence));
    }

    public function test_l_argent_encaisse_par_un_agent_reste_dans_la_situation_mais_pas_dans_le_disponible(): void
    {
        $soldes = app(SoldeOuvertureTresorerieService::class);
        $soldes->valider(
            $soldes->enregistrer($this->org->id, $this->caisseAgence, ['date_situation' => '2026-08-01', 'montant' => 1_000_000], $this->user->id),
            $this->user->id,
        );

        $this->encaisser($this->agent, 250_000);

        $this->assertSame(1_000_000.0, $this->disponibilite->disponiblePourSite($this->org->id, $this->site->id, Carbon::now()));

        $situation = $this->disponibilite->situationParSupport($this->org->id, Carbon::now())->keyBy('compte_tresorerie_id');
        $this->assertSame(1_000_000.0, $situation[$this->caisseAgence->id]['solde']);
        $this->assertSame(250_000.0, $situation[$this->caisseAgent->id]['solde']);
    }

    // ── Isolation entre organisations ────────────────────────────────────────

    public function test_la_caisse_d_une_autre_organisation_n_est_jamais_ciblee(): void
    {
        $autreOrg = Organization::factory()->create();
        $siteEtranger = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Étranger', 'type' => 'agence', 'localisation' => 'Labé']);
        $factureEtrangere = $this->facture($siteEtranger, $autreOrg);

        // Même auteur (notre agent), mais facture d'une autre organisation.
        $encaissement = $this->encaisser($this->agent, 60_000, 'especes', $factureEtrangere);

        $piece = PieceComptable::where('source_id', $encaissement->id)->firstOrFail();
        $this->assertSame($autreOrg->id, $piece->organization_id);
        $comptes = $piece->lignes()->with('compte')->get();
        $this->assertSame('571000', $comptes->firstWhere('debit', '>', 0)->compte->numero);
        $this->assertSame($autreOrg->id, $comptes->firstWhere('debit', '>', 0)->compte->organization_id);
        $this->assertSame(0.0, $this->disponibilite->soldePourSupport($this->caisseAgent));
    }

    // ── Parcours HTTP complet ────────────────────────────────────────────────

    public function test_l_encaissement_enregistre_via_l_ecran_alimente_la_caisse_de_l_agent_connecte(): void
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create(['organization_id' => $this->org->id, 'proprietaire_id' => $proprietaire->id]);
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'total_commande' => 5_000,
        ]);
        $facture = FactureVente::factory()->create([
            'organization_id' => $this->org->id,
            'commande_vente_id' => $commande->id,
            'site_id' => $this->site->id,
            'montant_net' => 5_000,
        ]);

        $encaisseur = $this->makeUserWithPermissions($this->org, ['ventes.update', 'factures.encaisser']);
        $encaisseur->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);
        $caisse = $this->creerCaisseActive($this->site->id, $encaisseur->id);

        $this->actingAs($encaisseur)
            ->post(route('encaissements.store', $facture), [
                'montant' => 5_000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ])
            ->assertSessionHasNoErrors();

        $encaissement = EncaissementVente::where('facture_vente_id', $facture->id)->firstOrFail();
        $this->assertSame($encaisseur->id, $encaissement->created_by);
        $this->assertSame(5_000.0, $this->lignes($encaissement)[$caisse->compte->numero]['debit']);
        $this->assertSame(5_000.0, $this->disponibilite->soldePourSupport($caisse));
        $this->assertSame(0.0, $this->disponibilite->soldePourSupport($this->caisseAgence));
    }
}
