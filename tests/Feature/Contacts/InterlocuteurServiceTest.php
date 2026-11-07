<?php

namespace Tests\Feature\Contacts;

use App\Models\EntrepriseInterlocuteur;
use App\Models\EntrepriseTierce;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\User;
use App\Services\Contacts\InterlocuteurService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Interlocuteurs d'une entreprise (ADR 0060, CONTACT-004 à CONTACT-006) : personne du référentiel,
 * téléphone obligatoire, confirmation explicite avant de rattacher une personne connue, un seul
 * principal actif, désactivation sans perte d'historique.
 */
class InterlocuteurServiceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $acteur;

    private InterlocuteurService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->acteur = User::factory()->create(['organization_id' => $this->org->id]);
        $this->service = app(InterlocuteurService::class);
    }

    private function entreprise(string $raisonSociale = 'Société de maintenance'): EntrepriseTierce
    {
        return EntrepriseTierce::create(['organization_id' => $this->org->id, 'raison_sociale' => $raisonSociale]);
    }

    private function ajouter(EntrepriseTierce $entreprise, string $telephone, array $donnees = [], ?string $confirmee = null, bool $principal = false): EntrepriseInterlocuteur
    {
        return $this->service->ajouter($entreprise, ['telephone' => $telephone, 'nom_complet' => 'Mamadou Diallo', ...$donnees], $this->acteur, $confirmee, $principal);
    }

    private function principaux(EntrepriseTierce $entreprise): array
    {
        return EntrepriseInterlocuteur::where('entreprise_tierce_id', $entreprise->id)->where('est_principal', true)->pluck('id')->all();
    }

    // ── Ajout ────────────────────────────────────────────────────────────────

    public function test_un_interlocuteur_nouveau_devient_une_personne_du_referentiel(): void
    {
        $interlocuteur = $this->ajouter($this->entreprise(), '+224 622 11 22 33', [
            'fonction' => 'Responsable technique', 'quartier' => 'Kaloum', 'ville' => 'Conakry', 'email' => 'M.Diallo@exemple.gn',
        ]);

        $personne = $interlocuteur->personne;
        $this->assertSame($this->org->id, $personne->organization_id);
        $this->assertSame('224622112233', $personne->telephone_normalise);
        $this->assertSame('Kaloum', $personne->quartier);
        $this->assertSame('m.diallo@exemple.gn', $personne->email);
        $this->assertSame('Responsable technique', $interlocuteur->fonction);
        $this->assertTrue($interlocuteur->is_active);
        $this->assertFalse($interlocuteur->est_principal);
        $this->assertFalse($interlocuteur->acces_commissions);
        $this->assertNull($interlocuteur->user_id);
        $this->assertSame($this->acteur->id, $interlocuteur->created_by);
    }

    public function test_le_telephone_est_obligatoire(): void
    {
        foreach (['', '   ', '---'] as $telephone) {
            try {
                $this->ajouter($this->entreprise(), $telephone);
                $this->fail("Téléphone « {$telephone} » accepté.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('telephone', $e->errors());
            }
        }

        $this->assertSame(0, EntrepriseInterlocuteur::count());
    }

    public function test_le_nom_est_obligatoire_pour_une_nouvelle_personne(): void
    {
        $this->expectException(ValidationException::class);

        $this->service->ajouter($this->entreprise(), ['telephone' => '+224622000001'], $this->acteur);
    }

    // ── Confirmation d'une personne connue ───────────────────────────────────

    public function test_une_personne_connue_n_est_jamais_rattachee_sans_confirmation(): void
    {
        $connue = Personne::factory()->create(['organization_id' => $this->org->id, 'telephone' => '+224622445566', 'nom_complet' => 'Alpha Camara']);
        $entreprise = $this->entreprise();

        $this->assertSame($connue->id, $this->service->personneConnue($entreprise, '+224 622 44 55 66')?->id);

        try {
            $this->ajouter($entreprise, '+224622445566', ['nom_complet' => 'Quelqu\'un d\'autre']);
            $this->fail('Personne connue rattachée sans confirmation.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Alpha Camara', $e->errors()['telephone'][0]);
        }

        $this->assertSame(0, EntrepriseInterlocuteur::count());
        $this->assertSame(1, Personne::where('telephone_normalise', '224622445566')->count());
    }

    public function test_avec_confirmation_la_personne_connue_est_reprise_telle_quelle(): void
    {
        $connue = Personne::factory()->create(['organization_id' => $this->org->id, 'telephone' => '+224622445566', 'nom_complet' => 'Alpha Camara', 'ville' => 'Kindia']);

        $interlocuteur = $this->ajouter($this->entreprise(), '+224622445566', ['nom_complet' => 'Autre nom', 'ville' => 'Conakry', 'fonction' => 'Directeur commercial'], $connue->id);

        $this->assertSame($connue->id, $interlocuteur->personne_id);
        $this->assertSame('Alpha Camara', $connue->fresh()->nom_complet);
        $this->assertSame('Kindia', $connue->fresh()->ville);
        $this->assertSame('Directeur commercial', $interlocuteur->fonction);
    }

    public function test_une_confirmation_qui_ne_correspond_pas_au_numero_est_refusee(): void
    {
        $connue = Personne::factory()->create(['organization_id' => $this->org->id, 'telephone' => '+224622445566']);
        $autre = Personne::factory()->create(['organization_id' => $this->org->id, 'telephone' => '+224622999999']);
        $entreprise = $this->entreprise();

        foreach ([['+224622445566', $autre->id], ['+224622000002', $connue->id]] as [$telephone, $confirmee]) {
            try {
                $this->ajouter($entreprise, $telephone, [], $confirmee);
                $this->fail('Confirmation incohérente acceptée.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, EntrepriseInterlocuteur::count());
    }

    public function test_une_personne_d_une_autre_organisation_n_est_jamais_proposee(): void
    {
        Personne::factory()->create(['organization_id' => Organization::factory()->create()->id, 'telephone' => '+224622445566']);

        $interlocuteur = $this->ajouter($this->entreprise(), '+224622445566');

        $this->assertSame($this->org->id, $interlocuteur->personne->organization_id);
        $this->assertSame(2, Personne::where('telephone_normalise', '224622445566')->count());
    }

    // ── Plusieurs entreprises, doublons ──────────────────────────────────────

    public function test_une_meme_personne_represente_plusieurs_entreprises_sans_nouvelle_fiche(): void
    {
        $a = $this->entreprise('ABC');
        $b = $this->entreprise('DEF');

        $premier = $this->ajouter($a, '+224622445566');
        $second = $this->ajouter($b, '+224622445566', [], $premier->personne_id);

        $this->assertSame($premier->personne_id, $second->personne_id);
        $this->assertSame(1, Personne::where('telephone_normalise', '224622445566')->count());
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $premier->personne->interlocutions()->pluck('entreprise_tierce_id')->all());

        $this->service->desactiver($premier, $this->acteur);
        $this->assertTrue($second->fresh()->is_active);
    }

    public function test_une_personne_n_est_pas_deux_fois_interlocuteur_de_la_meme_entreprise(): void
    {
        $entreprise = $this->entreprise();
        $premier = $this->ajouter($entreprise, '+224622445566');

        $this->expectException(ValidationException::class);
        $this->ajouter($entreprise, '+224622445566', [], $premier->personne_id);
    }

    // ── Principal ────────────────────────────────────────────────────────────

    public function test_un_seul_principal_actif_par_entreprise(): void
    {
        $entreprise = $this->entreprise();
        $a = $this->ajouter($entreprise, '+224622000010', [], null, true);
        $b = $this->ajouter($entreprise, '+224622000020');

        $this->assertSame([$a->id], $this->principaux($entreprise));

        $this->service->definirPrincipal($b, $this->acteur);
        $this->assertSame([$b->id], $this->principaux($entreprise));
        $this->assertSame($b->id, $entreprise->fresh()->interlocuteurPrincipal->id);

        $c = $this->ajouter($entreprise, '+224622000030', [], null, true);
        $this->assertSame([$c->id], $this->principaux($entreprise));
    }

    /**
     * Deux désignations concurrentes ne peuvent pas laisser deux principaux : chaque opération
     * verrouille l'entreprise, puis retire le principal à tous les autres. Même un état déjà
     * incohérent (deux principaux écrits avant le verrou) est ramené à un seul principal.
     */
    public function test_une_designation_ramene_toujours_a_un_seul_principal(): void
    {
        $entreprise = $this->entreprise();
        $a = $this->ajouter($entreprise, '+224622000010');
        $b = $this->ajouter($entreprise, '+224622000020');
        $c = $this->ajouter($entreprise, '+224622000030');
        EntrepriseInterlocuteur::whereIn('id', [$a->id, $b->id])->update(['est_principal' => true]);

        $this->service->definirPrincipal($c, $this->acteur);

        $this->assertSame([$c->id], $this->principaux($entreprise));
    }

    public function test_la_designation_verrouille_l_entreprise_avant_d_ecrire(): void
    {
        $entreprise = $this->entreprise();
        $a = $this->ajouter($entreprise, '+224622000010');

        DB::enableQueryLog();
        $this->service->definirPrincipal($a, $this->acteur);
        $requetes = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $ecriture = $requetes->search(fn (string $q) => str_starts_with(strtolower($q), 'update') && str_contains($q, 'entreprise_interlocuteurs'));
        $this->assertNotFalse($ecriture);

        // Lectures de l'entreprise AVANT la première écriture (chargement de la relation, puis
        // verrou). SQLite n'émet pas de clause de verrou (il sérialise déjà les écritures) ;
        // MySQL et MariaDB doivent montrer un SELECT ... FOR UPDATE.
        $lectures = $requetes->take($ecriture)->filter(fn (string $q) => str_starts_with(strtolower($q), 'select') && str_contains($q, 'entreprises_tierces'));
        $this->assertNotEmpty($lectures, 'Aucune lecture de l\'entreprise avant l\'écriture.');
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->assertTrue(
                $lectures->contains(fn (string $q) => str_contains(strtolower($q), 'for update')),
                'L\'entreprise n\'est pas verrouillée (SELECT ... FOR UPDATE) avant l\'écriture.',
            );
        }
    }

    // ── Désactivation ────────────────────────────────────────────────────────

    public function test_desactiver_conserve_le_lien_et_retire_le_principal(): void
    {
        $entreprise = $this->entreprise();
        $a = $this->ajouter($entreprise, '+224622000010', ['fonction' => 'Gérant'], null, true);

        $desactive = $this->service->desactiver($a, $this->acteur);

        $this->assertFalse($desactive->is_active);
        $this->assertFalse($desactive->est_principal);
        $this->assertNotNull($desactive->desactive_le);
        $this->assertSame($this->acteur->id, $desactive->desactive_par);
        $this->assertSame('Gérant', $desactive->fonction);
        $this->assertSame(1, EntrepriseInterlocuteur::count());
        $this->assertNull($entreprise->fresh()->interlocuteurPrincipal);

        try {
            $this->service->definirPrincipal($desactive, $this->acteur);
            $this->fail('Un interlocuteur désactivé est devenu principal.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $reactive = $this->service->reactiver($desactive, $this->acteur);
        $this->assertTrue($reactive->is_active);
        $this->assertFalse($reactive->est_principal);
        $this->assertNull($reactive->desactive_le);
    }

    public function test_modifier_ne_change_que_la_fonction_et_les_notes(): void
    {
        $a = $this->ajouter($this->entreprise(), '+224622000010', ['fonction' => 'Gérant']);

        $modifie = $this->service->modifier($a, ['fonction' => 'Directeur', 'notes' => 'Joignable le matin', 'personne_id' => 'x', 'est_principal' => true], $this->acteur);

        $this->assertSame('Directeur', $modifie->fonction);
        $this->assertSame('Joignable le matin', $modifie->notes);
        $this->assertSame($a->personne_id, $modifie->personne_id);
        $this->assertFalse($modifie->est_principal);
    }

    // ── Cloisonnement et compte (ADR 0056) ───────────────────────────────────

    public function test_un_acteur_d_une_autre_organisation_est_refuse(): void
    {
        $entreprise = $this->entreprise();
        $a = $this->ajouter($entreprise, '+224622000010');
        $etranger = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        foreach ([
            fn () => $this->service->ajouter($entreprise, ['telephone' => '+224622000020', 'nom_complet' => 'X'], $etranger),
            fn () => $this->service->definirPrincipal($a, $etranger),
            fn () => $this->service->desactiver($a, $etranger),
            fn () => $this->service->modifier($a, ['fonction' => 'X'], $etranger),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Opération acceptée depuis une autre organisation.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1, EntrepriseInterlocuteur::count());
        $this->assertTrue($a->fresh()->is_active);
    }

    /**
     * La confirmation porte sur le lien entreprise ↔ personne. Le compte de cette personne est
     * ensuite relié par le rattachement automatique d'ADR 0056, inchangé : il n'est jamais posé par
     * ce service.
     */
    public function test_le_rattachement_du_compte_reste_celui_d_adr_0056(): void
    {
        $connue = Personne::factory()->create(['organization_id' => $this->org->id, 'telephone' => '+224622445566']);
        $compte = User::factory()->create(['organization_id' => $this->org->id, 'personne_id' => $connue->id]);
        Role::firstOrCreate(['name' => 'client', 'guard_name' => 'web']);
        $compte->assignRole('client');

        $interlocuteur = $this->ajouter($this->entreprise(), '+224622445566', [], $connue->id);

        $this->assertSame($compte->id, $interlocuteur->fresh()->user_id);
        $this->assertFalse($interlocuteur->fresh()->acces_commissions);
    }
}
