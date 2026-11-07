<?php

namespace Tests\Feature\Contacts;

use App\Enums\ClientType;
use App\Enums\ContactCategorie;
use App\Enums\PrestataireType;
use App\Models\ContactSousCategorie;
use App\Models\EntrepriseTierce;
use App\Models\Fournisseur;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Prestataire;
use App\Support\Contacts\SousCategoriesSysteme;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use ReflectionClassConstant;
use Tests\TestCase;

/**
 * Référentiel des sous-catégories (ADR 0060, CONTACT-008 à CONTACT-010) : codes stables, unicité
 * par organisation, initialisation rejouable, sous-catégories système verrouillées (D18).
 */
class ReferentielSousCategoriesTest extends TestCase
{
    use RefreshDatabase;

    private function sousCategorie(Organization $org, ContactCategorie $categorie, string $code): ContactSousCategorie
    {
        return ContactSousCategorie::where('organization_id', $org->id)
            ->where('categorie', $categorie->value)
            ->where('code', $code)
            ->firstOrFail();
    }

    public function test_une_nouvelle_organisation_recoit_les_sous_categories_par_defaut(): void
    {
        $org = Organization::factory()->create();

        $lignes = ContactSousCategorie::where('organization_id', $org->id)->get();

        $this->assertCount(7, $lignes);
        $this->assertEqualsCanonicalizing(
            ['client:externe:1', 'client:revendeur:1', 'client:distributeur:1', 'client:grossiste:1',
                'prestataire:machiniste:0', 'prestataire:mecanicien:0', 'prestataire:consultant:1'],
            $lignes->map(fn (ContactSousCategorie $s) => "{$s->categorie->value}:{$s->code}:".(int) $s->is_system)->all(),
        );
        $this->assertTrue($lignes->every->is_active);
    }

    public function test_les_codes_reprennent_les_valeurs_deja_stockees(): void
    {
        $codes = collect(SousCategoriesSysteme::lignesParDefaut());

        $this->assertEqualsCanonicalizing(
            array_column(ClientType::cases(), 'value'),
            $codes->where('categorie', 'client')->pluck('code')->all(),
        );
        $this->assertEqualsCanonicalizing(
            array_column(PrestataireType::cases(), 'value'),
            $codes->where('categorie', 'prestataire')->pluck('code')->all(),
        );
    }

    public function test_la_migration_de_donnees_et_le_catalogue_creent_les_memes_lignes(): void
    {
        $migration = require database_path('migrations/2026_11_06_100300_seed_contact_sous_categories_systeme.php');
        $figees = (new ReflectionClassConstant($migration, 'LIGNES'))->getValue();

        $this->assertSame(
            collect(SousCategoriesSysteme::lignesParDefaut())->map(fn (array $l) => [$l['categorie'], $l['code'], $l['libelle'], $l['is_system'], $l['ordre']])->sortBy(fn ($l) => $l[0].$l[1])->values()->all(),
            collect($figees)->sortBy(fn ($l) => $l[0].$l[1])->values()->all(),
        );
    }

    public function test_l_initialisation_est_rejouable_sans_doublon_ni_ecrasement(): void
    {
        $org = Organization::factory()->create();
        $grossiste = $this->sousCategorie($org, ContactCategorie::CLIENT, 'grossiste');
        $grossiste->update(['libelle' => 'Grossiste (gros volumes)']);

        $this->assertSame(0, SousCategoriesSysteme::initialiserPour($org->id));
        $this->assertSame(0, SousCategoriesSysteme::initialiserPour($org->id));

        $this->assertSame(7, ContactSousCategorie::where('organization_id', $org->id)->count());
        $this->assertSame('Grossiste (gros volumes)', $grossiste->fresh()->libelle);
    }

    public function test_la_migration_de_donnees_rejouee_ne_cree_aucun_doublon(): void
    {
        $org = Organization::factory()->create();
        $migration = require database_path('migrations/2026_11_06_100300_seed_contact_sous_categories_systeme.php');

        $migration->up();
        $migration->up();

        $this->assertSame(7, ContactSousCategorie::where('organization_id', $org->id)->count());
    }

    public function test_l_unicite_est_par_organisation(): void
    {
        $a = Organization::factory()->create();
        $b = Organization::factory()->create();

        $this->assertSame(7, ContactSousCategorie::where('organization_id', $a->id)->count());
        $this->assertSame(7, ContactSousCategorie::where('organization_id', $b->id)->count());

        $this->expectException(UniqueConstraintViolationException::class);
        ContactSousCategorie::create(['organization_id' => $a->id, 'categorie' => 'client', 'code' => 'externe', 'libelle' => 'Doublon']);
    }

    public function test_une_sous_categorie_systeme_ne_change_ni_de_code_ni_d_activation(): void
    {
        $org = Organization::factory()->create();

        foreach ([['client', 'grossiste'], ['prestataire', 'consultant']] as [$categorie, $code]) {
            $systeme = $this->sousCategorie($org, ContactCategorie::from($categorie), $code);

            foreach ([['code' => 'autre'], ['is_active' => false], ['categorie' => 'fournisseur']] as $changement) {
                try {
                    $systeme->fresh()->update($changement);
                    $this->fail('Changement accepté sur une sous-catégorie système : '.json_encode($changement));
                } catch (ValidationException) {
                    $this->assertSame($code, $systeme->fresh()->code);
                    $this->assertTrue($systeme->fresh()->is_active);
                }
            }

            $systeme->fresh()->update(['libelle' => 'Libellé renommé', 'ordre' => 9]);
            $this->assertSame('Libellé renommé', $systeme->fresh()->libelle);
        }
    }

    public function test_une_sous_categorie_modifiable_se_desactive_mais_ne_se_supprime_jamais(): void
    {
        $org = Organization::factory()->create();
        $machiniste = $this->sousCategorie($org, ContactCategorie::PRESTATAIRE, 'machiniste');

        $machiniste->update(['is_active' => false]);
        $this->assertFalse($machiniste->fresh()->is_active);

        $this->expectException(ValidationException::class);
        $machiniste->fresh()->delete();
    }

    public function test_le_statut_systeme_n_est_pas_assignable_en_masse(): void
    {
        $org = Organization::factory()->create();

        $libre = ContactSousCategorie::create(['organization_id' => $org->id, 'categorie' => 'fournisseur', 'code' => 'emballages', 'libelle' => 'Emballages', 'is_system' => true]);

        $this->assertFalse($libre->fresh()->is_system);
    }

    public function test_les_tables_de_liaison_acceptent_plusieurs_sous_categories_sans_toucher_au_type(): void
    {
        $org = Organization::factory()->create();
        $personne = Personne::factory()->create(['organization_id' => $org->id]);
        $prestataire = Prestataire::create(['organization_id' => $org->id, 'personne_id' => $personne->id, 'type' => PrestataireType::MECANICIEN->value]);
        $entreprise = EntrepriseTierce::create(['organization_id' => $org->id, 'raison_sociale' => 'Emballages SA']);
        $fournisseur = Fournisseur::create(['organization_id' => $org->id, 'entreprise_tierce_id' => $entreprise->id]);
        $emballages = ContactSousCategorie::create(['organization_id' => $org->id, 'categorie' => 'fournisseur', 'code' => 'emballages', 'libelle' => 'Emballages']);
        $matieres = ContactSousCategorie::create(['organization_id' => $org->id, 'categorie' => 'fournisseur', 'code' => 'matieres_premieres', 'libelle' => 'Matières premières']);

        $prestataire->sousCategories()->attach([
            $this->sousCategorie($org, ContactCategorie::PRESTATAIRE, 'mecanicien')->id,
            $this->sousCategorie($org, ContactCategorie::PRESTATAIRE, 'machiniste')->id,
            $this->sousCategorie($org, ContactCategorie::PRESTATAIRE, 'consultant')->id,
        ]);
        $fournisseur->sousCategories()->attach([$emballages->id, $matieres->id]);

        $this->assertSame(3, $prestataire->sousCategories()->count());
        $this->assertSame(2, $fournisseur->sousCategories()->count());
        $this->assertSame(PrestataireType::MECANICIEN, $prestataire->fresh()->type);
        $this->assertNotEmpty($prestataire->sousCategories()->first()->pivot->id);

        $this->expectException(UniqueConstraintViolationException::class);
        $fournisseur->sousCategories()->attach($emballages->id);
    }
}
