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
use App\Services\Rh\PdgService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\DesigneUnPdg;
use Tests\TestCase;

/**
 * Contrat de travail généré automatiquement (docs/rh-contrat-pdf.md, ADR 0044 et 0048) : PDF
 * produit à la création et à la modification du contrat, sans formulaire de génération ; PDG
 * désigné obligatoire ; données reprises du dossier, du contrat et de l'agence ; jamais deux
 * versions identiques ; version signée jamais remplacée ; accès (permissions, organisation,
 * agence). Le rendu PDF est testé dans Documents\ModeleDocumentPdfTest.
 */
class DocumentContratTest extends TestCase
{
    use DesigneUnPdg;
    use RefreshDatabase;

    private const GENERATION = ['rh-contrats.read', 'rh-contrats.update', 'rh-remunerations.read'];

    private const CREATION = ['rh-contrats.read', 'rh-contrats.create', 'rh-contrats.update', 'rh-remunerations.read'];

    private Organization $org;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DocumentContratService::DISQUE);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->org = Organization::factory()->create(['name' => 'Eau La Maman']);
        $this->site = Site::create(['organization_id' => $this->org->id, 'nom' => 'Matoto', 'type' => 'depot', 'ville' => 'Conakry']);
        $this->designerPdg($this->org, 'Mamadou', 'BAH');
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

    private function employe(?Site $site = null, ?Organization $org = null, string $matricule = '373601', bool $avecFonction = true): Employe
    {
        $orgId = ($org ?? $this->org)->id;
        $personne = Personne::create([
            'organization_id' => $orgId, 'nom' => 'DIALLO', 'prenom' => 'Thierno Oumar', 'civilite' => 'monsieur',
            'telephone' => '+22462'.fake()->unique()->numerify('#######'),
        ]);
        $fonction = $avecFonction ? FonctionRh::create(['organization_id' => $orgId, 'libelle' => 'Chauffeur-livreur '.$matricule, 'code' => 'CL'.$matricule]) : null;

        return Employe::create([
            'organization_id' => $orgId, 'personne_id' => $personne->id, 'matricule' => $matricule,
            'type_employe' => 'interne', 'statut' => 'actif', 'site_id' => $site?->id, 'fonction_rh_id' => $fonction?->id,
        ]);
    }

    private function contrat(?Site $site = null, array $attributs = [], ?Organization $org = null, string $matricule = '373601', bool $avecFonction = true): Contrat
    {
        $employe = $this->employe($site, $org, $matricule, $avecFonction);

        return Contrat::create(array_merge([
            'organization_id' => $employe->organization_id, 'employe_id' => $employe->id, 'type_contrat' => TypeContrat::CDI->value,
            'date_debut' => '2026-01-15', 'salaire_base' => 750000, 'statut_contrat' => StatutContrat::ACTIF->value,
            'periode_essai_duree' => 3, 'periode_essai_unite' => 'mois', 'heures_hebdomadaires' => 40,
        ], $attributs));
    }

    private function activerModele(TypeContrat $type = TypeContrat::CDI, ?Organization $org = null): void
    {
        $orgId = ($org ?? $this->org)->id;
        app(ModeleContratService::class)->activer($orgId, $type, User::factory()->create(['organization_id' => $orgId]));
    }

    /** « Mettre à jour le PDF » : nouvelle tentative ou nouvelle version après signature. */
    private function generer(User $user, Contrat $contrat)
    {
        return $this->actingAs($user)->post(route('contrats.documents.store', $contrat));
    }

    private function creer(User $user, Employe $employe, array $donnees = [])
    {
        return $this->actingAs($user)->post(route('contrats.store'), [
            ...$this->conditionsContrat(),
            'employe_id' => $employe->id, 'type_contrat' => 'cdi', 'date_debut' => '2026-01-15', 'salaire_base' => 750000,
            ...$donnees,
        ]);
    }

    private function modifier(User $user, Contrat $contrat, array $donnees = [])
    {
        return $this->actingAs($user)->put(route('contrats.update', $contrat), [
            'employe_id' => $contrat->employe_id, 'type_contrat' => $contrat->type_contrat->value,
            'date_debut' => $contrat->date_debut->toDateString(), 'date_fin' => $contrat->date_fin?->toDateString(),
            'salaire_base' => (float) $contrat->salaire_base, 'periode_essai_duree' => $contrat->periode_essai_duree,
            'periode_essai_unite' => $contrat->periode_essai_unite, 'heures_hebdomadaires' => (float) $contrat->heures_hebdomadaires,
            'motif_cdd' => $contrat->motif_cdd,
            ...$donnees,
        ]);
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

    // ── Génération automatique à la création ─────────────────────────────────

    public function test_creer_le_contrat_produit_le_pdf_sans_autre_etape(): void
    {
        $this->activerModele();
        $employe = $this->employe($this->site);
        $user = $this->utilisateur(self::CREATION);

        $contrat = null;
        $this->creer($user, $employe)->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'Le PDF du contrat est prêt'))
            ->assertRedirect(route('contrats.document', $contrat = Contrat::sole()));

        $document = DocumentContrat::sole();
        $this->assertSame($contrat->id, $document->contrat_id);
        $this->assertSame($this->org->id, $document->organization_id);
        $this->assertSame(1, $document->version);
        $this->assertSame(StatutDocumentContrat::A_SIGNER, $document->statut);
        $this->assertSame($user->id, $document->genere_par);
        Storage::disk(DocumentContratService::DISQUE)->assertExists($document->fichier_path);
        $this->assertSame(hash('sha256', Storage::disk(DocumentContratService::DISQUE)->get($document->fichier_path)), $document->empreinte);

        $valeurs = $document->donnees['valeurs'];
        $this->assertSame('Eau La Maman', $valeurs['employeur.nom']);
        $this->assertSame('Mamadou BAH', $valeurs['employeur.representant_nom']);
        $this->assertSame(PdgService::QUALITE, $valeurs['employeur.representant_qualite']);
        $this->assertSame('Thierno Oumar DIALLO', $valeurs['salarie.nom_complet']);
        $this->assertSame('373601', $valeurs['salarie.matricule']);
        $this->assertSame('Chauffeur-livreur 373601', $valeurs['poste.fonction']);
        $this->assertSame('Matoto, Conakry', $valeurs['poste.lieu_travail']);
        $this->assertSame('3 mois', $valeurs['contrat.periode_essai']);
        $this->assertSame('40 heures par semaine', $valeurs['contrat.horaires']);
        $this->assertSame('750 000 GNF', $valeurs['contrat.salaire_base']);
        $this->assertSame(DocumentContratService::LIEU_SIGNATURE_BLANC, $valeurs['document.lieu_signature']);
        $this->assertSame('Mamadou BAH', $document->donnees['employeur']['representant_nom']);

        $texte = collect($document->donnees['articles'])->flatMap(fn ($a) => $a['paragraphes'])->implode(' ');
        $this->assertStringContainsString('représentée par Mamadou BAH, Président-directeur général', $texte);
        $this->assertStringContainsString('Fait à '.DocumentContratService::LIEU_SIGNATURE_BLANC.', en deux exemplaires', $texte);
        $this->assertStringContainsString('Période d’essai : 3 mois.', $texte);
        $this->assertStringContainsString('Durée et horaires de travail : 40 heures par semaine.', $texte);
    }

    public function test_sans_pdg_la_creation_du_contrat_est_refusee_avec_un_message_clair(): void
    {
        $this->activerModele();
        $this->org->forceFill(['pdg_employe_id' => null])->save();
        $employe = $this->employe($this->site);

        $this->creer($this->utilisateur(self::CREATION), $employe)
            ->assertSessionHasErrors(['pdg' => PdgService::MESSAGE_ABSENT]);

        $this->assertSame(0, Contrat::count());
        $this->assertSame(0, DocumentContrat::count());
    }

    public function test_sans_pdg_aucun_autre_salarie_ne_signe_a_sa_place(): void
    {
        $this->activerModele();
        $contrat = $this->contrat($this->site);
        $user = $this->utilisateur(self::GENERATION);
        $this->org->forceFill(['pdg_employe_id' => null])->save();

        $this->generer($user, $contrat)->assertSessionHasErrors(['pdg' => PdgService::MESSAGE_ABSENT]);
        $this->assertSame(0, DocumentContrat::count());
        $this->actingAs($user)->get(route('contrats.document', $contrat))
            ->assertInertia(fn (Assert $page) => $page->where('etat.code', 'a_completer')->where('etat.message', PdgService::MESSAGE_ABSENT));
    }

    public function test_le_pdg_d_une_autre_organisation_n_est_jamais_repris(): void
    {
        $this->activerModele();
        $contrat = $this->contrat($this->site);
        $salarieAilleurs = $this->employe(null, Organization::factory()->create(), '999999');
        // Référence incohérente (jamais possible par l'écran) : elle n'est pas suivie.
        $this->org->forceFill(['pdg_employe_id' => $salarieAilleurs->id])->save();

        $this->generer($this->utilisateur(self::GENERATION), $contrat)->assertSessionHasErrors('pdg');
        $this->assertSame(0, DocumentContrat::count());
    }

    public function test_un_pdg_sorti_n_est_plus_signataire(): void
    {
        $this->activerModele();
        $contrat = $this->contrat($this->site);
        Employe::whereKey($this->org->fresh()->pdg_employe_id)->update(['statut' => 'sorti']);

        $this->generer($this->utilisateur(self::GENERATION), $contrat)->assertSessionHasErrors('pdg');
    }

    public function test_sans_modele_actif_le_contrat_est_cree_et_le_pdf_signale_a_corriger(): void
    {
        $employe = $this->employe($this->site);
        $user = $this->utilisateur(self::CREATION);

        $this->creer($user, $employe)->assertSessionHasNoErrors()
            ->assertSessionHas('error', fn (string $m) => str_contains($m, 'Aucun modèle de CDI actif'));

        $contrat = Contrat::sole();
        $this->assertSame(0, DocumentContrat::count());
        $this->actingAs($user)->get(route('contrats.document', $contrat))
            ->assertInertia(fn (Assert $page) => $page->where('etat.code', 'a_completer'));

        // Nouvelle tentative une fois le modèle activé : une seule version, aucun doublon.
        $this->activerModele();
        $this->generer($user, $contrat)->assertSessionHasNoErrors()->assertSessionHas('success', fn (string $m) => str_contains($m, 'version 1'));
        $this->generer($user, $contrat)->assertSessionHasNoErrors()->assertSessionHas('success', 'Le PDF est déjà à jour.');
        $this->assertSame(1, Contrat::count());
        $this->assertSame(1, DocumentContrat::count());
    }

    public function test_une_information_manquante_dit_quoi_completer_et_ou(): void
    {
        $this->activerModele();
        $contrat = $this->contrat($this->site, ['salaire_base' => null, 'heures_hebdomadaires' => null], avecFonction: false);

        $this->generer($this->utilisateur(self::GENERATION), $contrat)
            ->assertSessionHasErrors(['manquantes' => 'Informations manquantes pour le PDF : fonction (fiche du salarié : RH → Employés → Modifier) ; salaire de base mensuel, heures de travail par semaine (contrat : RH → Contrats → Modifier).']);

        $this->assertSame(0, DocumentContrat::count());
        $this->assertSame([], Storage::disk(DocumentContratService::DISQUE)->allFiles());
    }

    // ── Conditions saisies : période d'essai et heures ──────────────────────

    public function test_la_periode_d_essai_et_les_heures_sont_des_nombres_valides(): void
    {
        $employe = $this->employe($this->site);
        $user = $this->utilisateur(self::CREATION);

        foreach ([
            [['periode_essai_duree' => -1], 'periode_essai_duree'],
            [['periode_essai_duree' => 'trois'], 'periode_essai_duree'],
            [['periode_essai_duree' => 1.5], 'periode_essai_duree'],
            [['periode_essai_duree' => null], 'periode_essai_duree'],
            [['periode_essai_unite' => null], 'periode_essai_unite'],
            [['periode_essai_unite' => 'jours'], 'periode_essai_unite'],
            [['heures_hebdomadaires' => -40], 'heures_hebdomadaires'],
            [['heures_hebdomadaires' => 0], 'heures_hebdomadaires'],
            [['heures_hebdomadaires' => 'quarante'], 'heures_hebdomadaires'],
            [['heures_hebdomadaires' => 200], 'heures_hebdomadaires'],
            [['heures_hebdomadaires' => null], 'heures_hebdomadaires'],
            [['type_contrat' => 'cdd', 'date_fin' => '2026-12-31', 'motif_cdd' => null], 'motif_cdd'],
        ] as [$donnees, $champ]) {
            $this->creer($user, $employe, $donnees)->assertSessionHasErrors($champ);
        }
        $this->assertSame(0, Contrat::count());
    }

    public function test_sans_periode_d_essai_le_contrat_indique_aucune_et_les_decimales_d_heures_sont_acceptees(): void
    {
        $this->activerModele();
        $employe = $this->employe($this->site);

        $this->creer($this->utilisateur(self::CREATION), $employe, ['periode_essai_duree' => 0, 'periode_essai_unite' => 'mois', 'heures_hebdomadaires' => 37.5])
            ->assertSessionHasNoErrors();

        $contrat = Contrat::sole();
        $this->assertSame(0, $contrat->periode_essai_duree);
        $this->assertNull($contrat->periode_essai_unite);
        $valeurs = DocumentContrat::sole()->donnees['valeurs'];
        $this->assertSame('aucune', $valeurs['contrat.periode_essai']);
        $this->assertSame('37,5 heures par semaine', $valeurs['contrat.horaires']);
    }

    public function test_textes_de_la_periode_d_essai(): void
    {
        $this->assertSame('2 semaines', DocumentContratService::textePeriodeEssai(2, 'semaine'));
        $this->assertSame('1 semaine', DocumentContratService::textePeriodeEssai(1, 'semaine'));
        $this->assertSame('6 mois', DocumentContratService::textePeriodeEssai(6, 'mois'));
        $this->assertSame('aucune', DocumentContratService::textePeriodeEssai(0, null));
        $this->assertNull(DocumentContratService::textePeriodeEssai(3, null));
        $this->assertNull(DocumentContratService::textePeriodeEssai(null, 'mois'));
    }

    public function test_un_cdd_reprend_sa_date_de_fin_et_son_motif(): void
    {
        $this->activerModele(TypeContrat::CDD);
        $employe = $this->employe($this->site);

        $this->creer($this->utilisateur(self::CREATION), $employe, ['type_contrat' => 'cdd', 'date_fin' => '2026-12-31', 'motif_cdd' => 'remplacement d’un salarié absent'])
            ->assertSessionHasNoErrors();

        $articles = collect(DocumentContrat::sole()->donnees['articles'])->flatMap(fn ($a) => $a['paragraphes'])->implode(' ');
        $this->assertStringContainsString('prend fin le 31/12/2026', $articles);
        $this->assertStringContainsString('remplacement d’un salarié absent', $articles);
    }

    // ── Mise à jour à la modification ────────────────────────────────────────

    public function test_modifier_le_contrat_met_le_pdf_a_jour_sans_doublon(): void
    {
        $this->activerModele();
        $user = $this->utilisateur(self::CREATION);
        $this->creer($user, $this->employe($this->site))->assertSessionHasNoErrors();
        $contrat = Contrat::sole();
        $v1 = DocumentContrat::sole();

        // Enregistrer sans rien changer : aucune nouvelle version.
        $this->modifier($user, $contrat)->assertSessionHasNoErrors()->assertRedirect(route('contrats.document', $contrat));
        $this->assertSame(1, DocumentContrat::count());

        $this->modifier($user, $contrat, ['salaire_base' => 900000, 'periode_essai_duree' => 2, 'periode_essai_unite' => 'semaine'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn (string $m) => str_contains($m, 'Le PDF du contrat est prêt'));

        $versions = DocumentContrat::orderBy('version')->get();
        $this->assertSame([StatutDocumentContrat::REMPLACE, StatutDocumentContrat::A_SIGNER], $versions->pluck('statut')->all());
        $this->assertSame('900 000 GNF', $versions[1]->donnees['valeurs']['contrat.salaire_base']);
        $this->assertSame('2 semaines', $versions[1]->donnees['valeurs']['contrat.periode_essai']);
        // L'ancienne version reste intacte et consultable.
        $this->assertSame('750 000 GNF', $v1->fresh()->donnees['valeurs']['contrat.salaire_base']);
        Storage::disk(DocumentContratService::DISQUE)->assertExists($v1->fichier_path);
        $this->assertSame(2, $contrat->documentCourant()->first()->version);
    }

    public function test_une_version_signee_est_conservee_et_une_clause_modifiee_prepare_une_nouvelle_version(): void
    {
        $this->activerModele();
        $user = $this->utilisateur(self::CREATION);
        $this->creer($user, $this->employe($this->site))->assertSessionHasNoErrors();
        $contrat = Contrat::sole();
        $v1 = DocumentContrat::sole();

        $this->actingAs($user)->post(route('contrats.documents.signature', [$contrat, $v1]), ['signe_le' => today()->toDateString()])
            ->assertSessionHasNoErrors();
        $v1->refresh();
        $this->assertSame(StatutDocumentContrat::SIGNE, $v1->statut);
        $this->assertSame($user->id, $v1->signature_enregistree_par);
        $this->actingAs($user)->post(route('contrats.documents.signature', [$contrat, $v1]), ['signe_le' => today()->toDateString()])
            ->assertSessionHasErrors('signe_le');

        // Une information hors contrat change (fiche du salarié) : la version signée n'est pas
        // remplacée automatiquement, ni à l'ouverture de la page ni à celle du PDF.
        $contrat->employe->fonction->update(['libelle' => 'Superviseur']);
        $this->actingAs($user)->get(route('contrats.document', $contrat))
            ->assertInertia(fn (Assert $page) => $page->where('etat.code', 'signe_different'));
        $this->actingAs($user)->get(route('contrats.pdf', $contrat))->assertOk();
        $this->assertSame(1, DocumentContrat::count());

        // Une clause du contrat change : une nouvelle version à faire signer est préparée.
        $this->modifier($user, $contrat, ['heures_hebdomadaires' => 45])->assertSessionHasNoErrors();
        $this->assertSame(StatutDocumentContrat::SIGNE, $v1->fresh()->statut);
        $v2 = DocumentContrat::where('version', 2)->sole();
        $this->assertSame(StatutDocumentContrat::A_SIGNER, $v2->statut);
        $this->assertSame('45 heures par semaine', $v2->donnees['valeurs']['contrat.horaires']);
        Storage::disk(DocumentContratService::DISQUE)->assertExists($v1->fichier_path);
    }

    public function test_apres_signature_une_nouvelle_version_peut_etre_preparee_a_la_demande(): void
    {
        $this->activerModele();
        $contrat = $this->contrat($this->site);
        $user = $this->utilisateur(self::GENERATION);
        $this->generer($user, $contrat)->assertSessionHasNoErrors();
        $v1 = DocumentContrat::sole();
        $this->actingAs($user)->post(route('contrats.documents.signature', [$contrat, $v1]), ['signe_le' => today()->toDateString()]);

        // Rien n'a changé : pas de nouvelle version.
        $this->generer($user, $contrat)->assertSessionHas('success', 'Le PDF est déjà à jour.');
        $contrat->employe->personne->update(['nom' => 'BARRY']);
        $this->generer($user, $contrat)->assertSessionHasNoErrors();

        $this->assertSame(StatutDocumentContrat::SIGNE, $v1->fresh()->statut);
        $this->assertSame('Thierno Oumar BARRY', DocumentContrat::where('version', 2)->sole()->donnees['valeurs']['salarie.nom_complet']);
    }

    public function test_ouvrir_le_pdf_apres_un_changement_du_dossier_le_met_a_jour(): void
    {
        $this->activerModele();
        $contrat = $this->contrat($this->site);
        $user = $this->utilisateur(self::GENERATION);
        $this->generer($user, $contrat)->assertSessionHasNoErrors();
        $v1 = DocumentContrat::sole();
        $avant = $this->actingAs($user)->get(route('contrats.pdf', $contrat))->getContent();

        $contrat->employe->fonction->update(['libelle' => 'Superviseur']);
        $lecteur = $this->utilisateur(['rh-contrats.read', 'rh-remunerations.read']);
        // Lecture seule : le PDF est servi tel quel, rien n'est produit.
        $this->assertSame($avant, $this->actingAs($lecteur)->get(route('contrats.pdf', $contrat))->getContent());
        $this->assertSame(1, DocumentContrat::count());

        $apres = $this->actingAs($user)->get(route('contrats.pdf', $contrat))->getContent();
        $this->assertNotSame($avant, $apres);
        $this->assertSame(StatutDocumentContrat::REMPLACE, $v1->fresh()->statut);
        $this->assertSame('Chauffeur-livreur 373601', $v1->fresh()->donnees['valeurs']['poste.fonction']);
        $this->assertSame('Superviseur', DocumentContrat::where('version', 2)->sole()->donnees['valeurs']['poste.fonction']);
    }

    public function test_un_contrat_termine_n_est_plus_mis_a_jour(): void
    {
        $this->activerModele();
        $contrat = $this->contrat($this->site, ['statut_contrat' => StatutContrat::TERMINE->value]);

        $this->generer($this->utilisateur(self::GENERATION), $contrat)->assertSessionHasErrors('contrat');
        $this->assertSame(0, DocumentContrat::count());
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

    // ── Primes ───────────────────────────────────────────────────────────────

    public function test_toutes_les_primes_validees_du_salarie_et_elles_seules_sont_reprises(): void
    {
        $this->activerModele();
        $contrat = $this->contrat($this->site);
        $autre = $this->contrat($this->site, matricule: '548817');
        $validee = $this->prime($contrat);
        $this->prime($contrat, StatutAffectationRemuneration::A_VALIDER->value);
        $this->prime($contrat, autre: $autre->employe);

        $this->generer($this->utilisateur(self::GENERATION), $contrat)->assertSessionHasNoErrors();

        $donnees = DocumentContrat::sole()->donnees;
        $this->assertCount(1, $donnees['avantages']);
        $this->assertSame($validee->id, $donnees['avantages'][0]['affectation_id']);
        $this->assertStringContainsString('(50 000 GNF par mois)', $donnees['valeurs']['contrat.avantages']);
    }

    public function test_sans_prime_le_contrat_l_indique_clairement(): void
    {
        $this->activerModele();
        $contrat = $this->contrat($this->site);

        $this->generer($this->utilisateur(self::GENERATION), $contrat)->assertSessionHasNoErrors();

        $this->assertSame('aucune prime ni aucun avantage', DocumentContrat::sole()->donnees['valeurs']['contrat.avantages']);
    }

    // ── Page du contrat et liste ─────────────────────────────────────────────

    public function test_la_page_montre_l_etat_et_les_informations_reprises(): void
    {
        $this->activerModele();
        $contrat = $this->contrat($this->site);
        $this->prime($contrat);

        $this->actingAs($this->utilisateur(self::GENERATION))->get(route('contrats.document', $contrat))
            ->assertInertia(fn (Assert $page) => $page->component('Contrats/Document')
                ->where('peut_generer', true)
                // Ouvrir la page a produit le PDF manquant.
                ->where('etat.code', 'a_jour')
                ->has('versions', 1)
                ->where('avantages.0', fn (string $t) => str_contains($t, '50 000 GNF par mois'))
                ->where('variables', fn ($variables) => collect($variables)->contains(fn ($v) => $v['cle'] === 'employeur.representant_nom' && $v['valeur'] === 'Mamadou BAH')
                    && collect($variables)->contains(fn ($v) => $v['cle'] === 'contrat.periode_essai' && $v['valeur'] === '3 mois')
                    && collect($variables)->doesntContain(fn ($v) => $v['cle'] === 'document.lieu_signature'))
                ->missing('champs')
                ->missing('champs_utilises'));
    }

    public function test_la_lecture_seule_ne_permet_ni_de_mettre_a_jour_ni_de_signer(): void
    {
        $this->activerModele();
        $contrat = $this->contrat($this->site);
        $this->generer($this->utilisateur(self::GENERATION), $contrat)->assertSessionHasNoErrors();
        $document = DocumentContrat::sole();
        $lecteur = $this->utilisateur(['rh-contrats.read', 'rh-remunerations.read']);

        $this->generer($lecteur, $contrat)->assertForbidden();
        $this->actingAs($lecteur)->post(route('contrats.documents.signature', [$contrat, $document]), ['signe_le' => today()->toDateString()])->assertForbidden();
        $this->actingAs($lecteur)->get(route('contrats.pdf', $contrat))->assertOk();
        $this->actingAs($lecteur)->get(route('contrats.document', $contrat))
            ->assertInertia(fn (Assert $page) => $page->component('Contrats/Document')
                ->where('peut_generer', false)
                ->has('versions', 1));
    }

    public function test_consulter_un_contrat_genere_exige_de_pouvoir_voir_les_salaires(): void
    {
        $this->activerModele();
        $contrat = $this->contrat($this->site);
        $this->generer($this->utilisateur(self::GENERATION), $contrat)->assertSessionHasNoErrors();
        $document = DocumentContrat::sole();
        $sansSalaires = $this->utilisateur(['rh-contrats.read']);

        $this->actingAs($sansSalaires)->get(route('contrats.pdf', $contrat))->assertForbidden();
        $this->actingAs($sansSalaires)->get(route('contrats.documents.pdf', [$contrat, $document]))->assertForbidden();
        $this->actingAs($sansSalaires)->get(route('contrats.document', $contrat))->assertForbidden();
        $this->actingAs($sansSalaires)->get(route('contrats.index'))
            ->assertInertia(fn (Assert $page) => $page->where('contrats.0.salaire_base', null));
    }

    public function test_mettre_a_jour_exige_de_pouvoir_voir_les_salaires(): void
    {
        $this->activerModele();
        $contrat = $this->contrat($this->site);

        $this->generer($this->utilisateur(['rh-contrats.read', 'rh-contrats.update']), $contrat)->assertForbidden();
    }

    public function test_sans_droit_de_voir_les_salaires_le_contrat_est_cree_et_le_salaire_signale_manquant(): void
    {
        $this->activerModele();
        $employe = $this->employe($this->site);
        $user = $this->utilisateur(['rh-contrats.read', 'rh-contrats.create']);

        // Le salaire envoyé est ignoré sans ce droit : le PDF attend qu'il soit saisi.
        $this->creer($user, $employe)->assertSessionHasNoErrors()
            ->assertRedirect(route('employes.edit', $employe))
            ->assertSessionHas('error', fn (string $m) => str_contains($m, 'salaire de base mensuel'));

        $this->assertSame(1, Contrat::count());
        $this->assertSame(0, DocumentContrat::count());
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
        $this->designerPdg($autreOrg);
        $contrat = $this->contrat(null, [], $autreOrg);
        $user = $this->utilisateur(self::GENERATION);

        $this->actingAs($user)->get(route('contrats.document', $contrat))->assertForbidden();
        $this->generer($user, $contrat)->assertForbidden();
        $this->actingAs($user)->get(route('contrats.pdf', $contrat))->assertForbidden();
        $this->assertSame(0, DocumentContrat::count());
    }

    public function test_hors_de_ses_agences_la_mise_a_jour_est_refusee(): void
    {
        $this->activerModele();
        $autreSite = Site::create(['organization_id' => $this->org->id, 'nom' => 'Kaloum', 'type' => 'depot']);
        $manager = $this->utilisateur(self::GENERATION, 'manager');

        $this->generer($manager, $this->contrat($this->site))->assertSessionHasNoErrors();
        $this->generer($manager, $this->contrat($autreSite, matricule: '548817'))->assertForbidden();
        $this->generer($manager, $this->contrat(null, matricule: '653228'))->assertForbidden();
        $this->assertSame(1, DocumentContrat::count());
    }

    public function test_voir_le_contrat_l_affiche_dans_le_navigateur_sans_le_telecharger(): void
    {
        $this->activerModele();
        $contrat = $this->contrat($this->site);
        $this->generer($this->utilisateur(self::GENERATION), $contrat)->assertSessionHasNoErrors();
        $lecteur = $this->utilisateur(['rh-contrats.read', 'rh-remunerations.read']);

        $reponse = $this->actingAs($lecteur)->get(route('contrats.pdf', [$contrat, 'preview' => 1]));

        $reponse->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('inline;', $reponse->headers->get('Content-Disposition'));
        $this->assertStringContainsString('.pdf', $reponse->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', $reponse->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', $reponse->headers->get('Cache-Control'));
        $this->assertStringStartsWith('%PDF', $reponse->getContent());

        // Le téléchargement direct reste disponible pour les autres écrans.
        $this->assertStringStartsWith('attachment;', $this->actingAs($lecteur)->get(route('contrats.pdf', $contrat))->headers->get('Content-Disposition'));

        // L'aperçu garde les mêmes contrôles d'accès.
        $this->actingAs($this->utilisateur(['rh-contrats.read']))->get(route('contrats.pdf', [$contrat, 'preview' => 1]))->assertForbidden();
        $autreOrg = Organization::factory()->create();
        $intrus = $this->utilisateur(['rh-contrats.read', 'rh-remunerations.read'], org: $autreOrg);
        $intrus->sites()->sync([Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Kaloum', 'type' => 'depot'])->id => ['role' => 'employe', 'is_default' => true]]);
        $this->actingAs($intrus)->get(route('contrats.pdf', [$contrat, 'preview' => 1]))->assertForbidden();
    }

    public function test_sans_version_le_pdf_du_contrat_est_introuvable_en_lecture_seule(): void
    {
        $this->activerModele();
        $contrat = $this->contrat($this->site);

        $this->actingAs($this->utilisateur(['rh-contrats.read', 'rh-remunerations.read']))->get(route('contrats.pdf', $contrat))->assertNotFound();
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
        $this->assertSame(1, DocumentContrat::count());
    }

    public function test_la_liste_des_contrats_indique_la_version_courante_et_le_droit_de_mettre_a_jour(): void
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
                        // Vision toutes agences en lecture, mais pas de mise à jour hors rattachement.
                        && $autre['document_courant'] === null
                        && $autre['peut_generer'] === false;
                }));
    }
}
