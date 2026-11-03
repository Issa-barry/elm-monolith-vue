<?php

namespace Tests\Feature\Comptes;

use App\Enums\PrestataireType;
use App\Models\EntrepriseInterlocuteur;
use App\Models\EntrepriseTierce;
use App\Models\Organization;
use App\Models\Parrain;
use App\Models\Personne;
use App\Models\Prestataire;
use App\Models\User;
use App\Services\Comptes\ActivitesCompteResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Activités parrain et consultant d'un compte : seul un rattachement explicite donne accès
 * (ADR 0050).
 */
class ActivitesCompteResolverTest extends TestCase
{
    use RefreshDatabase;

    private Organization $org;

    private User $compte;

    private ActivitesCompteResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->org = Organization::factory()->create();
        $this->compte = User::factory()->create(['organization_id' => $this->org->id, 'telephone' => '+224620000777']);
        $this->resolver = app(ActivitesCompteResolver::class);
    }

    private function relier(Parrain|Prestataire|EntrepriseInterlocuteur $fiche, ?User $compte = null): void
    {
        $fiche->forceFill(['user_id' => ($compte ?? $this->compte)->id])->save();
    }

    private function consultantPhysique(array $attributs = []): Prestataire
    {
        $personne = Personne::factory()->create(['organization_id' => $this->org->id]);

        return Prestataire::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'type' => PrestataireType::CONSULTANT->value,
            ...$attributs,
        ]);
    }

    /** @return array{0: EntrepriseTierce, 1: Prestataire} */
    private function entrepriseConsultante(string $raisonSociale = 'ABC CONSULTING'): array
    {
        $entreprise = EntrepriseTierce::create([
            'organization_id' => $this->org->id,
            'raison_sociale' => $raisonSociale,
        ]);

        return [$entreprise, Prestataire::create([
            'organization_id' => $this->org->id,
            'entreprise_tierce_id' => $entreprise->id,
            'type' => PrestataireType::CONSULTANT->value,
        ])];
    }

    private function interlocuteur(EntrepriseTierce $entreprise, array $attributs = []): EntrepriseInterlocuteur
    {
        $personne = Personne::factory()->create(['organization_id' => $this->org->id]);

        return EntrepriseInterlocuteur::create([
            'organization_id' => $this->org->id,
            'entreprise_tierce_id' => $entreprise->id,
            'personne_id' => $personne->id,
            'acces_commissions' => true,
            ...$attributs,
        ]);
    }

    public function test_un_compte_sans_rattachement_n_a_aucune_activite(): void
    {
        $activites = $this->resolver->resolve($this->compte);

        $this->assertNull($activites->parrain);
        $this->assertCount(0, $activites->consultants);
    }

    public function test_un_numero_identique_ne_donne_aucun_acces(): void
    {
        $personne = Personne::factory()->create(['organization_id' => $this->org->id, 'telephone' => '+224620000777']);
        Parrain::factory()->create(['organization_id' => $this->org->id, 'personne_id' => $personne->id]);
        $this->consultantPhysique(['personne_id' => $personne->id]);

        $activites = $this->resolver->resolve($this->compte);

        $this->assertNull($activites->parrain);
        $this->assertCount(0, $activites->consultants);
    }

    public function test_un_compte_sans_organisation_n_a_aucune_activite(): void
    {
        $compte = User::factory()->create(['organization_id' => null]);
        $this->relier(Parrain::factory()->create(['organization_id' => $this->org->id]), $compte);

        $activites = $this->resolver->resolve($compte);

        $this->assertNull($activites->parrain);
        $this->assertCount(0, $activites->consultants);
    }

    public function test_une_fiche_d_une_autre_organisation_reste_invisible(): void
    {
        $autreOrganisation = Organization::factory()->create();
        $this->relier(Parrain::factory()->create(['organization_id' => $autreOrganisation->id]));

        $this->assertNull($this->resolver->resolve($this->compte)->parrain);
    }

    public function test_le_parrain_rattache_est_trouve_meme_desactive(): void
    {
        $parrain = Parrain::factory()->create(['organization_id' => $this->org->id, 'is_active' => false]);
        $this->relier($parrain);

        $this->assertSame($parrain->id, $this->resolver->resolve($this->compte)->parrain?->id);
    }

    public function test_le_parrain_d_un_autre_compte_reste_invisible(): void
    {
        $this->relier(Parrain::factory()->create(['organization_id' => $this->org->id]), User::factory()->create(['organization_id' => $this->org->id]));

        $this->assertNull($this->resolver->resolve($this->compte)->parrain);
    }

    public function test_le_consultant_personne_physique_rattache_est_trouve(): void
    {
        $consultant = $this->consultantPhysique();
        $this->relier($consultant);

        $this->assertSame([$consultant->id], $this->resolver->resolve($this->compte)->consultants->pluck('id')->all());
    }

    public function test_un_prestataire_qui_n_est_pas_consultant_ne_donne_aucun_acces(): void
    {
        $this->relier($this->consultantPhysique(['type' => PrestataireType::MECANICIEN->value]));

        $this->assertCount(0, $this->resolver->resolve($this->compte)->consultants);
    }

    public function test_l_interlocuteur_autorise_accede_au_consultant_de_son_entreprise_seulement(): void
    {
        [$entreprise, $consultant] = $this->entrepriseConsultante();
        [, $autreConsultant] = $this->entrepriseConsultante('XYZ CONSEIL');
        $this->relier($this->interlocuteur($entreprise));

        $activites = $this->resolver->resolve($this->compte);

        $this->assertSame([$consultant->id], $activites->consultants->pluck('id')->all());
        $this->assertSame($consultant->id, $activites->consultant($consultant->id)?->id);
        $this->assertNull($activites->consultant($autreConsultant->id));
    }

    public function test_plusieurs_interlocuteurs_voient_le_meme_consultant_sans_en_etre_beneficiaires(): void
    {
        [$entreprise, $consultant] = $this->entrepriseConsultante();
        $autreCompte = User::factory()->create(['organization_id' => $this->org->id]);
        $this->relier($this->interlocuteur($entreprise));
        $this->relier($this->interlocuteur($entreprise), $autreCompte);

        $this->assertSame([$consultant->id], $this->resolver->resolve($this->compte)->consultants->pluck('id')->all());
        $this->assertSame([$consultant->id], $this->resolver->resolve($autreCompte)->consultants->pluck('id')->all());
        $this->assertNull($consultant->fresh()->user_id);
    }

    public function test_un_compte_peut_representer_plusieurs_entreprises(): void
    {
        [$premiere, $premierConsultant] = $this->entrepriseConsultante();
        [$seconde, $secondConsultant] = $this->entrepriseConsultante('XYZ CONSEIL');
        $this->relier($this->interlocuteur($premiere));
        $this->relier($this->interlocuteur($seconde));

        $this->assertEqualsCanonicalizing(
            [$premierConsultant->id, $secondConsultant->id],
            $this->resolver->resolve($this->compte)->consultants->pluck('id')->all(),
        );
    }

    public function test_un_interlocuteur_sans_autorisation_explicite_n_a_aucun_acces(): void
    {
        [$entreprise] = $this->entrepriseConsultante();
        $this->relier($this->interlocuteur($entreprise, ['acces_commissions' => false]));

        $this->assertCount(0, $this->resolver->resolve($this->compte)->consultants);
    }

    public function test_desactiver_un_interlocuteur_coupe_l_acces_immediatement(): void
    {
        [$entreprise] = $this->entrepriseConsultante();
        $interlocuteur = $this->interlocuteur($entreprise);
        $this->relier($interlocuteur);
        $this->assertCount(1, $this->resolver->resolve($this->compte)->consultants);

        $interlocuteur->update(['is_active' => false]);

        $this->assertCount(0, $this->resolver->resolve($this->compte)->consultants);
        $this->assertNotNull($this->compte->fresh());
    }

    public function test_detacher_un_interlocuteur_coupe_l_acces_immediatement(): void
    {
        [$entreprise] = $this->entrepriseConsultante();
        $interlocuteur = $this->interlocuteur($entreprise);
        $this->relier($interlocuteur);

        $interlocuteur->forceFill(['user_id' => null])->save();

        $this->assertCount(0, $this->resolver->resolve($this->compte)->consultants);
    }

    public function test_un_interlocuteur_n_accede_pas_aux_autres_prestataires_de_son_entreprise(): void
    {
        [$entreprise, $consultant] = $this->entrepriseConsultante();
        Prestataire::create([
            'organization_id' => $this->org->id,
            'entreprise_tierce_id' => $entreprise->id,
            'type' => PrestataireType::MACHINISTE->value,
        ]);
        $this->relier($this->interlocuteur($entreprise));

        $this->assertSame([$consultant->id], $this->resolver->resolve($this->compte)->consultants->pluck('id')->all());
    }

    public function test_un_lien_personnel_pose_sur_une_entreprise_ne_donne_aucun_acces(): void
    {
        [, $consultant] = $this->entrepriseConsultante();
        $this->relier($consultant);

        $this->assertCount(0, $this->resolver->resolve($this->compte)->consultants);
    }
}
