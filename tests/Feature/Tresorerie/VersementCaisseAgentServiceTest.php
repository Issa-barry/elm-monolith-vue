<?php

namespace Tests\Feature\Tresorerie;

use App\Enums\NatureMouvementFonds;
use App\Enums\StatutMouvementFonds;
use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\EcritureComptable;
use App\Models\MouvementFonds;
use App\Models\Organization;
use App\Models\PieceComptable;
use App\Models\Site;
use App\Models\User;
use App\Services\Tresorerie\CaisseAgentService;
use App\Services\Tresorerie\MouvementFondsService;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Phase 3 du chantier caisses dédiées (ADR 0001) : versement d'une caisse dédiée à un agent vers
 * une caisse de l'agence, en deux étapes — ENVOYÉ (la caisse de l'agent baisse, l'argent passe par
 * le compte de transit) puis REÇU (la caisse de l'agence augmente), confirmé par un AUTRE
 * utilisateur. Verrouille : écritures, contrôle de solde, destination, séparation
 * envoi/réception (dérogation super admin), contestation/retour, désactivation, et l'absence
 * d'effet sur le Financement (un versement interne n'est pas un financement du siège).
 */
class VersementCaisseAgentServiceTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, RefreshDatabase;

    private MouvementFondsService $service;

    private TresorerieDisponibiliteService $disponibilite;

    private Site $site;

    private CompteTresorerie $caisseAgence;

    private CompteTresorerie $caisseAgent;

    private User $agent;

    private User $envoyeur;

    private User $receveur;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['tresorerie.read', 'tresorerie.verser']);
        $this->service = app(MouvementFondsService::class);
        $this->disponibilite = app(TresorerieDisponibiliteService::class);
        $this->site = $this->user->sites()->first();
        $this->envoyeur = $this->user;
        $this->receveur = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.recevoir', 'tresorerie.rejeter']);

        $this->caisseAgence = $this->creerCaisseAgence($this->site, 'Caisse principale');
        $this->agent = $this->creerAgent($this->site);
        $this->caisseAgent = $this->creerCaisseActive($this->site->id, $this->agent->id);
        $this->alimenterCaisse($this->caisseAgent, 1_150_000);
    }

    private function creerCaisseAgence(Site $site, string $libelle, string $numeroCompte = '571000', string $type = 'caisse'): CompteTresorerie
    {
        return CompteTresorerie::create([
            'organization_id' => $this->org->id,
            'site_id' => $site->id,
            'compte_comptable_id' => CompteComptable::where('organization_id', $this->org->id)->where('numero', $numeroCompte)->firstOrFail()->id,
            'type' => $type,
            'libelle' => $libelle,
        ]);
    }

    private function verser(float $montant = 800_000, ?User $auteur = null, ?CompteTresorerie $destination = null, ?string $motif = 'Versement caisse agent'): MouvementFonds
    {
        return $this->service->verserCaisseAgent(
            $this->org->id,
            $this->caisseAgent,
            ($destination ?? $this->caisseAgence)->id,
            $montant,
            $motif,
            ($auteur ?? $this->envoyeur)->id,
        );
    }

    /** @return array<string, array{debit: float, credit: float}> */
    private function lignesDeLaPiece(?string $pieceId): array
    {
        return PieceComptable::findOrFail($pieceId)->lignes()->with('compte')->get()
            ->mapWithKeys(fn ($l) => [$l->compte->numero => ['debit' => (float) $l->debit, 'credit' => (float) $l->credit]])
            ->all();
    }

    private function soldeDuCompte(string $numero): float
    {
        return round((float) EcritureComptable::whereHas('compte', fn ($q) => $q->where('organization_id', $this->org->id)->where('numero', $numero))
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) as solde')
            ->value('solde'), 2);
    }

    private function solde(CompteTresorerie $support): float
    {
        return $this->disponibilite->soldePourSupport($support);
    }

    private function superAdmin(): User
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $superAdmin->assignRole('super_admin');
        $superAdmin->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => false]);

        return $superAdmin;
    }

    // ── Envoi ────────────────────────────────────────────────────────────────

    public function test_le_versement_est_envoye_et_diminue_la_caisse_de_l_agent(): void
    {
        $mouvement = $this->verser(800_000);

        $this->assertSame(NatureMouvementFonds::INTERNE_CAISSES, $mouvement->nature);
        $this->assertSame(StatutMouvementFonds::ENVOYE, $mouvement->statut);
        $this->assertSame($this->site->id, $mouvement->site_origine_id);
        $this->assertSame($this->site->id, $mouvement->site_destination_id);
        $this->assertSame($this->caisseAgent->id, $mouvement->compte_tresorerie_origine_id);
        $this->assertSame($this->caisseAgence->id, $mouvement->compte_tresorerie_destination_id);
        $this->assertEquals(800_000, $mouvement->montant);
        $this->assertSame('Versement caisse agent', $mouvement->commentaire);
        $this->assertSame('especes', $mouvement->moyen_transfert);
        $this->assertSame($this->envoyeur->id, $mouvement->sent_by);
        $this->assertSame($this->envoyeur->id, $mouvement->created_by);
        $this->assertSame(now()->toDateString(), $mouvement->date_envoi->toDateString());
        $this->assertNull($mouvement->received_by);
        $this->assertMatchesRegularExpression('/^MVT-\d{4}-\d{5}$/', $mouvement->reference);

        // Caisse de l'agent : 1 150 000 − 800 000. Caisse de l'agence : pas encore créditée.
        $this->assertSame(350_000.0, $this->solde($this->caisseAgent));
        $this->assertSame(0.0, $this->solde($this->caisseAgence));
    }

    public function test_l_envoi_passe_par_le_compte_de_transit_sans_produit_ni_charge(): void
    {
        $mouvement = $this->verser(800_000);

        $lignes = $this->lignesDeLaPiece($mouvement->piece_comptable_envoi_id);

        $this->assertSame([
            '588000' => ['debit' => 800_000.0, 'credit' => 0.0],
            $this->caisseAgent->compte->numero => ['debit' => 0.0, 'credit' => 800_000.0],
        ], $lignes);
        $this->assertSame(800_000.0, $this->soldeDuCompte('588000'), 'l\'argent est en transit');
    }

    public function test_le_motif_est_facultatif(): void
    {
        $this->assertNull($this->verser(100_000, null, null, null)->commentaire);
        $this->assertNull($this->verser(100_000, null, null, '   ')->commentaire);
    }

    // ── Réception ────────────────────────────────────────────────────────────

    public function test_la_reception_par_un_autre_utilisateur_credite_la_caisse_de_l_agence(): void
    {
        $mouvement = $this->verser(800_000);

        $recu = $this->service->recevoir($mouvement, $this->receveur->id, $this->caisseAgence->id);

        $this->assertSame(StatutMouvementFonds::RECU, $recu->statut);
        $this->assertSame($this->receveur->id, $recu->received_by);
        $this->assertSame($this->envoyeur->id, $recu->sent_by, 'l\'auteur de l\'envoi est conservé');
        $this->assertSame(now()->toDateString(), $recu->date_reception->toDateString());

        $this->assertSame([
            $this->caisseAgence->compte->numero => ['debit' => 800_000.0, 'credit' => 0.0],
            '588000' => ['debit' => 0.0, 'credit' => 800_000.0],
        ], $this->lignesDeLaPiece($recu->piece_comptable_reception_id));

        $this->assertSame(350_000.0, $this->solde($this->caisseAgent));
        $this->assertSame(800_000.0, $this->solde($this->caisseAgence));
        $this->assertSame(0.0, $this->soldeDuCompte('588000'), 'le transit est soldé');
    }

    public function test_apres_reception_la_situation_et_le_disponible_refletent_le_versement(): void
    {
        $this->service->recevoir($this->verser(800_000), $this->receveur->id, $this->caisseAgence->id);

        $situation = $this->disponibilite->situationParSupport($this->org->id, Carbon::now())->keyBy('compte_tresorerie_id');

        $this->assertSame(350_000.0, $situation[$this->caisseAgent->id]['solde']);
        $this->assertSame(800_000.0, $situation[$this->caisseAgence->id]['solde']);
        $this->assertSame(800_000.0, $this->disponibilite->disponiblePourSite($this->org->id, $this->site->id, Carbon::now()), 'l\'argent versé redevient disponible pour l\'agence');
    }

    public function test_avant_reception_le_disponible_de_l_agence_n_augmente_pas(): void
    {
        $this->verser(800_000);

        $this->assertSame(0.0, $this->disponibilite->disponiblePourSite($this->org->id, $this->site->id, Carbon::now()));
    }

    public function test_l_envoyeur_ne_peut_pas_confirmer_la_reception(): void
    {
        $mouvement = $this->verser(800_000);

        $this->assertErreurValidationSur('compte_tresorerie_destination_id', fn () => $this->service->recevoir($mouvement, $this->envoyeur->id, $this->caisseAgence->id));

        $mouvement->refresh();
        $this->assertSame(StatutMouvementFonds::ENVOYE, $mouvement->statut);
        $this->assertNull($mouvement->piece_comptable_reception_id);
        $this->assertSame(0.0, $this->solde($this->caisseAgence));
    }

    public function test_le_super_admin_peut_confirmer_son_propre_versement_et_l_exception_reste_tracee(): void
    {
        $superAdmin = $this->superAdmin();
        $mouvement = $this->verser(800_000, $superAdmin);

        $recu = $this->service->recevoir($mouvement, $superAdmin->id, $this->caisseAgence->id);

        $this->assertSame(StatutMouvementFonds::RECU, $recu->statut);
        $this->assertSame($superAdmin->id, $recu->sent_by);
        $this->assertSame($superAdmin->id, $recu->received_by, 'envoyeur et receveur sont tous deux enregistrés');
        $this->assertSame(800_000.0, $this->solde($this->caisseAgence));
    }

    public function test_seul_le_super_admin_deroge_pas_un_admin_entreprise(): void
    {
        // $this->envoyeur est admin_entreprise (isAdmin) mais pas super admin.
        $this->assertTrue($this->envoyeur->isAdmin());
        $this->assertFalse($this->envoyeur->isSuperAdmin());

        $mouvement = $this->verser(100_000);

        $this->assertErreurValidationSur('compte_tresorerie_destination_id', fn () => $this->service->recevoir($mouvement, $this->envoyeur->id, $this->caisseAgence->id));
    }

    // ── Contrôles ────────────────────────────────────────────────────────────

    public function test_un_montant_superieur_au_solde_est_refuse_sans_rien_ecrire(): void
    {
        $piecesAvant = PieceComptable::count();

        try {
            $this->verser(1_150_001);
            $this->fail('ValidationException attendue');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('montant', $e->errors());
            $this->assertStringContainsString('1 150 000', $e->errors()['montant'][0]);
        }

        $this->assertSame(0, MouvementFonds::count(), 'aucun mouvement ne subsiste');
        $this->assertSame($piecesAvant, PieceComptable::count());
        $this->assertSame(1_150_000.0, $this->solde($this->caisseAgent));
    }

    public function test_le_versement_du_solde_complet_est_accepte(): void
    {
        $this->verser(1_150_000);

        $this->assertSame(0.0, $this->solde($this->caisseAgent));
    }

    public function test_deux_versements_successifs_relisent_le_solde_deja_diminue(): void
    {
        $this->verser(800_000);

        $this->assertErreurValidationSur('montant', fn () => $this->verser(800_000));
        $this->assertSame(1, MouvementFonds::count());
        $this->assertSame(350_000.0, $this->solde($this->caisseAgent));

        $this->verser(350_000);
        $this->assertSame(0.0, $this->solde($this->caisseAgent));
    }

    public function test_un_montant_nul_ou_negatif_est_refuse(): void
    {
        $this->assertErreurValidationSur('montant', fn () => $this->verser(0));
        $this->assertErreurValidationSur('montant', fn () => $this->verser(-50));
        $this->assertSame(0, MouvementFonds::count());
    }

    public function test_les_destinations_invalides_sont_refusees(): void
    {
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kouria', 'type' => 'agence', 'localisation' => 'Coyah']);
        $autreAgence = $this->creerCaisseAgence($autreSite, 'Caisse Kouria');
        $banque = $this->creerCaisseAgence($this->site, 'UBA', '521000', 'banque');
        $desactivee = $this->creerCaisseAgence($this->site, 'Ancienne caisse');
        $desactivee->update(['actif' => false]);
        $collegue = $this->creerAgent($this->site, 'Abdoulaye', 'Diallo');
        $caisseCollegue = $this->creerCaisseActive($this->site->id, $collegue->id);

        $autreOrg = Organization::factory()->create();
        $siteEtranger = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Étranger', 'type' => 'agence', 'localisation' => 'Labé']);
        $caisseEtrangere = CompteTresorerie::create([
            'organization_id' => $autreOrg->id,
            'site_id' => $siteEtranger->id,
            'compte_comptable_id' => CompteComptable::where('organization_id', $autreOrg->id)->where('numero', '571000')->firstOrFail()->id,
            'type' => 'caisse',
            'libelle' => 'Caisse étrangère',
        ]);

        foreach ([
            'autre agence' => $autreAgence->id,
            'compte bancaire' => $banque->id,
            'caisse désactivée' => $desactivee->id,
            'caisse d\'un agent' => $caisseCollegue->id,
            'autre organisation' => $caisseEtrangere->id,
            'identifiant inconnu' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        ] as $cas => $destinationId) {
            try {
                $this->service->verserCaisseAgent($this->org->id, $this->caisseAgent, $destinationId, 100_000, null, $this->envoyeur->id);
                $this->fail("La destination « {$cas} » aurait dû être refusée.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('compte_tresorerie_destination_id', $e->errors(), $cas);
            }
        }

        $this->assertSame(0, MouvementFonds::count());
    }

    public function test_les_sources_invalides_sont_refusees(): void
    {
        // Une caisse d'agence n'est pas versable.
        try {
            $this->service->verserCaisseAgent($this->org->id, $this->caisseAgence, $this->caisseAgence->id, 100, null, $this->envoyeur->id);
            $this->fail('ValidationException attendue');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('compte_tresorerie_id', $e->errors());
        }

        // Une caisse dédiée désactivée non plus.
        $this->caisseAgent->update(['actif' => false]);
        $this->assertErreurValidationSur('compte_tresorerie_id', fn () => $this->verser(100_000));

        // Ni la caisse d'une autre organisation.
        $autreOrg = Organization::factory()->create();
        $this->assertErreurValidationSur('compte_tresorerie_id', fn () => $this->service->verserCaisseAgent($autreOrg->id, $this->caisseAgent, $this->caisseAgence->id, 100, null, $this->envoyeur->id));

        $this->assertSame(0, MouvementFonds::count());
    }

    public function test_la_reception_refuse_une_autre_caisse_ou_une_caisse_desactivee(): void
    {
        $mouvement = $this->verser(800_000);
        $autreCaisse = $this->creerCaisseAgence($this->site, 'Seconde caisse');

        $this->assertErreurValidationSur('compte_tresorerie_destination_id', fn () => $this->service->recevoir($mouvement, $this->receveur->id, $autreCaisse->id));

        $this->caisseAgence->update(['actif' => false]);
        $this->assertErreurValidationSur('compte_tresorerie_destination_id', fn () => $this->service->recevoir($mouvement, $this->receveur->id, $this->caisseAgence->id));

        $this->caisseAgence->update(['actif' => true]);
        $this->assertSame(StatutMouvementFonds::RECU, $this->service->recevoir($mouvement, $this->receveur->id, $this->caisseAgence->id)->statut);
    }

    // ── Contestation et retour ───────────────────────────────────────────────

    public function test_contestation_puis_retour_recredite_la_caisse_de_l_agent(): void
    {
        $mouvement = $this->verser(800_000);

        $conteste = $this->service->contester($mouvement, $this->receveur->id, 'Rien reçu');
        $this->assertSame(StatutMouvementFonds::CONTESTE, $conteste->statut);
        $this->assertSame(350_000.0, $this->solde($this->caisseAgent), 'une contestation ne contrepasse rien');

        $retourne = $this->service->confirmerRetour($conteste, $this->envoyeur->id, 'Les fonds sont revenus');

        $this->assertSame(StatutMouvementFonds::RETOURNE, $retourne->statut);
        $this->assertSame(1_150_000.0, $this->solde($this->caisseAgent));
        $this->assertSame(0.0, $this->solde($this->caisseAgence));
        $this->assertSame(0.0, $this->soldeDuCompte('588000'));
    }

    public function test_une_contestation_peut_etre_levee_par_une_reception(): void
    {
        $conteste = $this->service->contester($this->verser(800_000), $this->receveur->id, 'Doute');

        $recu = $this->service->recevoir($conteste, $this->receveur->id, $this->caisseAgence->id);

        $this->assertSame(StatutMouvementFonds::RECU, $recu->statut);
        $this->assertSame(800_000.0, $this->solde($this->caisseAgence));
    }

    public function test_l_envoyeur_ne_peut_pas_contester_son_propre_versement(): void
    {
        $mouvement = $this->verser(800_000);

        $this->assertErreurValidationSur('motif', fn () => $this->service->contester($mouvement, $this->envoyeur->id, 'Erreur'));
        $this->assertSame(StatutMouvementFonds::ENVOYE, $mouvement->fresh()->statut);

        $this->assertSame(
            StatutMouvementFonds::CONTESTE,
            $this->service->contester($mouvement, $this->superAdmin()->id, 'Contrôle')->statut,
            'un autre utilisateur, ici un super admin, peut contester',
        );
    }

    // ── Désactivation d'une caisse ───────────────────────────────────────────

    public function test_une_caisse_ne_peut_pas_etre_desactivee_pendant_un_versement_en_cours(): void
    {
        $mouvement = $this->verser(1_150_000); // la caisse est vide : seul le versement en cours bloque
        $caisses = app(CaisseAgentService::class);

        $this->assertErreurValidationSur('actif', fn () => $caisses->mettreAJour($this->caisseAgent, ['libelle' => $this->caisseAgent->libelle, 'actif' => false]));
        $this->assertTrue($this->caisseAgent->fresh()->actif);

        $this->service->recevoir($mouvement, $this->receveur->id, $this->caisseAgence->id);

        $caisses->mettreAJour($this->caisseAgent, ['libelle' => $this->caisseAgent->libelle, 'actif' => false]);
        $this->assertFalse($this->caisseAgent->fresh()->actif);
    }

    // ── Non-régression : mouvements entre agences et Financement ─────────────

    public function test_un_mouvement_entre_agences_reste_de_nature_inter_sites_et_refuse_le_meme_site(): void
    {
        $siege = Site::create(['organization_id' => $this->org->id, 'nom' => 'Siège', 'type' => 'siege', 'localisation' => 'Conakry']);
        $caisseSiege = $this->creerCaisseAgence($siege, 'Caisse Siège');

        $mouvement = $this->service->creerBrouillon($this->org->id, [
            'site_origine_id' => $siege->id,
            'site_destination_id' => $this->site->id,
            'compte_tresorerie_origine_id' => $caisseSiege->id,
            'montant' => 100_000,
        ], $this->envoyeur->id);

        $this->assertSame(NatureMouvementFonds::INTER_SITES, $mouvement->fresh()->nature);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->creerBrouillon($this->org->id, [
            'site_origine_id' => $this->site->id,
            'site_destination_id' => $this->site->id,
            'compte_tresorerie_origine_id' => $this->caisseAgence->id,
            'montant' => 100_000,
        ], $this->envoyeur->id);
    }

    public function test_un_versement_interne_n_est_ni_un_financement_en_transit_ni_un_deja_finance(): void
    {
        $debut = Carbon::now()->startOfMonth();
        $fin = Carbon::now()->endOfMonth();

        // Témoin : un vrai envoi du siège vers l'agence, en transit, est bien compté.
        $siege = Site::create(['organization_id' => $this->org->id, 'nom' => 'Siège', 'type' => 'siege', 'localisation' => 'Conakry']);
        $caisseSiege = $this->creerCaisseAgence($siege, 'Caisse Siège');
        $financement = $this->service->envoyer($this->service->creerBrouillon($this->org->id, [
            'site_origine_id' => $siege->id,
            'site_destination_id' => $this->site->id,
            'compte_tresorerie_origine_id' => $caisseSiege->id,
            'montant' => 50_000,
        ], $this->envoyeur->id), $this->envoyeur->id);
        $this->assertSame(50_000.0, $this->disponibilite->fondsEnTransitVersSite($this->org->id, $this->site->id, $debut, $fin));

        // Versement interne envoyé : toujours 50 000 (le versement n'ajoute rien).
        $versement = $this->verser(800_000);
        $this->assertSame(50_000.0, $this->disponibilite->fondsEnTransitVersSite($this->org->id, $this->site->id, $debut, $fin));

        // Les deux reçus : seul le financement du siège est « déjà financé ».
        $this->service->recevoir($financement, $this->receveur->id, $this->caisseAgence->id);
        $this->service->recevoir($versement, $this->receveur->id, $this->caisseAgence->id);
        $this->assertSame(50_000.0, $this->disponibilite->dejaFinancePourSite($this->org->id, $this->site->id, $debut, $fin));
        $this->assertSame(0.0, $this->disponibilite->fondsEnTransitVersSite($this->org->id, $this->site->id, $debut, $fin));
    }
}
