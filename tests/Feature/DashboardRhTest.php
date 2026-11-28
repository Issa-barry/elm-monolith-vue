<?php

namespace Tests\Feature;

use App\Features\ModuleFeature;
use App\Features\TableauDeBordFeature;
use App\Models\Absence;
use App\Models\Contrat;
use App\Models\Employe;
use App\Models\FonctionRh;
use App\Models\MotifAbsence;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Tableau de bord RH, Stat-RH lot R1 (docs/tableau-de-bord-rh.md) : effectifs, contrats, absences
 * et alertes ; un bloc par permission de liste RH ; périmètre d'agences des listes RH ; absences
 * en jours calendaires sur les jours écoulés ; absence injustifiée = motif « Absence injustifiée ».
 */
class DashboardRhTest extends TestCase
{
    use RefreshDatabase;

    private const DROITS = ['rh-employes.read', 'rh-contrats.read', 'rh-absences.read'];

    private Organization $org;

    private Site $matoto;

    private Site $cba;

    protected function setUp(): void
    {
        parent::setUp();
        // Jeudi 26 novembre 2026 : « Ce mois » = novembre, 26 jours écoulés.
        Carbon::setTestNow('2026-11-26 10:00:00');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->org = Organization::factory()->create();
        // Désactivé par défaut (ADR 0071) : refus couvert par TableauxDeBordActivationTest.
        Feature::for($this->org)->activate(TableauDeBordFeature::RH);
        $this->matoto = Site::create(['organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'depot']);
        $this->cba = Site::create(['organization_id' => $this->org->id, 'nom' => 'CBA', 'type' => 'depot']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Fabriques ─────────────────────────────────────────────────────────────────────────

    private function utilisateur(?Site $site, array $permissions = self::DROITS, string $role = 'admin_entreprise', ?Organization $org = null): User
    {
        $org ??= $this->org;
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        foreach ([...self::DROITS, ...$permissions] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $user = User::factory()->create(['organization_id' => $org->id]);
        $user->assignRole($role);
        $user->givePermissionTo($permissions);
        if ($site !== null) {
            $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);
        }

        return $user;
    }

    private function employe(?Site $site, array $attributs = [], ?Organization $org = null): Employe
    {
        $org ??= $this->org;
        $personne = Personne::create(['organization_id' => $org->id, 'nom' => 'DIALLO', 'prenom' => fake()->unique()->firstName(),
            'telephone' => '+22462'.fake()->unique()->numerify('#######')]);

        return Employe::create(['organization_id' => $org->id, 'personne_id' => $personne->id,
            'matricule' => fake()->unique()->numerify('######'), 'type_employe' => 'interne', 'statut' => 'actif',
            'site_id' => $site?->id, ...$attributs]);
    }

    private function motif(string $code = 'maladie', ?Organization $org = null): MotifAbsence
    {
        return MotifAbsence::where('organization_id', ($org ?? $this->org)->id)->where('code', $code)->firstOrFail();
    }

    private function absence(Employe $employe, string $debut, string $fin, string $motif = 'maladie', string $statut = 'enregistree'): Absence
    {
        return Absence::create([
            'organization_id' => $employe->organization_id,
            'employe_id' => $employe->id,
            'motif_absence_id' => $this->motif($motif, Organization::find($employe->organization_id))->id,
            'date_debut' => $debut,
            'date_fin' => $fin,
            'statut' => $statut,
        ]);
    }

    private function contrat(Employe $employe, string $type, string $debut, ?string $fin = null, string $statut = 'actif'): Contrat
    {
        return Contrat::create([
            'organization_id' => $employe->organization_id,
            'employe_id' => $employe->id,
            'type_contrat' => $type,
            'date_debut' => $debut,
            'date_fin' => $fin,
            'salaire_base' => 1_500_000,
            'statut_contrat' => $statut,
        ]);
    }

    private function page(User $user, array $parametres = []): array
    {
        $donnees = [];
        $this->actingAs($user)
            ->get(route('dashboard.rh', $parametres))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$donnees) {
                $page->component('DashboardRh');
                $donnees = $page->toArray()['props'];
            });

        return $donnees;
    }

    // ── Accès ─────────────────────────────────────────────────────────────────────────────

    public function test_refuse_sans_aucune_liste_rh_consultable(): void
    {
        $user = $this->utilisateur($this->matoto, ['produits.read']);

        $this->actingAs($user)->get(route('dashboard.rh'))->assertForbidden();
    }

    public function test_refuse_quand_le_module_rh_est_desactive(): void
    {
        Feature::for($this->org)->deactivate(ModuleFeature::RH);

        $this->actingAs($this->utilisateur($this->matoto))->get(route('dashboard.rh'))->assertForbidden();
    }

    /**
     * Limite assumée : un salarié muté est compté entièrement dans son agence actuelle, absences
     * passées comprises (comme la liste des absences) ; l'historique des affectations n'est pas lu.
     */
    public function test_un_salarie_mute_est_compte_dans_son_agence_actuelle(): void
    {
        $mute = $this->employe($this->matoto, ['date_embauche' => '2024-01-01']);
        // Absence prise à Matoto, avant la mutation.
        $this->absence($mute, '2026-11-02', '2026-11-04');
        $mute->update(['site_id' => $this->cba->id]);

        $admin = $this->utilisateur($this->matoto);
        $this->assertSame(0, $this->page($admin, ['site_id' => $this->matoto->id])['absences']['jours']);
        $cba = $this->page($admin, ['site_id' => $this->cba->id]);
        $this->assertSame(3, $cba['absences']['jours']);
        $this->assertSame(26, $cba['absences']['jours_attendus']);
        // « Toutes les agences » : total inchangé.
        $this->assertSame(3, $this->page($admin)['absences']['jours']);
    }

    public function test_chaque_bloc_suit_la_permission_de_sa_liste(): void
    {
        $this->employe($this->matoto);

        $absences = $this->page($this->utilisateur($this->matoto, ['rh-absences.read']));
        $this->assertSame(['effectifs' => false, 'contrats' => false, 'absences' => true], $absences['blocs']);
        $this->assertNull($absences['effectifs']);
        $this->assertNull($absences['effectifs_agences']);
        $this->assertNull($absences['a_completer']);
        $this->assertNull($absences['contrats']);
        $this->assertNull($absences['fins_cdd']);
        $this->assertNotNull($absences['absences']);

        $employes = $this->page($this->utilisateur($this->matoto, ['rh-employes.read']));
        $this->assertSame(['effectifs' => true, 'contrats' => false, 'absences' => false], $employes['blocs']);
        $this->assertSame(1, $employes['effectifs']['actifs']);
        $this->assertNull($employes['absences']);
        $this->assertNull($employes['contrats']);
    }

    // ── Effectifs ─────────────────────────────────────────────────────────────────────────

    public function test_effectifs_entrees_sorties_et_dossiers_a_completer(): void
    {
        $fonction = FonctionRh::create(['organization_id' => $this->org->id, 'libelle' => 'Livreur', 'code' => 'LIV']);
        $this->employe($this->matoto, ['date_embauche' => '2026-11-03', 'fonction_rh_id' => $fonction->id]);
        $this->employe($this->matoto, ['date_embauche' => '2025-01-10']);
        $this->employe($this->cba, ['statut' => 'suspendu']);
        $this->employe($this->cba, ['statut' => 'sorti', 'date_embauche' => '2024-02-01', 'date_sortie' => '2026-11-15', 'motif_sortie' => 'Démission']);
        $this->employe($this->cba, ['statut' => 'sorti', 'date_embauche' => '2024-02-01', 'date_sortie' => '2026-09-30', 'motif_sortie' => 'Démission']);
        $this->employe(null);
        // Dossier supprimé : jamais compté.
        $this->employe($this->matoto)->delete();

        $p = $this->page($this->utilisateur($this->matoto));

        $this->assertSame(['actifs' => 3, 'suspendus' => 1, 'entrees' => 1, 'sorties' => 1], $p['effectifs']);
        $this->assertSame([
            'presents' => 4,
            'sans_date_embauche' => 2,
            'sans_fonction' => 3,
            'sans_service' => 4,
            'sans_agence' => 1,
            'sortis_sans_date' => 0,
        ], $p['a_completer']);
        $this->assertSame([
            ['site_id' => $this->matoto->id, 'site' => 'Matoto', 'actifs' => 2, 'suspendus' => 0, 'entrees' => 1, 'sorties' => 0],
            ['site_id' => null, 'site' => 'Sans agence', 'actifs' => 1, 'suspendus' => 0, 'entrees' => 0, 'sorties' => 0],
            ['site_id' => $this->cba->id, 'site' => 'CBA', 'actifs' => 0, 'suspendus' => 1, 'entrees' => 0, 'sorties' => 1],
        ], $p['effectifs_agences']);
    }

    public function test_perimetre_des_listes_rh_et_filtre_agence(): void
    {
        $this->employe($this->matoto);
        $this->employe($this->cba);
        $this->employe(null);
        $autreOrg = Organization::factory()->create();
        $this->employe(null, [], $autreOrg);

        // Responsable d'agence : ses agences seulement, sans les salariés sans agence.
        $responsable = $this->utilisateur($this->matoto, self::DROITS, 'manager');
        $p = $this->page($responsable);
        $this->assertSame(1, $p['effectifs']['actifs']);
        $this->assertTrue($p['perimetre_limite']);
        $this->assertSame([['value' => $this->matoto->id, 'label' => 'Matoto']], $p['agences']);

        $this->actingAs($responsable)
            ->get(route('dashboard.rh', ['site_id' => $this->cba->id]))
            ->assertSessionHasErrors(['site_id' => 'Agence inconnue ou hors de votre périmètre.']);

        // Administrateur : toute l'organisation, jamais une autre.
        $admin = $this->utilisateur($this->cba);
        $this->assertSame(3, $this->page($admin)['effectifs']['actifs']);
        $this->assertSame(1, $this->page($admin, ['site_id' => $this->cba->id])['effectifs']['actifs']);
    }

    // ── Contrats ──────────────────────────────────────────────────────────────────────────

    public function test_contrats_en_cours_salaries_sans_contrat_et_fins_de_cdd(): void
    {
        $cdi = $this->employe($this->matoto);
        $this->contrat($cdi, 'cdi', '2025-01-01');
        $bientot = $this->employe($this->matoto);
        $this->contrat($bientot, 'cdd', '2026-06-01', '2026-12-10');
        $depasse = $this->employe($this->cba);
        $this->contrat($depasse, 'cdd', '2026-05-01', '2026-11-20');
        $lointain = $this->employe($this->cba);
        $this->contrat($lointain, 'cdd', '2026-05-01', '2027-03-31');
        // Contrat terminé : le salarié actif reste sans contrat en cours.
        $sans = $this->employe($this->cba);
        $this->contrat($sans, 'cdd', '2025-01-01', '2025-12-31', 'termine');
        $this->employe($this->cba, ['statut' => 'suspendu']);

        $p = $this->page($this->utilisateur($this->matoto));

        $this->assertSame(['cdi' => 1, 'cdd' => 3, 'sans_contrat' => 1], $p['contrats']);
        $this->assertCount(2, $p['fins_cdd']);
        $this->assertSame($depasse->id, $p['fins_cdd'][0]['employe_id']);
        $this->assertSame('2026-11-20', $p['fins_cdd'][0]['date_fin']);
        $this->assertSame(-6, $p['fins_cdd'][0]['jours']);
        $this->assertSame('echeance_depassee', $p['fins_cdd'][0]['statut']);
        $this->assertSame('CBA', $p['fins_cdd'][0]['site']);
        $this->assertSame($bientot->id, $p['fins_cdd'][1]['employe_id']);
        $this->assertSame(14, $p['fins_cdd'][1]['jours']);
        $this->assertSame('expire_bientot', $p['fins_cdd'][1]['statut']);
        $this->assertSame('Se termine dans 14 jours', $p['fins_cdd'][1]['statut_label']);

        // Sans accès aux fiches salariés : pas de lien vers la fiche, ni blocs Employés ou Absences.
        $contratsSeuls = $this->page($this->utilisateur($this->matoto, ['rh-contrats.read']));
        $this->assertNull($contratsSeuls['fins_cdd'][0]['employe_id']);
        $this->assertSame(['effectifs' => false, 'contrats' => true, 'absences' => false], $contratsSeuls['blocs']);
        $this->assertNull($contratsSeuls['effectifs']);
        $this->assertNull($contratsSeuls['a_completer']);
        $this->assertNull($contratsSeuls['absences']);
    }

    public function test_bornes_de_l_alerte_de_fin_de_cdd(): void
    {
        // Aujourd'hui : 26 novembre 2026.
        $cas = [
            'j30' => '2026-12-26',
            'j31' => '2026-12-27',
            'j0' => '2026-11-26',
            'veille' => '2026-11-25',
        ];
        $salaries = [];
        foreach ($cas as $cle => $fin) {
            $salaries[$cle] = $this->employe($this->matoto);
            $this->contrat($salaries[$cle], 'cdd', '2026-01-01', $fin);
        }

        $fins = collect($this->page($this->utilisateur($this->matoto))['fins_cdd'])->keyBy('employe_id');

        $this->assertCount(3, $fins);
        $this->assertFalse($fins->has($salaries['j31']->id));
        $this->assertSame(['expire_bientot', 'Se termine dans 30 jours', 30], [
            $fins[$salaries['j30']->id]['statut'], $fins[$salaries['j30']->id]['statut_label'], $fins[$salaries['j30']->id]['jours'],
        ]);
        $this->assertSame(['expire_bientot', 'Se termine aujourd’hui', 0], [
            $fins[$salaries['j0']->id]['statut'], $fins[$salaries['j0']->id]['statut_label'], $fins[$salaries['j0']->id]['jours'],
        ]);
        $this->assertSame(['echeance_depassee', 'Fin dépassée', -1], [
            $fins[$salaries['veille']->id]['statut'], $fins[$salaries['veille']->id]['statut_label'], $fins[$salaries['veille']->id]['jours'],
        ]);
    }

    public function test_un_cdd_renouvele_ne_declenche_plus_d_alerte(): void
    {
        // Ancien CDD terminé le 20, remplacé par un CDI actif : aucune alerte.
        $renouvele = $this->employe($this->matoto);
        $this->contrat($renouvele, 'cdd', '2026-05-01', '2026-11-20', 'termine');
        $this->contrat($renouvele, 'cdi', '2026-11-21');
        // CDD prolongé par sa date de fin : plus d'alerte non plus.
        $prolonge = $this->employe($this->matoto);
        $this->contrat($prolonge, 'cdd', '2026-05-01', '2026-11-30')->update(['date_fin' => '2027-05-31']);
        // Contrat rompu à échéance proche : jamais signalé.
        $rompu = $this->employe($this->matoto);
        $this->contrat($rompu, 'cdd', '2026-05-01', '2026-12-05', 'rompu');

        $p = $this->page($this->utilisateur($this->matoto));

        $this->assertSame([], $p['fins_cdd']);
        $this->assertSame(['cdi' => 1, 'cdd' => 1, 'sans_contrat' => 1], $p['contrats']);
    }

    public function test_contrats_limites_a_l_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $etranger = $this->employe(null, [], $autreOrg);
        $this->contrat($etranger, 'cdd', '2026-01-01', '2026-11-30');
        $this->employe($this->matoto);

        $p = $this->page($this->utilisateur($this->matoto));

        $this->assertSame([], $p['fins_cdd']);
        $this->assertSame(['cdi' => 0, 'cdd' => 0, 'sans_contrat' => 1], $p['contrats']);
    }

    // ── Absences ──────────────────────────────────────────────────────────────────────────

    public function test_absences_en_jours_calendaires_sur_les_jours_ecoules(): void
    {
        $a = $this->employe($this->matoto, ['date_embauche' => '2024-01-01']);
        $b = $this->employe($this->matoto, ['date_embauche' => '2024-01-01']);
        // Du 28 octobre au 3 novembre : 3 jours en novembre.
        $this->absence($a, '2026-10-28', '2026-11-03');
        // 2 jours injustifiés.
        $this->absence($a, '2026-11-10', '2026-11-11', 'absence_injustifiee');
        // En cours : du 24 au 28, 3 jours écoulés (24, 25, 26).
        $this->absence($b, '2026-11-24', '2026-11-28', 'absence_autorisee');
        // À venir dans le mois : pas encore comptée.
        $this->absence($b, '2026-11-30', '2026-11-30');
        // Annulée : jamais comptée.
        $this->absence($b, '2026-11-05', '2026-11-06', 'maladie', 'annulee');

        $p = $this->page($this->utilisateur($this->matoto))['absences'];

        $this->assertSame(8, $p['jours']);
        $this->assertSame(3, $p['absences']);
        $this->assertSame(2, $p['salaries']);
        // 2 salariés × 26 jours écoulés.
        $this->assertSame(52, $p['jours_attendus']);
        $this->assertEqualsWithDelta(8 / 52 * 100, $p['taux'], 0.0001);
        $this->assertNull($p['taux_motif']);
        $this->assertSame(2, $p['salaries_taux']);
        $this->assertSame(0, $p['salaries_exclus']);
        $this->assertSame(2, $p['injustifiees_jours']);
        $this->assertSame(1, $p['injustifiees_absences']);
        $this->assertSame(1, $p['absents_aujourdhui']);
        $this->assertSame(1, $p['a_venir']);
        $this->assertSame('2026-11-26', $p['jusqu_au']);
        $this->assertSame([
            ['libelle' => 'Absence autorisée', 'injustifiee' => false, 'absences' => 1, 'jours' => 3],
            ['libelle' => 'Maladie', 'injustifiee' => false, 'absences' => 1, 'jours' => 3],
            ['libelle' => 'Absence injustifiée', 'injustifiee' => true, 'absences' => 1, 'jours' => 2],
        ], array_map(fn (array $l) => array_diff_key($l, ['motif_id' => true]), $p['par_motif']));
    }

    public function test_jours_attendus_entre_embauche_et_sortie(): void
    {
        // Embauché le 20 : 7 jours (20 → 26).
        $this->employe($this->matoto, ['date_embauche' => '2026-11-20']);
        // Sorti le 10 : 10 jours (1 → 10).
        $this->employe($this->matoto, ['statut' => 'sorti', 'date_embauche' => '2024-01-01', 'date_sortie' => '2026-11-10', 'motif_sortie' => 'Fin']);
        // Suspendu : reste dans le calcul, faute de dates de suspension (26 jours).
        $this->employe($this->matoto, ['statut' => 'suspendu', 'date_embauche' => '2024-01-01']);
        // Sorti avant la période : aucun jour, ni retenu ni exclu.
        $this->employe($this->matoto, ['statut' => 'sorti', 'date_embauche' => '2024-01-01', 'date_sortie' => '2026-10-31', 'motif_sortie' => 'Fin']);

        $p = $this->page($this->utilisateur($this->matoto))['absences'];

        $this->assertSame(43, $p['jours_attendus']);
        $this->assertSame(3, $p['salaries_taux']);
        $this->assertSame(0, $p['salaries_exclus']);
        $this->assertSame(0, $p['jours']);
        // Aucune absence : 0 % est un vrai résultat, calculé sur des salariés retenus.
        $this->assertEqualsWithDelta(0.0, $p['taux'], 0.0001);
        $this->assertNull($p['taux_motif']);
    }

    public function test_taux_exclut_les_salaries_sans_periode_d_emploi_connue(): void
    {
        $retenu = $this->employe($this->matoto, ['date_embauche' => '2024-01-01']);
        $this->absence($retenu, '2026-11-02', '2026-11-04');
        $sansEmbauche = $this->employe($this->matoto);
        $this->absence($sansEmbauche, '2026-11-09', '2026-11-13');
        // Ancienne fiche « Sorti » sans date de sortie : exclue et signalée.
        $sortiSansDate = $this->employe($this->matoto, ['statut' => 'sorti', 'date_embauche' => '2024-01-01']);
        $this->absence($sortiSansDate, '2026-11-16', '2026-11-16');
        // Sans date d'embauche mais sorti avant la période : hors sujet, pas compté en exclu.
        $this->employe($this->matoto, ['statut' => 'sorti', 'date_sortie' => '2026-09-30', 'motif_sortie' => 'Fin']);

        $p = $this->page($this->utilisateur($this->matoto));
        $absences = $p['absences'];

        // Toutes les absences restent comptées dans les jours d'absence…
        $this->assertSame(9, $absences['jours']);
        // … mais le taux ne porte que sur le salarié retenu : 3 jours sur 26.
        $this->assertSame(26, $absences['jours_attendus']);
        $this->assertEqualsWithDelta(3 / 26 * 100, $absences['taux'], 0.0001);
        $this->assertSame(1, $absences['salaries_taux']);
        $this->assertSame(2, $absences['salaries_exclus']);
        $this->assertSame(1, $p['a_completer']['sortis_sans_date']);
        $this->assertSame(1, $p['a_completer']['sans_date_embauche']);
    }

    public function test_sans_salarie_retenu_le_taux_n_est_pas_zero_mais_non_calcule(): void
    {
        // Situation de la production au 26/11/2026 : aucune date d'embauche renseignée.
        $a = $this->employe($this->matoto);
        $this->employe($this->matoto);
        $this->employe($this->cba);
        $this->absence($a, '2026-11-02', '2026-11-03');

        $p = $this->page($this->utilisateur($this->matoto))['absences'];

        $this->assertSame(2, $p['jours']);
        $this->assertNull($p['taux']);
        $this->assertSame('aucun_salarie', $p['taux_motif']);
        $this->assertSame(0, $p['jours_attendus']);
        $this->assertSame(0, $p['salaries_taux']);
        $this->assertSame(3, $p['salaries_exclus']);
    }

    public function test_absences_limitees_a_la_periode_d_emploi(): void
    {
        // Date d'embauche corrigée après la saisie d'une absence du 5 au 12 : seuls 10, 11, 12 comptent.
        $embauche = $this->employe($this->matoto, ['date_embauche' => '2026-11-10']);
        $this->absence($embauche, '2026-11-05', '2026-11-12');
        // Sorti le 15 : absence du 14 au 20, seuls 14 et 15 comptent.
        $sorti = $this->employe($this->matoto, ['statut' => 'sorti', 'date_embauche' => '2024-01-01', 'date_sortie' => '2026-11-15', 'motif_sortie' => 'Fin']);
        $this->absence($sorti, '2026-11-14', '2026-11-20');

        $p = $this->page($this->utilisateur($this->matoto))['absences'];

        $this->assertSame(5, $p['jours']);
        // 17 jours (10 → 26) + 15 jours (1 → 15).
        $this->assertSame(32, $p['jours_attendus']);
        $this->assertEqualsWithDelta(5 / 32 * 100, $p['taux'], 0.0001);
        $this->assertSame(5, $p['par_motif'][0]['jours']);
    }

    public function test_detail_du_taux_masque_sans_acces_aux_employes(): void
    {
        $a = $this->employe($this->matoto, ['date_embauche' => '2024-01-01']);
        $this->employe($this->matoto);
        $this->absence($a, '2026-11-02', '2026-11-03');

        $p = $this->page($this->utilisateur($this->matoto, ['rh-absences.read']))['absences'];

        // Le taux reste affiché ; ce qui révèle l'effectif n'est pas envoyé.
        $this->assertEqualsWithDelta(2 / 26 * 100, $p['taux'], 0.0001);
        $this->assertNull($p['jours_attendus']);
        $this->assertNull($p['salaries_taux']);
        $this->assertNull($p['salaries_exclus']);
    }

    public function test_absence_injustifiee_suit_le_code_du_motif_pas_son_libelle(): void
    {
        $a = $this->employe($this->matoto, ['date_embauche' => '2024-01-01']);
        $this->motif('absence_injustifiee')->update(['libelle' => 'Absence non justifiée']);
        $this->motif('autre')->update(['libelle' => 'Absence injustifiée']);
        $this->absence($a, '2026-11-02', '2026-11-03', 'absence_injustifiee');
        $this->absence($a, '2026-11-09', '2026-11-09', 'autre');

        $p = $this->page($this->utilisateur($this->matoto))['absences'];

        $this->assertSame(2, $p['injustifiees_jours']);
        $this->assertSame(1, $p['injustifiees_absences']);
    }

    public function test_sans_periode_bornee_ni_periode_commencee_le_taux_n_est_pas_calcule(): void
    {
        $a = $this->employe($this->matoto);
        $this->absence($a, '2025-03-01', '2025-03-04');

        $tout = $this->page($this->utilisateur($this->matoto), ['periode' => 'tout'])['absences'];
        $this->assertSame(4, $tout['jours']);
        $this->assertNull($tout['taux']);
        $this->assertSame('periode', $tout['taux_motif']);
        $this->assertNull($tout['jours_attendus']);
        $this->assertNull($tout['salaries_taux']);

        $futur = $this->page($this->utilisateur($this->matoto), ['date_debut' => '2026-12-01', 'date_fin' => '2026-12-31'])['absences'];
        $this->assertSame(0, $futur['jours']);
        $this->assertNull($futur['taux']);
        $this->assertNull($futur['jusqu_au']);
    }

    public function test_absences_limitees_au_perimetre_et_a_l_organisation(): void
    {
        $matoto = $this->employe($this->matoto);
        $cba = $this->employe($this->cba);
        $this->absence($matoto, '2026-11-02', '2026-11-02');
        $this->absence($cba, '2026-11-02', '2026-11-05');
        $autreOrg = Organization::factory()->create();
        $etranger = $this->employe(null, [], $autreOrg);
        $this->absence($etranger, '2026-11-02', '2026-11-20');

        $responsable = $this->utilisateur($this->matoto, self::DROITS, 'manager');
        $this->assertSame(1, $this->page($responsable)['absences']['jours']);

        $admin = $this->utilisateur($this->cba);
        $this->assertSame(5, $this->page($admin)['absences']['jours']);
        $this->assertSame(4, $this->page($admin, ['site_id' => $this->cba->id])['absences']['jours']);
    }
}
