<?php

namespace Tests\Feature;

use App\Enums\StatutAffectationRemuneration;
use App\Enums\StatutContrat;
use App\Enums\StatutDocumentContrat;
use App\Enums\TypeContrat;
use App\Models\AffectationRemuneration;
use App\Models\Contrat;
use App\Models\DocumentContrat;
use App\Models\Employe;
use App\Models\FonctionRh;
use App\Models\Organization;
use App\Models\Personne;
use App\Models\Site;
use App\Models\TypeRemuneration;
use App\Models\User;
use App\Services\Rh\DocumentContratService;
use App\Services\Rh\ModeleContratService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Contrat de travail généré (docs/rh-contrat-pdf.md, ADR 0044) : modèle actif obligatoire,
 * variables vides bloquantes, instantané figé, versions, signature, primes validées seulement,
 * et accès (permissions, organisation, agence). Le rendu PDF est testé dans
 * Documents\ModeleDocumentPdfTest.
 */
class DocumentContratTest extends TestCase
{
    use RefreshDatabase;

    private const GENERATION = ['rh-contrats.read', 'rh-contrats.update', 'rh-remunerations.read'];

    private const CHAMPS = [
        'representant_nom' => 'Mamadou BAH',
        'representant_qualite' => 'Directeur général',
        'lieu_travail' => 'Matoto, Conakry',
        'periode_essai' => 'trois (3) mois',
        'horaires' => '40 heures par semaine',
        'lieu_signature' => 'Conakry',
    ];

    private Organization $org;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DocumentContratService::DISQUE);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->org = Organization::factory()->create(['name' => 'Eau La Maman']);
        $this->site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'depot', 'ville' => 'Conakry']);
    }

    private function utilisateur(array $permissions, string $role = 'admin_entreprise', ?Organization $org = null): User
    {
        Role::firstOrCreate(['name' => $role, 'guard_name' => 'web']);
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }

        $user = User::factory()->create(['organization_id' => ($org ?? $this->org)->id]);
        $user->assignRole($role);
        $user->givePermissionTo($permissions);
        $user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function contrat(?Site $site = null, array $attributs = [], ?Organization $org = null, string $matricule = '373601', bool $avecFonction = true): Contrat
    {
        $orgId = ($org ?? $this->org)->id;
        $personne = Personne::create([
            'organization_id' => $orgId, 'nom' => 'DIALLO', 'prenom' => 'Thierno Oumar', 'civilite' => 'monsieur',
            'telephone' => '+22462'.fake()->unique()->numerify('#######'),
        ]);
        $fonction = $avecFonction ? FonctionRh::create(['organization_id' => $orgId, 'libelle' => 'Chauffeur-livreur '.$matricule, 'code' => 'CL'.$matricule]) : null;
        $employe = Employe::create([
            'organization_id' => $orgId, 'personne_id' => $personne->id, 'matricule' => $matricule,
            'type_employe' => 'interne', 'statut' => 'actif', 'site_id' => $site?->id, 'fonction_rh_id' => $fonction?->id,
        ]);

        return Contrat::create(array_merge([
            'organization_id' => $orgId, 'employe_id' => $employe->id, 'type_contrat' => TypeContrat::CDI->value,
            'date_debut' => '2026-01-15', 'salaire_base' => 750000, 'statut_contrat' => StatutContrat::ACTIF->value,
        ], $attributs));
    }

    private function activerModele(TypeContrat $type = TypeContrat::CDI, ?Organization $org = null): void
    {
        $orgId = ($org ?? $this->org)->id;
        app(ModeleContratService::class)->activer($orgId, $type, User::factory()->create(['organization_id' => $orgId]));
    }

    private function generer(User $user, Contrat $contrat, array $champs = self::CHAMPS, array $avantages = [])
    {
        return $this->actingAs($user)->post(route('contrats.documents.store', $contrat), ['champs' => $champs, 'avantages' => $avantages]);
    }

    private function prime(Contrat $contrat, string $statut = 'validee', ?Employe $autre = null): AffectationRemuneration
    {
        $type = TypeRemuneration::create([
            'organization_id' => $contrat->organization_id, 'code' => 'TRANS'.fake()->unique()->numerify('###'), 'libelle' => 'Prime de transport '.fake()->unique()->numerify('###'),
            'categorie' => 'prime', 'mode_calcul' => 'montant_fixe', 'periodicite' => 'mensuelle', 'nature_montant' => 'fixe', 'statut' => 'actif',
        ]);

        return AffectationRemuneration::create([
            'organization_id' => $contrat->organization_id, 'employe_id' => ($autre ?? $contrat->employe)->id, 'type_remuneration_id' => $type->id,
            'date_debut' => '2026-01-15', 'montant' => 50000, 'statut' => $statut,
        ]);
    }

    public function test_sans_modele_actif_la_generation_est_refusee(): void
    {
        $contrat = $this->contrat($this->site);

        $this->generer($this->utilisateur(self::GENERATION), $contrat)->assertSessionHasErrors('modele');

        $this->assertSame(0, DocumentContrat::count());
    }

    public function test_la_generation_cree_une_version_stockee_et_consultable(): void
    {
        $this->activerModele();
        $contrat = $this->contrat($this->site);
        $user = $this->utilisateur(self::GENERATION);

        $this->generer($user, $contrat)->assertSessionHasNoErrors()->assertRedirect();

        $document = DocumentContrat::sole();
        $this->assertSame(1, $document->version);
        $this->assertSame(StatutDocumentContrat::A_SIGNER, $document->statut);
        $this->assertSame($user->id, $document->genere_par);
        Storage::disk(DocumentContratService::DISQUE)->assertExists($document->fichier_path);
        $stocke = Storage::disk(DocumentContratService::DISQUE)->get($document->fichier_path);
        $this->assertSame(hash('sha256', $stocke), $document->empreinte);
        $this->assertSame('750 000 GNF', $document->donnees['valeurs']['contrat.salaire_base']);
        $this->assertStringContainsString('salaire de base mensuel de 750 000 GNF', implode(' ', $document->donnees['articles'][6]['paragraphes']));
        $this->assertStringContainsString('Mamadou BAH, Directeur général', $document->donnees['articles'][0]['paragraphes'][0]);

        $apercu = $this->actingAs($user)->get(route('contrats.pdf', ['contrat' => $contrat, 'preview' => 1]));
        $apercu->assertOk();
        $this->assertSame('application/pdf', $apercu->headers->get('Content-Type'));
        $this->assertStringContainsString('inline', (string) $apercu->headers->get('Content-Disposition'));
        $this->assertStringContainsString('CTR-373601-20260115-V1.pdf', (string) $apercu->headers->get('Content-Disposition'));
        $this->assertSame($stocke, $apercu->getContent());

        $telechargement = $this->actingAs($user)->get(route('contrats.documents.pdf', [$contrat, $document]));
        $telechargement->assertOk();
        $this->assertStringContainsString('attachment', (string) $telechargement->headers->get('Content-Disposition'));
    }

    public function test_un_contrat_genere_ne_change_pas_quand_le_dossier_change(): void
    {
        $this->activerModele();
        $contrat = $this->contrat($this->site);
        $user = $this->utilisateur(self::GENERATION);
        $this->generer($user, $contrat)->assertSessionHasNoErrors();
        $avant = $this->actingAs($user)->get(route('contrats.pdf', $contrat))->getContent();

        $contrat->update(['salaire_base' => 900000]);
        $contrat->employe->fonction->update(['libelle' => 'Superviseur']);
        $contrat->employe->personne->update(['nom' => 'BARRY']);

        $this->assertSame($avant, $this->actingAs($user)->get(route('contrats.pdf', $contrat))->getContent());
        $document = DocumentContrat::sole();
        $this->assertSame('750 000 GNF', $document->donnees['valeurs']['contrat.salaire_base']);
        $this->assertSame('Thierno Oumar DIALLO', $document->donnees['valeurs']['salarie.nom_complet']);
    }

    public function test_une_information_manquante_bloque_la_generation_sans_rien_inventer(): void
    {
        $this->activerModele();
        $contrat = $this->contrat($this->site, ['salaire_base' => null], avecFonction: false);

        $this->generer($this->utilisateur(self::GENERATION), $contrat, array_merge(self::CHAMPS, ['periode_essai' => '  ']))
            ->assertSessionHasErrors(['manquantes' => 'Informations manquantes : Fonction, Période d’essai, Salaire de base mensuel.']);

        $this->assertSame(0, DocumentContrat::count());
        $this->assertSame([], Storage::disk(DocumentContratService::DISQUE)->allFiles());
    }

    public function test_regenerer_remplace_la_version_non_signee(): void
    {
        $this->activerModele();
        $contrat = $this->contrat($this->site);
        $user = $this->utilisateur(self::GENERATION);

        $this->generer($user, $contrat)->assertSessionHasNoErrors();
        $this->generer($user, $contrat, array_merge(self::CHAMPS, ['periode_essai' => 'sans période d’essai']))->assertSessionHasNoErrors();

        $versions = DocumentContrat::orderBy('version')->get();
        $this->assertSame([StatutDocumentContrat::REMPLACE, StatutDocumentContrat::A_SIGNER], $versions->pluck('statut')->all());
        foreach ($versions as $v) {
            Storage::disk(DocumentContratService::DISQUE)->assertExists($v->fichier_path);
        }
        $this->assertSame(2, $contrat->documentCourant()->first()->version);
    }

    public function test_une_version_signee_est_figee_et_une_nouvelle_generation_cree_une_version(): void
    {
        $this->activerModele();
        $contrat = $this->contrat($this->site);
        $user = $this->utilisateur(self::GENERATION);
        $this->generer($user, $contrat)->assertSessionHasNoErrors();
        $v1 = DocumentContrat::sole();

        $this->actingAs($user)->post(route('contrats.documents.signature', [$contrat, $v1]), ['signe_le' => today()->toDateString()])
            ->assertSessionHasNoErrors();
        $v1->refresh();
        $this->assertSame(StatutDocumentContrat::SIGNE, $v1->statut);
        $this->assertSame(today()->toDateString(), $v1->signe_le->toDateString());
        $this->assertSame($user->id, $v1->signature_enregistree_par);

        // Déjà signée : refus.
        $this->actingAs($user)->post(route('contrats.documents.signature', [$contrat, $v1]), ['signe_le' => today()->toDateString()])
            ->assertSessionHasErrors('signe_le');

        $this->generer($user, $contrat)->assertSessionHasNoErrors();
        $this->assertSame(StatutDocumentContrat::SIGNE, $v1->fresh()->statut);
        $this->assertSame(StatutDocumentContrat::A_SIGNER, DocumentContrat::where('version', 2)->sole()->statut);
        Storage::disk(DocumentContratService::DISQUE)->assertExists($v1->fichier_path);
    }

    public function test_la_date_de_signature_doit_etre_entre_la_generation_et_aujourd_hui(): void
    {
        $this->activerModele();
        $contrat = $this->contrat($this->site);
        $user = $this->utilisateur(self::GENERATION);
        $this->generer($user, $contrat)->assertSessionHasNoErrors();
        $document = DocumentContrat::sole();

        foreach ([today()->addDay(), today()->subDay()] as $date) {
            $this->actingAs($user)->post(route('contrats.documents.signature', [$contrat, $document]), ['signe_le' => $date->toDateString()])
                ->assertSessionHasErrors('signe_le');
        }
        $this->assertSame(StatutDocumentContrat::A_SIGNER, $document->fresh()->statut);
    }

    public function test_seuls_les_avantages_valides_du_salarie_figurent_au_contrat(): void
    {
        $this->activerModele();
        $contrat = $this->contrat($this->site);
        $autre = $this->contrat($this->site, matricule: '548817');
        $user = $this->utilisateur(self::GENERATION);
        $validee = $this->prime($contrat);
        $aValider = $this->prime($contrat, StatutAffectationRemuneration::A_VALIDER->value);
        $autreSalarie = $this->prime($contrat, autre: $autre->employe);

        foreach ([$aValider, $autreSalarie] as $refusee) {
            $this->generer($user, $contrat, avantages: [$refusee->id])->assertSessionHasErrors('avantages');
        }
        $this->assertSame(0, DocumentContrat::count());

        $this->generer($user, $contrat, avantages: [$validee->id])->assertSessionHasNoErrors();
        $donnees = DocumentContrat::sole()->donnees;
        $this->assertSame($validee->id, $donnees['avantages'][0]['affectation_id']);
        $this->assertStringContainsString('(50 000 GNF par mois)', $donnees['valeurs']['contrat.avantages']);
    }

    public function test_sans_avantage_retenu_le_contrat_indique_aucun(): void
    {
        $this->activerModele();
        $contrat = $this->contrat($this->site);

        $this->generer($this->utilisateur(self::GENERATION), $contrat)->assertSessionHasNoErrors();

        $this->assertSame('aucun', DocumentContrat::sole()->donnees['valeurs']['contrat.avantages']);
    }

    public function test_un_contrat_qui_n_est_plus_actif_ne_peut_pas_etre_genere(): void
    {
        $this->activerModele();
        $contrat = $this->contrat($this->site, ['statut_contrat' => StatutContrat::TERMINE->value]);

        $this->generer($this->utilisateur(self::GENERATION), $contrat)->assertSessionHasErrors('contrat');
    }

    public function test_un_cdd_exige_sa_date_de_fin_et_son_motif(): void
    {
        $this->activerModele(TypeContrat::CDD);
        $contrat = $this->contrat($this->site, ['type_contrat' => TypeContrat::CDD->value, 'date_fin' => '2026-12-31']);
        $user = $this->utilisateur(self::GENERATION);

        $this->generer($user, $contrat)->assertSessionHasErrors(['manquantes' => 'Informations manquantes : Motif du recours au CDD.']);

        $this->generer($user, $contrat, array_merge(self::CHAMPS, ['motif_cdd' => 'accroissement temporaire d’activité']))->assertSessionHasNoErrors();
        $articles = collect(DocumentContrat::sole()->donnees['articles'])->flatMap(fn ($a) => $a['paragraphes'])->implode(' ');
        $this->assertStringContainsString('prend fin le 31/12/2026', $articles);
        $this->assertStringContainsString('accroissement temporaire d’activité', $articles);
    }

    public function test_la_lecture_seule_ne_permet_ni_de_generer_ni_de_signer(): void
    {
        $this->activerModele();
        $contrat = $this->contrat($this->site);
        $this->generer($this->utilisateur(self::GENERATION), $contrat)->assertSessionHasNoErrors();
        $document = DocumentContrat::sole();
        $lecteur = $this->utilisateur(['rh-contrats.read']);

        $this->generer($lecteur, $contrat)->assertForbidden();
        $this->actingAs($lecteur)->post(route('contrats.documents.signature', [$contrat, $document]), ['signe_le' => today()->toDateString()])->assertForbidden();
        $this->actingAs($lecteur)->get(route('contrats.pdf', $contrat))->assertOk();

        // La page reste consultable (historique), sans données ni formulaire de génération.
        $this->actingAs($lecteur)->get(route('contrats.document', $contrat))
            ->assertInertia(fn (Assert $page) => $page->component('Contrats/Document')
                ->where('peut_generer', false)
                ->where('variables', [])
                ->has('versions', 1));
    }

    public function test_generer_exige_de_pouvoir_voir_les_salaires(): void
    {
        $this->activerModele();
        $contrat = $this->contrat($this->site);

        $this->generer($this->utilisateur(['rh-contrats.read', 'rh-contrats.update']), $contrat)->assertForbidden();
    }

    public function test_sans_rh_contrats_read_tout_est_refuse(): void
    {
        $this->activerModele();
        $contrat = $this->contrat($this->site);
        $this->generer($this->utilisateur(self::GENERATION), $contrat)->assertSessionHasNoErrors();
        $user = $this->utilisateur(['rh-employes.read']);

        $this->actingAs($user)->get(route('contrats.pdf', $contrat))->assertForbidden();
        $this->actingAs($user)->get(route('contrats.document', $contrat))->assertForbidden();
        $this->actingAs($user)->get(route('contrats.documents.pdf', [$contrat, DocumentContrat::sole()]))->assertForbidden();
    }

    public function test_un_contrat_d_une_autre_organisation_est_refuse(): void
    {
        $autreOrg = Organization::factory()->create();
        $this->activerModele(org: $autreOrg);
        $contrat = $this->contrat(null, [], $autreOrg);
        $user = $this->utilisateur(self::GENERATION);

        $this->actingAs($user)->get(route('contrats.document', $contrat))->assertForbidden();
        $this->generer($user, $contrat)->assertForbidden();
        $this->actingAs($user)->get(route('contrats.pdf', $contrat))->assertForbidden();
        $this->assertSame(0, DocumentContrat::count());
    }

    public function test_hors_de_ses_agences_la_generation_est_refusee(): void
    {
        $this->activerModele();
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kaloum', 'type' => 'depot']);
        $manager = $this->utilisateur(self::GENERATION, 'manager');

        $this->generer($manager, $this->contrat($this->site))->assertSessionHasNoErrors();
        $this->generer($manager, $this->contrat($autreSite, matricule: '548817'))->assertForbidden();
        $this->generer($manager, $this->contrat(null, matricule: '653228'))->assertForbidden();
        $this->assertSame(1, DocumentContrat::count());
    }

    public function test_sans_version_generee_le_pdf_du_contrat_est_introuvable(): void
    {
        $contrat = $this->contrat($this->site);

        $this->actingAs($this->utilisateur(['rh-contrats.read']))->get(route('contrats.pdf', $contrat))->assertNotFound();
    }

    public function test_une_version_d_un_autre_contrat_est_introuvable(): void
    {
        $this->activerModele();
        $contrat = $this->contrat($this->site);
        $autre = $this->contrat($this->site, matricule: '548817');
        $user = $this->utilisateur(self::GENERATION);
        $this->generer($user, $autre)->assertSessionHasNoErrors();
        $document = DocumentContrat::sole();

        $this->actingAs($user)->get(route('contrats.documents.pdf', [$contrat, $document]))->assertNotFound();
        $this->actingAs($user)->post(route('contrats.documents.signature', [$contrat, $document]), ['signe_le' => today()->toDateString()])->assertNotFound();
    }

    public function test_un_fichier_disparu_est_reconstruit_depuis_l_instantane(): void
    {
        $this->activerModele();
        $contrat = $this->contrat($this->site);
        $user = $this->utilisateur(self::GENERATION);
        $this->generer($user, $contrat)->assertSessionHasNoErrors();
        Storage::disk(DocumentContratService::DISQUE)->delete(DocumentContrat::sole()->fichier_path);

        $reponse = $this->actingAs($user)->get(route('contrats.pdf', $contrat));

        $reponse->assertOk();
        $this->assertStringStartsWith('%PDF', $reponse->getContent());
    }

    public function test_la_page_propose_les_donnees_les_champs_et_les_primes(): void
    {
        $this->activerModele();
        $contrat = $this->contrat($this->site);
        $prime = $this->prime($contrat);

        $this->actingAs($this->utilisateur(self::GENERATION))->get(route('contrats.document', $contrat))
            ->assertInertia(fn (Assert $page) => $page->component('Contrats/Document')
                ->where('peut_generer', true)
                ->where('modele.actif', true)
                ->where('champs.lieu_travail', 'Matoto, Conakry')
                ->where('champs.lieu_signature', 'Conakry')
                ->where('avantages.0.id', $prime->id)
                ->where('utilise_avantages', true)
                ->where('variables', fn ($variables) => collect($variables)->contains(fn ($v) => $v['cle'] === 'contrat.salaire_base' && $v['valeur'] === '750 000 GNF'))
                ->where('champs_utilises', fn ($champs) => collect($champs)->pluck('champ')->sort()->values()->all() === ['horaires', 'lieu_signature', 'lieu_travail', 'periode_essai', 'representant_nom', 'representant_qualite']));
    }

    public function test_les_champs_saisis_sont_reproposes_a_la_version_suivante(): void
    {
        $this->activerModele();
        $contrat = $this->contrat($this->site);
        $user = $this->utilisateur(self::GENERATION);
        $this->generer($user, $contrat, array_merge(self::CHAMPS, ['horaires' => '45 heures']))->assertSessionHasNoErrors();

        $this->actingAs($user)->get(route('contrats.document', $contrat))
            ->assertInertia(fn (Assert $page) => $page->where('champs.horaires', '45 heures')->where('champs.representant_nom', 'Mamadou BAH'));

        // Le représentant est aussi proposé pour le premier contrat d'un autre salarié.
        $autre = $this->contrat($this->site, matricule: '548817');
        $this->actingAs($user)->get(route('contrats.document', $autre))
            ->assertInertia(fn (Assert $page) => $page->where('champs.representant_nom', 'Mamadou BAH')->where('champs.horaires', null));
    }

    public function test_la_liste_des_contrats_indique_la_version_courante_et_le_droit_de_generer(): void
    {
        $this->activerModele();
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kaloum', 'type' => 'depot']);
        $contrat = $this->contrat($this->site);
        $this->contrat($autreSite, matricule: '548817');
        $manager = $this->utilisateur([...self::GENERATION, 'sites.lecture_toutes_agences'], 'manager');
        $this->generer($manager, $contrat)->assertSessionHasNoErrors();

        $this->actingAs($manager)->get(route('contrats.index'))
            ->assertInertia(fn (Assert $page) => $page->component('Contrats/Index')
                ->where('contrats', function ($contrats) use ($contrat) {
                    $parId = collect($contrats)->keyBy('id');
                    $autre = collect($contrats)->firstWhere('id', '!=', $contrat->id);

                    return $parId[$contrat->id]['document_courant']['version'] === 1
                        && $parId[$contrat->id]['document_courant']['statut'] === 'a_signer'
                        && $parId[$contrat->id]['peut_generer'] === true
                        // Vision toutes agences en lecture, mais pas de génération hors rattachement.
                        && $autre['document_courant'] === null
                        && $autre['peut_generer'] === false;
                }));
    }
}
