<?php

namespace Tests\Feature\Contacts;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionMode;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\CommissionUniteCalcul;
use App\Enums\FicheRattachable;
use App\Enums\PrestataireType;
use App\Features\ModuleFeature;
use App\Models\AuditLog;
use App\Models\CommissionCibleType;
use App\Models\CommissionConsultantAffectation;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\ContactSousCategorie;
use App\Models\Fournisseur;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Prestataire;
use App\Models\Site;
use App\Models\User;
use App\Services\Comptes\RattachementCompteService;
use App\Services\Contacts\QualificationsPrestataire;
use App\Support\Depenses\DepenseFormOptionsLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Lot 6b du référentiel Contacts (ADR 0060, CONTACT-038 à CONTACT-041) : qualifications multiples
 * des prestataires, critère unique Prestataire::qualifieConsultant() (seule la qualification système
 * Consultant ouvre l'éligibilité ; repli transitoire sur l'ancien `type` sans qualification
 * enregistrée), D15, synchronisation de `type`, reprise des qualifications par migration.
 */
class QualificationsPrestataireTest extends TestCase
{
    use RefreshDatabase;

    private const DROITS = ['prestataires.read', 'prestataires.create', 'prestataires.update'];

    private Organization $org;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        Feature::for($this->org)->activate(ModuleFeature::PRESTATAIRES);
    }

    private function utilisateur(array $permissions = self::DROITS, ?string $role = 'manager'): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole($role);
        $site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Agence '.$user->id, 'type' => 'depot', 'localisation' => 'Conakry']);
        $user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => true]);
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function qualification(string $code): ContactSousCategorie
    {
        return ContactSousCategorie::where('organization_id', $this->org->id)->where('categorie', 'prestataire')->where('code', $code)->firstOrFail();
    }

    private function qualificationLibre(string $code, string $libelle, bool $active = true): ContactSousCategorie
    {
        return ContactSousCategorie::create(['organization_id' => $this->org->id, 'categorie' => 'prestataire', 'code' => $code, 'libelle' => $libelle, 'is_active' => $active]);
    }

    /** Prestataire personne physique ; qualifications enregistrées si $codes est fourni. */
    private function prestataire(?string $type = 'machiniste', ?array $codes = null): Prestataire
    {
        $personne = Personne::factory()->create(['organization_id' => $this->org->id]);
        $p = Prestataire::create(['organization_id' => $this->org->id, 'personne_id' => $personne->id, 'type' => $type, 'is_active' => true]);
        foreach ($codes ?? [] as $code) {
            $p->sousCategories()->attach($this->qualification($code)->id);
        }

        return $p;
    }

    private function donnees(array $surcharges = []): array
    {
        return ['nom' => 'Camara', 'prenom' => 'Awa', 'phone' => '622000011', 'code_pays' => 'GN', 'ville' => 'Conakry', ...$surcharges];
    }

    private function processus(): CommissionProcessus
    {
        return CommissionProcessus::firstOrCreate(['organization_id' => $this->org->id, 'code' => CommissionProcessus::CODE_VENTE], [
            'libelle' => 'Vente', 'declencheur' => 'chargement_valide',
            'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);
    }

    private function bareme(Prestataire $consultant, ?string $fin = null, string $statut = 'active'): CommissionRegle
    {
        return CommissionRegle::create([
            'organization_id' => $this->org->id, 'processus_id' => $this->processus()->id, 'libelle' => 'Consultant',
            'scope_type' => CommissionScopeType::GLOBAL->value, 'cible_type' => CommissionCibleType::CODE_CONSULTANT,
            'mode' => CommissionMode::DIRECT->value, 'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => 10, 'consultant_id' => $consultant->id,
            'effective_from' => now()->subMonth()->toDateString(), 'effective_to' => $fin, 'statut' => $statut,
        ]);
    }

    // ── Reprise par migration ───────────────────────────────────────────────

    public function test_la_reprise_cree_une_qualification_par_ancien_type_sans_doublon_ni_perte(): void
    {
        $machiniste = $this->prestataire('machiniste');
        $consultant = $this->prestataire('consultant');
        $supprime = $this->prestataire('mecanicien');
        $supprime->delete();
        // Organisation sans référentiel : les trois qualifications sont recréées.
        $autre = Organization::factory()->create();
        DB::table('contact_sous_categories')->where('organization_id', $autre->id)->delete();
        $ailleurs = Prestataire::create(['organization_id' => $autre->id, 'personne_id' => Personne::factory()->create(['organization_id' => $autre->id])->id, 'type' => 'consultant']);

        $migration = require database_path('migrations/2026_11_09_100100_backfill_prestataire_qualifications.php');
        $migration->up();
        $migration->up();

        foreach ([[$machiniste, 'machiniste'], [$consultant, 'consultant'], [$supprime, 'mecanicien'], [$ailleurs, 'consultant']] as [$p, $code]) {
            $codes = Prestataire::withTrashed()->find($p->id)->sousCategories()->pluck('code')->all();
            $this->assertSame([$code], $codes);
            $this->assertSame($code, Prestataire::withTrashed()->find($p->id)->type->value);
        }
        $this->assertSame(4, DB::table('prestataire_sous_categories')->count());
    }

    public function test_type_est_facultatif(): void
    {
        $p = $this->prestataire(null, ['machiniste']);

        $this->assertNull($p->fresh()->type);
        $this->assertSame('Machiniste', $p->fresh()->type_label);
    }

    // ── Critère unique ──────────────────────────────────────────────────────

    public function test_seule_la_qualification_systeme_consultant_ouvre_l_eligibilite(): void
    {
        $libre = $this->qualificationLibre('consultant_externe', 'Consultant externe');
        $parQualification = $this->prestataire('machiniste', ['machiniste', 'consultant']);
        $parRepli = $this->prestataire('consultant');
        $libreSeule = $this->prestataire(null);
        $libreSeule->sousCategories()->attach($libre->id);
        // La qualification enregistrée fait foi : un ancien type « consultant » ne compte plus.
        $typePerime = $this->prestataire('consultant', ['mecanicien']);

        $this->assertTrue($parQualification->qualifieConsultant());
        $this->assertTrue($parRepli->qualifieConsultant());
        $this->assertFalse($libreSeule->qualifieConsultant());
        $this->assertFalse($typePerime->qualifieConsultant());

        $ids = Prestataire::where('organization_id', $this->org->id)->qualifiesConsultant()->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$parQualification->id, $parRepli->id])->sort()->values()->all(), $ids);

        // Même critère dans les règles de validation (barème, affectation, dépenses).
        $regle = ['id' => Rule::exists('prestataires', 'id')->where(fn ($q) => Prestataire::conditionQualifieConsultant($q))];
        $this->assertTrue(Validator::make(['id' => $parQualification->id], $regle)->passes());
        $this->assertTrue(Validator::make(['id' => $parRepli->id], $regle)->passes());
        $this->assertFalse(Validator::make(['id' => $libreSeule->id], $regle)->passes());
        $this->assertFalse(Validator::make(['id' => $typePerime->id], $regle)->passes());
    }

    public function test_les_lectures_consultant_suivent_le_critere_unique(): void
    {
        $consultant = $this->prestataire('machiniste', ['machiniste', 'consultant']);
        $typePerime = $this->prestataire('consultant', ['mecanicien']);

        // Compte (rattachement, ADR 0050).
        $requete = FicheRattachable::CONSULTANT->requete()->where('organization_id', $this->org->id)->pluck('id')->all();
        $this->assertSame([$consultant->id], $requete);
        $compte = User::factory()->create(['organization_id' => $this->org->id]);
        $this->assertSame('Seul un prestataire consultant peut être rattaché à un compte.', app(RattachementCompteService::class)->motifRefus($typePerime, $compte));
        $this->assertNotSame('Seul un prestataire consultant peut être rattaché à un compte.', app(RattachementCompteService::class)->motifRefus($consultant, $compte));

        // Dépenses : le concerné « Consultant » ne propose que les qualifiés.
        $options = DepenseFormOptionsLoader::prestataires($this->org->id)->keyBy('id');
        $this->assertTrue($options[$consultant->id]['qualifie_consultant']);
        $this->assertFalse($options[$typePerime->id]['qualifie_consultant']);
    }

    // ── Formulaire Prestataire (Q1, Q5) ─────────────────────────────────────

    public function test_creer_un_prestataire_avec_plusieurs_qualifications(): void
    {
        $user = $this->utilisateur();

        $this->actingAs($user)->post(route('prestataires.store'), $this->donnees(['qualification_ids' => [$this->qualification('consultant')->id, $this->qualification('machiniste')->id]]))
            ->assertSessionHasNoErrors();

        $p = Prestataire::where('organization_id', $this->org->id)->firstOrFail();
        $this->assertEqualsCanonicalizing(['machiniste', 'consultant'], $p->sousCategories()->pluck('code')->all());
        // Q5 sans ancien type : la première des anciennes valeurs portées, sans priorité à Consultant.
        $this->assertSame(PrestataireType::MACHINISTE, $p->type);
        $this->assertTrue($p->qualifieConsultant());
    }

    public function test_synchronisation_du_type_de_compatibilite(): void
    {
        $user = $this->utilisateur();
        $p = $this->prestataire('consultant', ['consultant', 'mecanicien']);
        $transporteur = $this->qualificationLibre('transporteur', 'Transporteur');
        $service = app(QualificationsPrestataire::class);

        // Ancienne valeur gardée tant qu'elle reste portée.
        $service->synchroniser($user, $p, [$this->qualification('consultant')->id, $transporteur->id]);
        $this->assertSame(PrestataireType::CONSULTANT, $p->fresh()->type);
        // Sinon, la première ancienne valeur portée.
        $service->synchroniser($user, $p, [$this->qualification('mecanicien')->id, $transporteur->id]);
        $this->assertSame(PrestataireType::MECANICIEN, $p->fresh()->type);
        // Sinon, vide : une qualification libre ne remplit jamais `type`.
        $service->synchroniser($user, $p, [$transporteur->id]);
        $this->assertNull($p->fresh()->type);
        $this->assertFalse($p->fresh()->qualifieConsultant());
    }

    public function test_au_moins_une_qualification_active_est_obligatoire(): void
    {
        $user = $this->utilisateur();
        $archivee = $this->qualificationLibre('archivee', 'Archivée', false);
        $p = $this->prestataire('machiniste', ['machiniste']);
        $p->sousCategories()->attach($archivee->id);

        // Aucune qualification : refus (message porté par l'ancien champ, affiché sous Qualifications).
        $this->actingAs($user)->post(route('prestataires.store'), $this->donnees(['qualification_ids' => []]))
            ->assertSessionHasErrors(['type' => 'Choisissez au moins une qualification.']);
        $this->actingAs($user)->put(route('prestataires.update', $p), $this->donnees(['qualification_ids' => [$archivee->id]]))
            ->assertSessionHasErrors(['qualification_ids' => 'Au moins une qualification active est obligatoire.']);
        // Une désactivée se garde à côté d'une active, mais ne s'ajoute pas.
        $this->actingAs($user)->put(route('prestataires.update', $p), $this->donnees(['qualification_ids' => [$archivee->id, $this->qualification('machiniste')->id]]))->assertSessionHasNoErrors();
        $autreArchivee = $this->qualificationLibre('ancienne', 'Ancienne', false);
        $this->actingAs($user)->put(route('prestataires.update', $p), $this->donnees(['qualification_ids' => [$autreArchivee->id, $this->qualification('machiniste')->id]]))->assertSessionHasErrors('qualification_ids');
    }

    public function test_une_qualification_d_une_autre_fonction_ou_organisation_est_refusee(): void
    {
        $user = $this->utilisateur();
        $grossiste = ContactSousCategorie::where('organization_id', $this->org->id)->where('code', 'grossiste')->firstOrFail();
        $ailleurs = ContactSousCategorie::where('organization_id', Organization::factory()->create()->id)->where('code', 'machiniste')->firstOrFail();

        foreach ([$grossiste->id, $ailleurs->id] as $id) {
            $this->actingAs($user)->post(route('prestataires.store'), $this->donnees(['qualification_ids' => [$id]]))->assertSessionHasErrors('qualification_ids');
        }
        $this->assertDatabaseCount('prestataires', 0);
    }

    public function test_l_ancien_champ_type_reste_accepte_et_l_edition_presente_les_qualifications(): void
    {
        $user = $this->utilisateur();

        $this->actingAs($user)->post(route('prestataires.store'), $this->donnees(['type' => 'mecanicien']))->assertSessionHasNoErrors();
        $p = Prestataire::where('organization_id', $this->org->id)->firstOrFail();
        $this->assertSame(['mecanicien'], $p->sousCategories()->pluck('code')->all());

        // Prestataire pas encore repris : le formulaire présente la qualification de son ancien type.
        $ancien = $this->prestataire('consultant');
        $this->actingAs($user)->get(route('prestataires.edit', $ancien))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('prestataire.qualification_ids', [$this->qualification('consultant')->id]));
    }

    // ── D15 ─────────────────────────────────────────────────────────────────

    public function test_d15_retrait_de_consultant_refuse_avec_une_affectation_active_meme_pour_le_super_admin(): void
    {
        $p = $this->prestataire('consultant', ['consultant', 'machiniste']);
        CommissionConsultantAffectation::create(['organization_id' => $this->org->id, 'prestataire_id' => $p->id, 'effective_from' => now()->subMonth()->toDateString(), 'statut' => 'active']);

        foreach ([$this->utilisateur(), $this->utilisateur([], 'super_admin')] as $user) {
            $this->actingAs($user)->put(route('prestataires.update', $p), $this->donnees(['qualification_ids' => [$this->qualification('machiniste')->id]]))
                ->assertSessionHasErrors(['qualification_ids' => QualificationsPrestataire::MESSAGE_D15]);
        }
        $this->assertTrue($p->fresh()->qualifieConsultant());

        // Garder Consultant en ajoutant une autre qualification reste possible.
        $this->actingAs($this->utilisateur())->put(route('prestataires.update', $p), $this->donnees(['qualification_ids' => [$this->qualification('consultant')->id, $this->qualification('mecanicien')->id]]))
            ->assertSessionHasNoErrors();
    }

    public function test_d15_bareme_actif_ou_a_venir_bloque_mais_pas_un_bareme_clos(): void
    {
        $user = $this->utilisateur();
        $retrait = ['qualification_ids' => [$this->qualification('machiniste')->id]];

        $actif = $this->prestataire('consultant', ['consultant']);
        $this->bareme($actif);
        $this->actingAs($user)->put(route('prestataires.update', $actif), $this->donnees($retrait))->assertSessionHasErrors('qualification_ids');

        $aVenir = $this->prestataire('consultant', ['consultant']);
        $this->bareme($aVenir, now()->addMonth()->toDateString());
        $this->actingAs($user)->put(route('prestataires.update', $aVenir), $this->donnees($retrait))->assertSessionHasErrors('qualification_ids');

        $clos = $this->prestataire('consultant', ['consultant']);
        $this->bareme($clos, now()->subDay()->toDateString());
        $this->actingAs($user)->put(route('prestataires.update', $clos), $this->donnees($retrait))->assertSessionHasNoErrors();
        $this->assertFalse($clos->fresh()->qualifieConsultant());
    }

    public function test_d15_s_applique_aussi_a_un_consultant_lu_par_le_repli(): void
    {
        $p = $this->prestataire('consultant');
        CommissionConsultantAffectation::create(['organization_id' => $this->org->id, 'prestataire_id' => $p->id, 'effective_from' => now()->subMonth()->toDateString(), 'statut' => 'active']);

        $this->expectException(ValidationException::class);
        app(QualificationsPrestataire::class)->synchroniser($this->utilisateur(), $p, [$this->qualification('machiniste')->id]);
    }

    public function test_desactiver_un_prestataire_designe_garde_son_comportement(): void
    {
        $p = $this->prestataire('consultant', ['consultant']);
        CommissionConsultantAffectation::create(['organization_id' => $this->org->id, 'prestataire_id' => $p->id, 'effective_from' => now()->subMonth()->toDateString(), 'statut' => 'active']);

        $this->actingAs($this->utilisateur())->put(route('prestataires.update', $p), $this->donnees(['qualification_ids' => [$this->qualification('consultant')->id], 'is_active' => false]))
            ->assertSessionHasNoErrors();
        $this->assertFalse($p->fresh()->is_active);
    }

    // ── Contacts et journal ─────────────────────────────────────────────────

    public function test_creation_depuis_contacts_et_affichage_des_qualifications(): void
    {
        // Fiche visible grâce à une fonction Fournisseur : on lui ajoute la fonction Prestataire.
        Feature::for($this->org)->activate(ModuleFeature::ACHATS);
        $user = $this->utilisateur(['contacts.read', 'contacts.create', 'prestataires.read', 'prestataires.create', 'fournisseurs.read']);
        $personne = Personne::factory()->create(['organization_id' => $this->org->id]);
        Fournisseur::create(['organization_id' => $this->org->id, 'personne_id' => $personne->id]);
        $this->prestataire('machiniste', ['machiniste']);

        $this->actingAs($user)->post(route('contacts.personnes.fonctions.store', $personne), [
            'fonction' => 'prestataire', 'qualification_ids' => [$this->qualification('consultant')->id, $this->qualification('mecanicien')->id],
        ])->assertSessionHasNoErrors();
        $p = Prestataire::where('personne_id', $personne->id)->firstOrFail();
        $this->assertTrue($p->qualifieConsultant());

        $this->actingAs($user)->get(route('contacts.personnes.show', $personne))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('fonctions.1.fonction', 'prestataire')
                ->where('fonctions.1.sous_categories', ['Mécanicien', 'Consultant']));

        $this->actingAs($user)->get(route('contacts.personnes.index', ['sous_categorie' => 'prestataire:consultant']))
            ->assertInertia(fn (AssertableInertia $page) => $page->where('personnes.total', 1)->where('personnes.data.0.id', $personne->id));
    }

    public function test_le_journal_ne_garde_que_des_identifiants(): void
    {
        $p = $this->prestataire('machiniste', ['machiniste']);
        app(QualificationsPrestataire::class)->synchroniser($this->utilisateur(), $p, [$this->qualification('mecanicien')->id]);

        $entree = AuditLog::where('auditable_id', $p->id)->where('meta->origine', 'qualifications')->firstOrFail();
        $this->assertSame([$this->qualification('mecanicien')->id], $entree->meta['qualifications_ajoutees']);
        $this->assertStringNotContainsString('Mécanicien', json_encode($entree->meta));
    }
}
