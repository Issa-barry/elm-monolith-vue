<?php

namespace Tests\Feature;

use App\Enums\EvenementComptable;
use App\Enums\StatutCommandeAchat;
use App\Enums\StatutFactureFournisseur;
use App\Features\ModuleFeature;
use App\Models\CommandeAchat;
use App\Models\CommandeAchatLigne;
use App\Models\CompteComptable;
use App\Models\CompteMapping;
use App\Models\EcritureComptable;
use App\Models\EntrepriseTierce;
use App\Models\FactureFournisseur;
use App\Models\Fournisseur;
use App\Models\JournalComptable;
use App\Models\Organization;
use App\Models\PieceComptable;
use App\Models\ReceptionAchat;
use App\Models\ReceptionAchatLigne;
use App\Models\RegleValidationRole;
use App\Models\Site;
use App\Models\User;
use App\Services\Achats\FactureFournisseurService;
use App\Services\Achats\PaiementFournisseurService;
use App\Services\Comptabilite\EcritureComptableService;
use App\Services\Comptabilite\FactureFournisseurComptabilisationService;
use App\Services\Comptabilite\PlanComptableBootstrapService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Pennant\Feature;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Factures fournisseurs (ADR 0022) : facture de lignes reçues d'un bon validé, quantités jamais
 * facturées deux fois, dette constatée à la validation, séparation saisie/validation, périmètre,
 * comptabilisation par mapping au moment de la validation.
 */
class FactureFournisseurTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private const PERMISSIONS_SAISIE = ['achats.read', 'factures-fournisseurs.read', 'factures-fournisseurs.create', 'factures-fournisseurs.update', 'factures-fournisseurs.annuler'];

    private const MOTIF_SAISIE = 'Vous avez saisi cette facture : votre rôle ne permet pas de valider vos propres factures, elle doit être validée par une autre personne.';

    private const MOTIF_MODIFICATION = 'Vous avez modifié cette facture en dernier : votre rôle ne permet pas de valider vos propres factures, elle doit être validée par une autre personne.';

    private Site $site;

    private Fournisseur $fournisseur;

    private CommandeAchat $commande;

    private CommandeAchatLigne $ligneA;

    private CommandeAchatLigne $ligneB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(self::PERMISSIONS_SAISIE);
        Feature::for($this->org)->activate(ModuleFeature::ACHATS);
        $this->site = Site::where('organization_id', $this->org->id)->firstOrFail();
        $this->regle('admin_entreprise');

        // Point de départ de ces tests : comptes d'achat et de TVA NON paramétrés (organisation dont
        // le comptable a retiré les comptes provisoires du plan par défaut). Les tests qui en ont
        // besoin les paramètrent avec mapperCompteAchat() / mapperTva() ; ceux du plan par défaut
        // les rétablissent avec PlanComptableBootstrapService.
        CompteMapping::where('organization_id', $this->org->id)
            ->where('evenement', EvenementComptable::FACTURE_FOURNISSEUR_VALIDEE->value)
            ->whereIn('role', ['achat', 'tva_deductible'])
            ->delete();

        $this->fournisseur = $this->makeFournisseur($this->org, 'FOURNISSEUR A');
        [$this->commande, $this->ligneA, $this->ligneB] = $this->commandeValidee($this->org, $this->site, $this->fournisseur);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeFournisseur(Organization $org, string $nom): Fournisseur
    {
        $entreprise = EntrepriseTierce::create(['organization_id' => $org->id, 'raison_sociale' => $nom]);

        return Fournisseur::create(['organization_id' => $org->id, 'entreprise_tierce_id' => $entreprise->id, 'is_active' => true]);
    }

    private function regle(string $role, string $perimetre = 'toutes_agences', array $sites = []): void
    {
        RegleValidationRole::create([
            'organization_id' => $this->org->id, 'domaine' => RegleValidationRole::DOMAINE_ACHATS,
            'role_name' => $role, 'perimetre' => $perimetre, 'sites' => $sites ?: null,
        ]);
    }

    /** @return array{0: CommandeAchat, 1: CommandeAchatLigne, 2: CommandeAchatLigne} */
    private function commandeValidee(Organization $org, Site $site, Fournisseur $fournisseur, StatutCommandeAchat $statut = StatutCommandeAchat::VALIDEE): array
    {
        $produitA = $this->makeProduitAvecVariante($org, ['nom' => 'Préformes 500 ml', 'type' => 'materiel']);
        $produitB = $this->makeProduitAvecVariante($org, ['nom' => 'Bouchons', 'type' => 'materiel']);
        $commande = CommandeAchat::create([
            'organization_id' => $org->id, 'site_id' => $site->id, 'fournisseur_id' => $fournisseur->id,
            'total_commande' => 150_000, 'statut' => $statut,
            'validee_at' => $statut === StatutCommandeAchat::A_VALIDER ? null : now(),
        ]);
        $a = $commande->lignes()->create(['variante_id' => $produitA->variantes()->first()->id, 'qte' => 100, 'prix_achat_snapshot' => 1000, 'total_ligne' => 100_000, 'libelle_snapshot' => 'Préformes 500 ml']);
        $b = $commande->lignes()->create(['variante_id' => $produitB->variantes()->first()->id, 'qte' => 100, 'prix_achat_snapshot' => 500, 'total_ligne' => 50_000, 'libelle_snapshot' => 'Bouchons']);

        return [$commande, $a, $b];
    }

    private function receptionner(CommandeAchat $commande, array $quantites, string $date = '2026-10-01'): ReceptionAchat
    {
        static $n = 0;
        $n++;
        $reception = ReceptionAchat::create([
            'organization_id' => $commande->organization_id, 'commande_achat_id' => $commande->id, 'site_id' => $commande->site_id,
            'reference' => "RCA-TEST-{$n}", 'date_reception' => $date,
        ]);
        foreach ($quantites as $ligneId => $qte) {
            $ligne = CommandeAchatLigne::find($ligneId);
            $reception->lignes()->create(['commande_achat_ligne_id' => $ligneId, 'variante_id' => $ligne->variante_id, 'qte_recue' => $qte, 'cout_unitaire' => $ligne->prix_achat_snapshot]);
            $ligne->increment('qte_recue', $qte);
        }

        return $reception->load('lignes');
    }

    private function ligneRecue(ReceptionAchat $reception, CommandeAchatLigne $ligne): ReceptionAchatLigne
    {
        return $reception->lignes->firstWhere('commande_achat_ligne_id', $ligne->id);
    }

    private function payload(array $lignes, array $overrides = []): array
    {
        return array_merge([
            'commande_achat_id' => $this->commande->id,
            'fournisseur_id' => $this->fournisseur->id,
            'numero_facture_fournisseur' => 'F-2026-001',
            'date_facture' => '2026-10-02',
            'taux_tva' => 0,
            'lignes' => $lignes,
        ], $overrides);
    }

    private function ligne(ReceptionAchatLigne $l, int $qte, float $prix = 1000): array
    {
        return ['reception_ligne_id' => $l->id, 'qte' => $qte, 'prix_unitaire' => $prix];
    }

    private function saisir(array $payload, ?User $user = null): FactureFournisseur
    {
        $this->actingAs($user ?? $this->user)->post(route('achats.factures.store'), $payload)->assertSessionHasNoErrors();

        return FactureFournisseur::orderByDesc('numero')->firstOrFail();
    }

    private function makeValidateur(): User
    {
        foreach (['achats.read', 'factures-fournisseurs.read', 'factures-fournisseurs.valider'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $role = Role::firstOrCreate(['name' => 'comptable_test', 'guard_name' => 'web']);
        $role->givePermissionTo(['achats.read', 'factures-fournisseurs.read', 'factures-fournisseurs.valider']);
        if (! RegleValidationRole::where('role_name', 'comptable_test')->exists()) {
            $this->regle('comptable_test');
        }
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole($role);
        $user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        return $user;
    }

    private function mapperCompteAchat(): void
    {
        $compte = CompteComptable::firstOrCreate(['organization_id' => $this->org->id, 'numero' => '601000'], ['libelle' => 'Achats (test)', 'actif' => true]);
        CompteMapping::create([
            'organization_id' => $this->org->id, 'evenement' => EvenementComptable::FACTURE_FOURNISSEUR_VALIDEE->value,
            'role' => 'achat', 'compte_comptable_id' => $compte->id,
            'journal_comptable_id' => JournalComptable::where('organization_id', $this->org->id)->where('code', 'AC')->value('id'),
            'actif' => true,
        ]);
    }

    private function mapperTva(): void
    {
        $compte = CompteComptable::firstOrCreate(['organization_id' => $this->org->id, 'numero' => '445200'], ['libelle' => 'TVA déductible (test)', 'actif' => true]);
        CompteMapping::create([
            'organization_id' => $this->org->id, 'evenement' => EvenementComptable::FACTURE_FOURNISSEUR_VALIDEE->value,
            'role' => 'tva_deductible', 'compte_comptable_id' => $compte->id, 'journal_comptable_id' => null, 'actif' => true,
        ]);
    }

    private function piece(FactureFournisseur $facture): ?PieceComptable
    {
        return PieceComptable::where('source_id', $facture->id)->where('type_evenement', EvenementComptable::FACTURE_FOURNISSEUR_VALIDEE->value)->first();
    }

    // ── Création ──────────────────────────────────────────────────────────────

    public function test_creation_d_une_facture_en_brouillon_liee_au_bon_valide(): void
    {
        $r = $this->receptionner($this->commande, [$this->ligneA->id => 60]);

        $facture = $this->saisir($this->payload([$this->ligne($this->ligneRecue($r, $this->ligneA), 60, 1000)]));

        $this->assertSame(StatutFactureFournisseur::BROUILLON, $facture->statut);
        $this->assertSame($this->commande->id, $facture->commande_achat_id);
        $this->assertSame($this->fournisseur->id, $facture->fournisseur_id);
        $this->assertSame($this->site->id, $facture->site_id);
        $this->assertStringStartsWith('FAF-', $facture->reference);
        $this->assertSame(60_000.0, (float) $facture->montant_ht);
        $this->assertSame(0.0, $facture->resteDu());
        $this->assertSame('Préformes 500 ml', $facture->lignes()->first()->libelle_snapshot);
    }

    public function test_refus_si_le_bon_n_est_pas_valide(): void
    {
        [$commande] = $this->commandeValidee($this->org, $this->site, $this->fournisseur, StatutCommandeAchat::A_VALIDER);

        $this->actingAs($this->user)
            ->post(route('achats.factures.store'), $this->payload([['reception_ligne_id' => 'x', 'qte' => 1, 'prix_unitaire' => 1]], ['commande_achat_id' => $commande->id]))
            ->assertSessionHasErrors(['commande' => 'Seul un bon de commande validé peut être facturé.']);
    }

    public function test_refus_d_une_quantite_superieure_au_recu(): void
    {
        $r = $this->receptionner($this->commande, [$this->ligneA->id => 60]);

        $this->actingAs($this->user)
            ->post(route('achats.factures.store'), $this->payload([$this->ligne($this->ligneRecue($r, $this->ligneA), 61)]))
            ->assertSessionHasErrors('lignes.0.qte');
        $this->assertSame(0, FactureFournisseur::count());
    }

    public function test_refus_d_un_fournisseur_incoherent_avec_le_bon(): void
    {
        $r = $this->receptionner($this->commande, [$this->ligneA->id => 10]);
        $autre = $this->makeFournisseur($this->org, 'AUTRE FOURNISSEUR');

        $this->actingAs($this->user)
            ->post(route('achats.factures.store'), $this->payload([$this->ligne($this->ligneRecue($r, $this->ligneA), 10)], ['fournisseur_id' => $autre->id]))
            ->assertSessionHasErrors('fournisseur_id');
    }

    public function test_une_facture_couvre_plusieurs_receptions(): void
    {
        $r1 = $this->receptionner($this->commande, [$this->ligneA->id => 40]);
        $r2 = $this->receptionner($this->commande, [$this->ligneA->id => 30, $this->ligneB->id => 50], '2026-10-02');

        $facture = $this->saisir($this->payload([
            $this->ligne($this->ligneRecue($r1, $this->ligneA), 40),
            $this->ligne($this->ligneRecue($r2, $this->ligneA), 30),
            $this->ligne($this->ligneRecue($r2, $this->ligneB), 50, 500),
        ]));

        $this->assertSame(3, $facture->lignes()->count());
        $this->assertSame(95_000.0, (float) $facture->montant_ht);
        $this->actingAs($this->makeValidateur())->patch(route('achats.factures.valider', $facture))->assertSessionHasNoErrors();
        $this->assertSame(StatutFactureFournisseur::VALIDEE, $facture->fresh()->statut);
    }

    // ── Double facturation ────────────────────────────────────────────────────

    public function test_plusieurs_factures_sur_un_bon_dans_la_limite_du_recu_et_jamais_deux_fois(): void
    {
        $r = $this->receptionner($this->commande, [$this->ligneA->id => 60]);
        $ligne = $this->ligneRecue($r, $this->ligneA);
        $validateur = $this->makeValidateur();

        $f1 = $this->saisir($this->payload([$this->ligne($ligne, 40)]));
        $this->actingAs($validateur)->patch(route('achats.factures.valider', $f1))->assertSessionHasNoErrors();

        // Reste facturable : 20 — une seconde facture de 20 passe, au-delà non.
        $this->actingAs($this->user)
            ->post(route('achats.factures.store'), $this->payload([$this->ligne($ligne, 21)], ['numero_facture_fournisseur' => 'F-2026-002']))
            ->assertSessionHasErrors('lignes.0.qte');
        $f2 = $this->saisir($this->payload([$this->ligne($ligne, 20)], ['numero_facture_fournisseur' => 'F-2026-002']));
        $this->actingAs($validateur)->patch(route('achats.factures.valider', $f2))->assertSessionHasNoErrors();

        $this->actingAs($this->user)
            ->post(route('achats.factures.store'), $this->payload([$this->ligne($ligne, 1)], ['numero_facture_fournisseur' => 'F-2026-003']))
            ->assertSessionHasErrors('lignes.0.qte');
    }

    public function test_deux_brouillons_sur_la_meme_quantite_seul_le_premier_valide_passe(): void
    {
        $r = $this->receptionner($this->commande, [$this->ligneA->id => 50]);
        $ligne = $this->ligneRecue($r, $this->ligneA);
        $validateur = $this->makeValidateur();

        $f1 = $this->saisir($this->payload([$this->ligne($ligne, 50)]));
        $f2 = $this->saisir($this->payload([$this->ligne($ligne, 50)], ['numero_facture_fournisseur' => 'F-2026-009']));

        $this->actingAs($validateur)->patch(route('achats.factures.valider', $f1))->assertSessionHasNoErrors();
        $this->actingAs($validateur)->patch(route('achats.factures.valider', $f2))->assertSessionHasErrors('validation');
        $this->assertSame(StatutFactureFournisseur::BROUILLON, $f2->fresh()->statut);
    }

    public function test_numero_de_facture_fournisseur_unique_par_fournisseur(): void
    {
        $r = $this->receptionner($this->commande, [$this->ligneA->id => 50]);
        $ligne = $this->ligneRecue($r, $this->ligneA);
        $this->saisir($this->payload([$this->ligne($ligne, 10)]));

        $this->actingAs($this->user)
            ->post(route('achats.factures.store'), $this->payload([$this->ligne($ligne, 10)]))
            ->assertSessionHasErrors('numero_facture_fournisseur');
    }

    // ── Validation et séparation des tâches ───────────────────────────────────

    public function test_validation_par_un_utilisateur_autorise_constate_la_dette(): void
    {
        $r = $this->receptionner($this->commande, [$this->ligneA->id => 60]);
        $facture = $this->saisir($this->payload([$this->ligne($this->ligneRecue($r, $this->ligneA), 60, 1000)], ['taux_tva' => 18]));
        $validateur = $this->makeValidateur();

        $this->assertSame(0.0, $facture->resteDu());
        $this->actingAs($validateur)->patch(route('achats.factures.valider', $facture))->assertSessionHasNoErrors();

        $facture->refresh();
        $this->assertSame(StatutFactureFournisseur::VALIDEE, $facture->statut);
        $this->assertSame($validateur->id, $facture->validee_par);
        $this->assertSame(60_000.0, (float) $facture->montant_ht);
        $this->assertSame(10_800.0, (float) $facture->montant_tva);
        $this->assertSame(70_800.0, (float) $facture->montant_ttc);
        $this->assertSame(0.0, (float) $facture->montant_paye);
        $this->assertSame(70_800.0, $facture->resteDu());
        $this->assertSame('FOURNISSEUR A', $facture->fournisseur_nom_snapshot);
        $this->assertSame(70_800.0, (float) $this->fournisseur->factures()->get()->sum(fn ($f) => $f->resteDu()));
    }

    public function test_refus_sans_permission_de_validation(): void
    {
        $r = $this->receptionner($this->commande, [$this->ligneA->id => 10]);
        $facture = $this->saisir($this->payload([$this->ligne($this->ligneRecue($r, $this->ligneA), 10)]));
        $lecteur = $this->makeUserWithPermissions($this->org, ['factures-fournisseurs.read']);
        $lecteur->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($lecteur)->patch(route('achats.factures.valider', $facture))->assertForbidden();
    }

    public function test_le_createur_ne_peut_pas_valider_sa_facture(): void
    {
        $this->user->givePermissionTo(Permission::firstOrCreate(['name' => 'factures-fournisseurs.valider', 'guard_name' => 'web']));
        $r = $this->receptionner($this->commande, [$this->ligneA->id => 10]);
        $facture = $this->saisir($this->payload([$this->ligne($this->ligneRecue($r, $this->ligneA), 10)]));

        $this->actingAs($this->user)
            ->patch(route('achats.factures.valider', $facture))
            ->assertSessionHasErrors(['validation' => self::MOTIF_SAISIE]);
        $this->assertSame(StatutFactureFournisseur::BROUILLON, $facture->fresh()->statut);
    }

    public function test_le_dernier_modificateur_ne_peut_pas_valider(): void
    {
        $r = $this->receptionner($this->commande, [$this->ligneA->id => 10]);
        $ligne = $this->ligneRecue($r, $this->ligneA);
        $facture = $this->saisir($this->payload([$this->ligne($ligne, 10)]));
        $validateur = $this->makeValidateur();
        $validateur->givePermissionTo(Permission::firstOrCreate(['name' => 'factures-fournisseurs.update', 'guard_name' => 'web']));

        $this->actingAs($validateur)->put(route('achats.factures.update', $facture), $this->payload([$this->ligne($ligne, 8)]))->assertSessionHasNoErrors();
        $this->actingAs($validateur)
            ->patch(route('achats.factures.valider', $facture))
            ->assertSessionHasErrors(['validation' => self::MOTIF_MODIFICATION]);
    }

    public function test_facture_validee_non_modifiable(): void
    {
        $r = $this->receptionner($this->commande, [$this->ligneA->id => 10]);
        $ligne = $this->ligneRecue($r, $this->ligneA);
        $facture = $this->saisir($this->payload([$this->ligne($ligne, 10)]));
        $this->actingAs($this->makeValidateur())->patch(route('achats.factures.valider', $facture));

        $this->actingAs($this->user)->put(route('achats.factures.update', $facture), $this->payload([$this->ligne($ligne, 5)]))->assertSessionHasErrors('facture');
        $this->assertSame(10, $facture->lignes()->first()->qte_facturee);
    }

    // ── Comptabilité ──────────────────────────────────────────────────────────

    public function test_aucune_dette_ni_ecriture_a_la_reception_ni_au_brouillon(): void
    {
        $this->mapperCompteAchat();
        $r = $this->receptionner($this->commande, [$this->ligneA->id => 10]);
        $facture = $this->saisir($this->payload([$this->ligne($this->ligneRecue($r, $this->ligneA), 10)]));

        $this->assertSame(0, PieceComptable::where('type_evenement', EvenementComptable::FACTURE_FOURNISSEUR_VALIDEE->value)->count());
        $this->assertSame(0.0, $facture->resteDu());
        $this->assertSame(0.0, (float) $this->fournisseur->factures()->get()->sum(fn ($f) => $f->resteDu()));
    }

    public function test_ecriture_generee_a_la_validation_ht_tva_ttc(): void
    {
        $this->mapperCompteAchat();
        $this->mapperTva();
        $r = $this->receptionner($this->commande, [$this->ligneA->id => 60, $this->ligneB->id => 40]);
        $facture = $this->saisir($this->payload([
            $this->ligne($this->ligneRecue($r, $this->ligneA), 60, 1000),
            $this->ligne($this->ligneRecue($r, $this->ligneB), 40, 500),
        ], ['taux_tva' => 18]));

        $this->actingAs($this->makeValidateur())->patch(route('achats.factures.valider', $facture))->assertSessionHasNoErrors();

        $piece = $this->piece($facture);
        $this->assertNotNull($piece);
        $this->assertSame('AC', $piece->journal->code);
        $this->assertSame($this->site->id, EcritureComptable::where('piece_comptable_id', $piece->id)->value('site_id'));

        $parCompte = EcritureComptable::where('piece_comptable_id', $piece->id)->get()
            ->groupBy(fn ($e) => CompteComptable::find($e->compte_comptable_id)->numero)
            ->map(fn ($e) => ['debit' => (float) $e->sum('debit'), 'credit' => (float) $e->sum('credit')]);

        $this->assertSame(['debit' => 80_000.0, 'credit' => 0.0], $parCompte['601000']);
        $this->assertSame(['debit' => 14_400.0, 'credit' => 0.0], $parCompte['445200']);
        $this->assertSame(['debit' => 0.0, 'credit' => 94_400.0], $parCompte['401000']);
        $this->assertNull($facture->fresh()->comptabilisation_erreur);
    }

    public function test_sans_compte_d_achat_parametre_la_dette_existe_et_l_ecriture_reste_en_attente(): void
    {
        $r = $this->receptionner($this->commande, [$this->ligneA->id => 10]);
        $facture = $this->saisir($this->payload([$this->ligne($this->ligneRecue($r, $this->ligneA), 10)]));

        $this->actingAs($this->makeValidateur())->patch(route('achats.factures.valider', $facture))->assertSessionHasNoErrors()->assertSessionHas('warning');

        $facture->refresh();
        $this->assertSame(StatutFactureFournisseur::VALIDEE, $facture->statut);
        $this->assertSame(10_000.0, $facture->resteDu());
        $this->assertNull($this->piece($facture));
        $this->assertNotNull($facture->comptabilisation_erreur);

        // Compte paramétré ensuite : la relance passe la pièce.
        $this->mapperCompteAchat();
        $this->actingAs($this->makeValidateur())->post(route('achats.factures.comptabiliser', $facture))->assertSessionHas('success');
        $this->assertNotNull($this->piece($facture));
        $this->assertNull($facture->fresh()->comptabilisation_erreur);
    }

    public function test_annulation_d_une_facture_validee_contrepasse_et_libere_les_quantites(): void
    {
        $this->mapperCompteAchat();
        $r = $this->receptionner($this->commande, [$this->ligneA->id => 10]);
        $ligne = $this->ligneRecue($r, $this->ligneA);
        $facture = $this->saisir($this->payload([$this->ligne($ligne, 10)]));
        $this->actingAs($this->makeValidateur())->patch(route('achats.factures.valider', $facture));
        $origine = $this->piece($facture);
        $this->assertSame(10_000.0, $facture->fresh()->resteDu());

        $this->actingAs($this->user)
            ->patch(route('achats.factures.annuler', $facture), ['motif_annulation' => 'Facture erronée'])
            ->assertSessionHasNoErrors();

        // Plus de dette, ni sur la facture ni pour le fournisseur.
        $facture->refresh();
        $this->assertSame(StatutFactureFournisseur::ANNULEE, $facture->statut);
        $this->assertSame(0.0, $facture->resteDu());
        $this->assertSame(0.0, (float) $this->fournisseur->factures()->get()->sum(fn ($f) => $f->resteDu()));

        // Écriture inverse qui référence l'originale, elle-même marquée contrepassée.
        $extourne = PieceComptable::where('piece_origine_id', $origine->id)->firstOrFail();
        $this->assertSame('contrepassee', $origine->fresh()->statut->value);
        $this->assertSame($facture->id, $extourne->source_id);
        $debitsOrigine = EcritureComptable::where('piece_comptable_id', $origine->id)->sum('debit');
        $this->assertSame((float) $debitsOrigine, (float) EcritureComptable::where('piece_comptable_id', $extourne->id)->sum('credit'));
        $this->actingAs($this->user)->get(route('achats.factures.show', $facture))
            ->assertInertia(fn ($page) => $page->where('comptabilite.statut', 'contrepassee')->where('comptabilite.extourne_numero', $extourne->numero));

        // Pas de double annulation, ni de seconde contrepassation.
        $this->actingAs($this->user)
            ->patch(route('achats.factures.annuler', $facture), ['motif_annulation' => 'Encore'])
            ->assertSessionHasErrors(['motif_annulation' => 'Cette facture est déjà annulée.']);
        $this->assertSame(1, PieceComptable::where('piece_origine_id', $origine->id)->count());

        // Quantités et numéro libérés : une nouvelle facture reprend les 10 unités, même numéro.
        $nouvelle = $this->saisir($this->payload([$this->ligne($ligne, 10)]));
        $this->actingAs($this->makeValidateur())->patch(route('achats.factures.valider', $nouvelle))->assertSessionHasNoErrors();
        $this->assertSame(10_000.0, $nouvelle->fresh()->resteDu());
        $this->actingAs($this->user)
            ->post(route('achats.factures.store'), $this->payload([$this->ligne($ligne, 1)], ['numero_facture_fournisseur' => 'F-2026-AUTRE']))
            ->assertSessionHasErrors('lignes.0.qte');
    }

    public function test_echec_de_la_contrepassation_l_annulation_est_entierement_refusee(): void
    {
        $this->mapperCompteAchat();
        $r = $this->receptionner($this->commande, [$this->ligneA->id => 10]);
        $facture = $this->saisir($this->payload([$this->ligne($this->ligneRecue($r, $this->ligneA), 10)]));
        $this->actingAs($this->makeValidateur())->patch(route('achats.factures.valider', $facture));
        $origine = $this->piece($facture);

        // Contrepassation en panne (ex. période, base) : l'annulation doit être entièrement annulée.
        $this->app->instance(FactureFournisseurComptabilisationService::class, new class(app(EcritureComptableService::class)) extends FactureFournisseurComptabilisationService
        {
            public function annuler(FactureFournisseur $facture, string $motif, ?string $userId): ?PieceComptable
            {
                throw new \RuntimeException('contrepassation en panne');
            }
        });

        $this->actingAs($this->user)
            ->patch(route('achats.factures.annuler', $facture), ['motif_annulation' => 'Erreur'])
            ->assertSessionHasErrors('motif_annulation');

        $facture->refresh();
        $this->assertSame(StatutFactureFournisseur::VALIDEE, $facture->statut);
        $this->assertSame('F-2026-001', $facture->cle_numero_unique);
        $this->assertNull($facture->annulee_at);
        $this->assertSame(10_000.0, $facture->resteDu());
        $this->assertTrue($origine->fresh()->isValidee());
        $this->assertSame(0, PieceComptable::where('piece_origine_id', $origine->id)->count());
    }

    public function test_annulation_d_une_facture_validee_en_attente_de_comptabilisation(): void
    {
        $r = $this->receptionner($this->commande, [$this->ligneA->id => 10]);
        $facture = $this->saisir($this->payload([$this->ligne($this->ligneRecue($r, $this->ligneA), 10)]));
        $this->actingAs($this->makeValidateur())->patch(route('achats.factures.valider', $facture));
        $this->assertNull($this->piece($facture));

        $this->actingAs($this->user)
            ->patch(route('achats.factures.annuler', $facture), ['motif_annulation' => 'Erreur'])
            ->assertSessionHasNoErrors();

        $this->assertSame(StatutFactureFournisseur::ANNULEE, $facture->fresh()->statut);
        $this->assertSame(0, PieceComptable::where('source_id', $facture->id)->count());
    }

    public function test_une_relance_tardive_ne_comptabilise_jamais_une_facture_annulee(): void
    {
        $r = $this->receptionner($this->commande, [$this->ligneA->id => 10]);
        $facture = $this->saisir($this->payload([$this->ligne($this->ligneRecue($r, $this->ligneA), 10)]));
        $this->actingAs($this->makeValidateur())->patch(route('achats.factures.valider', $facture));
        $luAvantAnnulation = $facture->fresh();

        $this->actingAs($this->user)->patch(route('achats.factures.annuler', $facture), ['motif_annulation' => 'Erreur']);
        $this->mapperCompteAchat();

        // Relance partie sur une lecture « validée » antérieure à l'annulation : aucune écriture.
        $this->assertFalse(app(FactureFournisseurService::class)->comptabiliser($luAvantAnnulation));
        $this->artisan('comptabilite:rattraper', ['--organization' => [$this->org->id], '--type' => ['facture-fournisseur']])->assertSuccessful();
        $this->assertSame(0, PieceComptable::where('source_id', $facture->id)->count());
    }

    public function test_unicite_du_numero_garantie_en_base(): void
    {
        $r = $this->receptionner($this->commande, [$this->ligneA->id => 50]);
        $ligne = $this->ligneRecue($r, $this->ligneA);
        $facture = $this->saisir($this->payload([$this->ligne($ligne, 10)]));
        $this->assertSame('F-2026-001', $facture->cle_numero_unique);

        // Insertion directe du même numéro (contournant le service) : refusée par la base.
        $this->expectException(UniqueConstraintViolationException::class);
        FactureFournisseur::create([
            'organization_id' => $this->org->id, 'commande_achat_id' => $this->commande->id, 'fournisseur_id' => $this->fournisseur->id,
            'site_id' => $this->site->id, 'reference' => 'FAF-DOUBLON', 'numero_facture_fournisseur' => 'F-2026-001',
            'cle_numero_unique' => 'F-2026-001', 'date_facture' => '2026-10-02',
        ]);
    }

    public function test_doublon_detecte_par_la_base_rendu_en_erreur_de_saisie(): void
    {
        // Situation d'une saisie concurrente : le contrôle applicatif ne voit pas le doublon (ici une
        // ligne annulée dont la clé n'a pas été libérée), seule la contrainte en base le voit.
        $r = $this->receptionner($this->commande, [$this->ligneA->id => 50]);
        FactureFournisseur::create([
            'organization_id' => $this->org->id, 'commande_achat_id' => $this->commande->id, 'fournisseur_id' => $this->fournisseur->id,
            'site_id' => $this->site->id, 'reference' => 'FAF-CONCURRENTE', 'numero_facture_fournisseur' => 'F-2026-001',
            'cle_numero_unique' => 'F-2026-001', 'date_facture' => '2026-10-02', 'statut' => StatutFactureFournisseur::ANNULEE,
        ]);

        $this->actingAs($this->user)
            ->post(route('achats.factures.store'), $this->payload([$this->ligne($this->ligneRecue($r, $this->ligneA), 10)]))
            ->assertSessionHasErrors(['numero_facture_fournisseur' => 'Une facture de ce fournisseur porte déjà ce numéro.']);
        $this->assertSame(1, FactureFournisseur::count());
    }

    public function test_ecriture_en_attente_puis_rattrapage_idempotent(): void
    {
        $r = $this->receptionner($this->commande, [$this->ligneA->id => 10]);
        $facture = $this->saisir($this->payload([$this->ligne($this->ligneRecue($r, $this->ligneA), 10)]));
        $this->actingAs($this->user)->get(route('achats.factures.show', $facture))
            ->assertInertia(fn ($page) => $page->where('comptabilite.statut', 'sans_objet'));

        $this->actingAs($this->makeValidateur())->patch(route('achats.factures.valider', $facture));
        $this->actingAs($this->user)->get(route('achats.factures.show', $facture))
            ->assertInertia(fn ($page) => $page->where('facture.statut', 'validee')
                ->where('comptabilite.statut', 'en_attente')
                ->where('comptabilite.piece_numero', null));
        $this->actingAs($this->user)->get(route('achats.factures.index'))
            ->assertInertia(fn ($page) => $page->where('factures.data.0.comptabilite.statut', 'en_attente'));

        $this->mapperCompteAchat();
        foreach ([1, 2] as $passage) {
            $this->artisan('comptabilite:rattraper', ['--organization' => [$this->org->id], '--type' => ['facture-fournisseur']])->assertSuccessful();
        }
        app(FactureFournisseurService::class)->comptabiliser($facture->fresh());

        $this->assertSame(1, PieceComptable::where('source_id', $facture->id)->count());
        $this->actingAs($this->user)->get(route('achats.factures.show', $facture))
            ->assertInertia(fn ($page) => $page->where('comptabilite.statut', 'comptabilisee')->where('comptabilite.erreur', null));
    }

    // ── Comptes d'achat et de TVA provisoires du plan par défaut ──────────────

    /** @return array<string, array{compte: string, journal: ?string}> correspondances achat/TVA d'une organisation, par rôle */
    private function correspondancesAchat(string $organizationId): array
    {
        return CompteMapping::where('organization_id', $organizationId)
            ->where('evenement', EvenementComptable::FACTURE_FOURNISSEUR_VALIDEE->value)
            ->whereIn('role', ['achat', 'tva_deductible'])
            ->get()
            ->mapWithKeys(fn (CompteMapping $m) => [$m->role => [
                'compte' => CompteComptable::find($m->compte_comptable_id)->numero,
                'journal' => $m->journal_comptable_id ? JournalComptable::find($m->journal_comptable_id)->code : null,
            ]])
            ->all();
    }

    public function test_une_nouvelle_organisation_recoit_les_comptes_d_achat_et_de_tva_provisoires(): void
    {
        $nouvelle = Organization::factory()->create();

        $this->assertSame(
            ['achat' => ['compte' => '601000', 'journal' => 'AC'], 'tva_deductible' => ['compte' => '445200', 'journal' => null]],
            $this->correspondancesAchat($nouvelle->id),
        );
        $this->assertSame('Achats de marchandises', CompteComptable::where('organization_id', $nouvelle->id)->where('numero', '601000')->value('libelle'));
        // Aucun compte par type de produit n'est créé.
        $this->assertFalse(CompteMapping::where('organization_id', $nouvelle->id)->where('role', 'like', 'achat\_%')->exists());
        // Isolation : rien n'est créé pour l'organisation de référence, dont les comptes ont été retirés.
        $this->assertSame([], $this->correspondancesAchat($this->org->id));
    }

    public function test_la_migration_complete_une_organisation_existante_sans_doublon_ni_ecrasement(): void
    {
        // Correspondance d'achat personnalisée par le comptable ; TVA absente.
        $personnalise = CompteComptable::create(['organization_id' => $this->org->id, 'numero' => '602000', 'libelle' => 'Achats de matières (choix du comptable)', 'actif' => true]);
        CompteMapping::create([
            'organization_id' => $this->org->id, 'evenement' => EvenementComptable::FACTURE_FOURNISSEUR_VALIDEE->value,
            'role' => 'achat', 'compte_comptable_id' => $personnalise->id, 'journal_comptable_id' => null, 'actif' => true,
        ]);

        $migration = require database_path('migrations/2026_10_10_400000_bootstrap_comptes_achat_et_tva_provisoires.php');
        $migration->up();
        $comptesApres = CompteComptable::where('organization_id', $this->org->id)->count();
        $mappingsApres = CompteMapping::where('organization_id', $this->org->id)->count();
        $migration->up();

        // Personnalisation conservée, TVA manquante créée, deuxième passage sans effet.
        $this->assertSame(
            ['achat' => ['compte' => '602000', 'journal' => null], 'tva_deductible' => ['compte' => '445200', 'journal' => null]],
            $this->correspondancesAchat($this->org->id),
        );
        $this->assertSame($comptesApres, CompteComptable::where('organization_id', $this->org->id)->count());
        $this->assertSame($mappingsApres, CompteMapping::where('organization_id', $this->org->id)->count());
        $this->assertSame(1, CompteComptable::where('organization_id', $this->org->id)->where('numero', '601000')->count());
    }

    public function test_avec_les_comptes_par_defaut_la_validation_passe_l_ecriture_ht_tva_ttc(): void
    {
        app(PlanComptableBootstrapService::class)->bootstrap($this->org->id);
        $r = $this->receptionner($this->commande, [$this->ligneA->id => 10]);
        $facture = $this->saisir($this->payload([$this->ligne($this->ligneRecue($r, $this->ligneA), 10)], ['taux_tva' => 18]));

        $this->actingAs($this->makeValidateur())->patch(route('achats.factures.valider', $facture))->assertSessionHasNoErrors();

        $piece = $this->piece($facture);
        $this->assertNotNull($piece);
        $parCompte = EcritureComptable::where('piece_comptable_id', $piece->id)->get()
            ->mapWithKeys(fn ($e) => [CompteComptable::find($e->compte_comptable_id)->numero => (float) $e->debit - (float) $e->credit]);
        $this->assertSame(['601000' => 10_000.0, '445200' => 1_800.0, '401000' => -11_800.0], $parCompte->all());
    }

    public function test_la_migration_ne_rattrape_rien_le_paiement_reste_bloque_jusqu_a_la_relance(): void
    {
        $this->user->givePermissionTo(Permission::firstOrCreate(['name' => 'factures-fournisseurs.payer', 'guard_name' => 'web']));
        $paiements = app(PaiementFournisseurService::class);
        $r = $this->receptionner($this->commande, [$this->ligneA->id => 10]);
        $facture = $this->saisir($this->payload([$this->ligne($this->ligneRecue($r, $this->ligneA), 10)]));
        $this->actingAs($this->makeValidateur())->patch(route('achats.factures.valider', $facture))->assertSessionHas('warning');

        // La migration crée les comptes mais ne passe aucune écriture : facture toujours impayable.
        (require database_path('migrations/2026_10_10_400000_bootstrap_comptes_achat_et_tva_provisoires.php'))->up();
        $this->assertNull($this->piece($facture));
        $this->assertStringStartsWith('Paiement impossible', (string) $paiements->motifNonPayable($facture->fresh(), $this->user));

        // Relance volontaire depuis la fiche : écriture passée, facture payable.
        $this->actingAs($this->makeValidateur())->post(route('achats.factures.comptabiliser', $facture))->assertSessionHas('success');
        $this->assertNotNull($this->piece($facture));
        $this->assertNull($paiements->motifNonPayable($facture->fresh(), $this->user));
    }

    // ── Achats sans facture ou sans numéro (décision du 10/10/2026) ───────────

    public function test_facture_sans_numero_acceptee_et_plusieurs_peuvent_coexister(): void
    {
        $r = $this->receptionner($this->commande, [$this->ligneA->id => 20]);
        $ligne = $this->ligneRecue($r, $this->ligneA);

        // Reçu sans numéro : aucun numéro n'est inventé, aucune clé de doublon.
        $recu = $this->saisir($this->payload([$this->ligne($ligne, 5)], ['numero_facture_fournisseur' => null, 'type_justificatif' => 'recu']));
        $this->assertNull($recu->numero_facture_fournisseur);
        $this->assertNull($recu->cle_numero_unique);
        $this->assertSame('recu', $recu->type_justificatif->value);
        $this->assertSame('Reçu sans numéro', $recu->designationDocument());

        // Un second document sans numéro du même fournisseur n'est pas un doublon.
        $ticket = $this->saisir($this->payload([$this->ligne($ligne, 5)], ['numero_facture_fournisseur' => '   ', 'type_justificatif' => 'ticket']));
        $this->assertNull($ticket->numero_facture_fournisseur);
        $this->assertSame(2, FactureFournisseur::count());

        // Le contrôle de doublon reste actif dès qu'un numéro est saisi.
        $this->saisir($this->payload([$this->ligne($ligne, 5)], ['numero_facture_fournisseur' => 'F-77']));
        $this->actingAs($this->user)
            ->post(route('achats.factures.store'), $this->payload([$this->ligne($ligne, 5)], ['numero_facture_fournisseur' => 'F-77']))
            ->assertSessionHasErrors(['numero_facture_fournisseur' => 'Une facture de ce fournisseur porte déjà ce numéro.']);

        // Sans type précisé : « facture », comme avant.
        $this->assertSame('facture', FactureFournisseur::where('numero_facture_fournisseur', 'F-77')->firstOrFail()->type_justificatif->value);
    }

    public function test_achat_sans_justificatif_enregistre_valide_et_trace_dans_l_ecriture(): void
    {
        $this->mapperCompteAchat();
        $r = $this->receptionner($this->commande, [$this->ligneA->id => 10]);
        $ligne = $this->ligneRecue($r, $this->ligneA);

        // Sans document, un numéro n'a pas de sens.
        $this->actingAs($this->user)
            ->post(route('achats.factures.store'), $this->payload([$this->ligne($ligne, 10)], ['type_justificatif' => 'aucun', 'numero_facture_fournisseur' => 'F-1']))
            ->assertSessionHasErrors('numero_facture_fournisseur');
        $this->actingAs($this->user)
            ->post(route('achats.factures.store'), $this->payload([$this->ligne($ligne, 10)], ['type_justificatif' => 'inconnu']))
            ->assertSessionHasErrors('type_justificatif');

        $facture = $this->saisir($this->payload([$this->ligne($ligne, 10)], ['type_justificatif' => 'aucun', 'numero_facture_fournisseur' => null]));
        $this->assertTrue($facture->estSansJustificatif());
        $this->actingAs($this->user)->get(route('achats.factures.show', $facture))
            ->assertInertia(fn ($page) => $page->where('facture.sans_justificatif', true)->where('facture.numero_facture_fournisseur', null));
        $this->actingAs($this->user)->get(route('achats.factures.index'))
            ->assertInertia(fn ($page) => $page->where('factures.data.0.sans_justificatif', true));

        // Validée comme les autres : la dette naît et l'écriture mentionne l'absence de justificatif.
        $this->actingAs($this->makeValidateur())->patch(route('achats.factures.valider', $facture))->assertSessionHasNoErrors();
        $piece = $this->piece($facture);
        $this->assertNotNull($piece);
        $this->assertSame('Achat sans justificatif — '.$facture->reference, $piece->libelle);
        $this->assertSame(10_000.0, $facture->fresh()->resteDu());

        // Le PDF le dit aussi.
        $html = view('pdf.facture_achat', ['facture' => $facture->fresh(['commande', 'site', 'fournisseur', 'createdBy', 'valideePar', 'lignes.receptionLigne.reception']), 'organisation' => $this->org])->render();
        $this->assertStringContainsString('achat enregistré sans justificatif du fournisseur', $html);
        $this->assertStringContainsString('Aucun document', $html);
    }

    public function test_la_saisie_propose_la_date_d_achat_du_bon(): void
    {
        $this->commande->update(['date_achat' => '2026-09-28']);
        $this->receptionner($this->commande, [$this->ligneA->id => 10], '2026-09-29');

        $this->actingAs($this->user)->get(route('achats.factures.create', ['commande' => $this->commande->id]))
            ->assertInertia(fn ($page) => $page->where('commande.date_achat', '2026-09-28'));
    }

    // ── Achat centralisé : la facture relève de l'agence payeuse ──────────────

    public function test_la_facture_d_un_achat_centralise_appartient_a_l_agence_payeuse(): void
    {
        // Bon livré à Cba, payé par l'agence principale ($this->site).
        $cba = Site::factory()->for($this->org)->create(['nom' => 'Cba']);
        $this->commande->update(['site_id' => $cba->id, 'site_payeur_id' => $this->site->id]);
        $r = $this->receptionner($this->commande->fresh(), [$this->ligneA->id => 10]);
        $payload = $this->payload([$this->ligne($this->ligneRecue($r, $this->ligneA), 10)]);

        // Un utilisateur dont le périmètre ne couvre que l'agence de livraison ne peut pas la saisir.
        foreach (self::PERMISSIONS_SAISIE as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $role = Role::firstOrCreate(['name' => 'agent_cba', 'guard_name' => 'web']);
        $role->givePermissionTo(self::PERMISSIONS_SAISIE);
        $this->regle('agent_cba', 'agences_selectionnees', [$cba->id]);
        $agentCba = User::factory()->create(['organization_id' => $this->org->id]);
        $agentCba->assignRole($role);
        $agentCba->sites()->attach($cba->id, ['role' => 'employe', 'is_default' => true]);
        $this->actingAs($agentCba)->post(route('achats.factures.store'), $payload)->assertSessionHasErrors('commande');
        $this->assertSame(0, FactureFournisseur::count());

        // L'acheteur central (toutes agences) la saisit : elle est rattachée à l'agence payeuse.
        $facture = $this->saisir($payload);
        $this->assertSame($this->site->id, $facture->site_id);

        // Elle se valide et se paie donc dans le périmètre de l'agence payeuse, pas de Cba.
        $this->assertStringContainsString("n'est pas dans votre périmètre", (string) app(FactureFournisseurService::class)->motifNonValidable(
            $facture,
            tap($agentCba, fn (User $u) => $role->givePermissionTo(Permission::firstOrCreate(['name' => 'factures-fournisseurs.valider', 'guard_name' => 'web'])))->fresh(),
        ));
    }

    // ── Isolation et périmètre ────────────────────────────────────────────────

    public function test_isolation_organisationnelle(): void
    {
        $autreOrg = Organization::factory()->create();
        $autreSite = Site::factory()->for($autreOrg)->create();
        $autreFournisseur = $this->makeFournisseur($autreOrg, 'ETRANGER');
        [$commandeEtrangere, $ligne] = $this->commandeValidee($autreOrg, $autreSite, $autreFournisseur);
        $r = $this->receptionner($commandeEtrangere, [$ligne->id => 10]);
        $factureEtrangere = FactureFournisseur::create([
            'organization_id' => $autreOrg->id, 'commande_achat_id' => $commandeEtrangere->id, 'fournisseur_id' => $autreFournisseur->id,
            'site_id' => $autreSite->id, 'reference' => 'FAF-ETR', 'numero_facture_fournisseur' => 'X', 'date_facture' => '2026-10-01',
        ]);

        $this->actingAs($this->user)
            ->post(route('achats.factures.store'), $this->payload([$this->ligne($this->ligneRecue($r, $ligne), 10)], ['commande_achat_id' => $commandeEtrangere->id, 'fournisseur_id' => $autreFournisseur->id]))
            ->assertNotFound();
        $this->actingAs($this->user)->get(route('achats.factures.show', $factureEtrangere))->assertForbidden();

        // Ligne reçue d'un autre bon (même d'une autre organisation) : refusée.
        $this->actingAs($this->user)
            ->post(route('achats.factures.store'), $this->payload([$this->ligne($this->ligneRecue($r, $ligne), 10)]))
            ->assertSessionHasErrors('lignes.0.reception_ligne_id');
    }

    public function test_hors_perimetre_du_bon_ni_saisie_ni_lecture_ni_validation(): void
    {
        $r = $this->receptionner($this->commande, [$this->ligneA->id => 10]);
        $facture = $this->saisir($this->payload([$this->ligne($this->ligneRecue($r, $this->ligneA), 10)]));
        $autreSite = Site::factory()->for($this->org)->create();
        RegleValidationRole::where('role_name', 'comptable_test')->delete();
        $validateur = $this->makeValidateur();
        RegleValidationRole::where('role_name', 'comptable_test')->update(['perimetre' => 'agences_selectionnees', 'sites' => json_encode([$autreSite->id])]);

        $this->actingAs($validateur)->get(route('achats.factures.show', $facture))->assertForbidden();
        $this->actingAs($validateur)->patch(route('achats.factures.valider', $facture))->assertForbidden();
        $this->actingAs($validateur)->get(route('achats.factures.index'))
            ->assertInertia(fn ($page) => $page->where('factures.total', 0));
    }

    public function test_le_super_administrateur_suit_le_perimetre_et_la_separation_des_taches(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $superAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $superAdmin->assignRole('super_admin');
        $superAdmin->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);
        $r = $this->receptionner($this->commande, [$this->ligneA->id => 20]);
        $ligne = $this->ligneRecue($r, $this->ligneA);
        $facture = $this->saisir($this->payload([$this->ligne($ligne, 10)]));

        // Sans règle « Peut acheter pour » : aucune action, malgré le Gate::before. Seule la
        // CONSULTATION lui reste ouverte (ADR 0025) : il détient toutes les permissions, dont
        // « consulter les données de toutes les agences » — la fiche s'ouvre, sans action proposée.
        $this->actingAs($superAdmin)->get(route('achats.factures.show', $facture))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('actions.peut_valider', false)
                ->where('actions.peut_modifier', false)
                ->where('actions.peut_annuler', false)
                ->where('actions.peut_payer', false));
        $this->actingAs($superAdmin)->patch(route('achats.factures.valider', $facture))->assertForbidden();

        // Avec sa règle par défaut : valide la facture d'un autre, et la sienne (décision du 10/10/2026).
        RegleValidationRole::provisionnerAchatsParDefaut($this->org->id);
        $this->actingAs($superAdmin)->patch(route('achats.factures.valider', $facture))->assertSessionHasNoErrors();
        $this->assertSame(StatutFactureFournisseur::VALIDEE, $facture->fresh()->statut);

        $saFacture = $this->saisir($this->payload([$this->ligne($ligne, 5)], ['numero_facture_fournisseur' => 'F-SA']), $superAdmin);
        $this->actingAs($superAdmin)->get(route('achats.factures.show', $saFacture))
            ->assertInertia(fn ($page) => $page->where('actions.peut_valider', true)->where('actions.motif_non_validable', null));
        $this->actingAs($superAdmin)->patch(route('achats.factures.valider', $saFacture))->assertSessionHasNoErrors();
        $this->assertSame(StatutFactureFournisseur::VALIDEE, $saFacture->fresh()->statut);

        // Réglage retiré dans Paramètres → Achats : la séparation s'applique à lui aussi.
        RegleValidationRole::where('role_name', 'super_admin')->update(['peut_valider_ses_propres_factures' => false]);
        $sonAutreFacture = $this->saisir($this->payload([$this->ligne($ligne, 5)], ['numero_facture_fournisseur' => 'F-SA-2']), $superAdmin);
        $this->actingAs($superAdmin)
            ->patch(route('achats.factures.valider', $sonAutreFacture))
            ->assertSessionHasErrors(['validation' => self::MOTIF_SAISIE]);
    }

    public function test_un_role_autorise_a_valider_ses_propres_factures_le_fait_les_autres_restent_bloques(): void
    {
        $this->user->givePermissionTo(Permission::firstOrCreate(['name' => 'factures-fournisseurs.valider', 'guard_name' => 'web']));
        $r = $this->receptionner($this->commande, [$this->ligneA->id => 10]);
        $ligne = $this->ligneRecue($r, $this->ligneA);

        // Réglage désactivé (défaut des rôles autres que super_admin) : séparation des tâches.
        $facture = $this->saisir($this->payload([$this->ligne($ligne, 5)]));
        $this->actingAs($this->user)->patch(route('achats.factures.valider', $facture))->assertSessionHasErrors(['validation' => self::MOTIF_SAISIE]);

        // Réglage activé sur le rôle : il valide sa propre facture.
        RegleValidationRole::where('role_name', 'admin_entreprise')->update(['peut_valider_ses_propres_factures' => true]);
        $this->actingAs($this->user)->patch(route('achats.factures.valider', $facture))->assertSessionHasNoErrors();
        $this->assertSame(StatutFactureFournisseur::VALIDEE, $facture->fresh()->statut);

        // Ni une facture déjà validée, ni une facture annulée ne se valide.
        $this->actingAs($this->user)->patch(route('achats.factures.valider', $facture))->assertSessionHasErrors('validation');
        $annulee = $this->saisir($this->payload([$this->ligne($ligne, 5)], ['numero_facture_fournisseur' => 'F-ANN']));
        $this->actingAs($this->user)->patch(route('achats.factures.annuler', $annulee), ['motif_annulation' => 'Erreur'])->assertSessionHasNoErrors();
        $this->actingAs($this->user)->patch(route('achats.factures.valider', $annulee))->assertSessionHasErrors('validation');
    }

    public function test_pdf_recapitulatif_de_la_facture(): void
    {
        $r = $this->receptionner($this->commande, [$this->ligneA->id => 10]);
        $facture = $this->saisir($this->payload([$this->ligne($this->ligneRecue($r, $this->ligneA), 10)], ['numero_facture_fournisseur' => 'FR-123']));

        $reponse = $this->actingAs($this->user)->get(route('achats.factures.pdf', $facture));
        $reponse->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString($facture->reference.'.pdf', (string) $reponse->headers->get('content-disposition'));

        $html = view('pdf.facture_achat', ['facture' => $facture->fresh(['commande', 'site', 'fournisseur', 'createdBy', 'valideePar', 'lignes.receptionLigne.reception']), 'organisation' => $this->org])->render();
        foreach (['FACTURE D’ACHAT', $facture->reference, 'FR-123', $this->commande->reference ?? '—', $r->reference, 'Préformes 500 ml', 'BROUILLON', 'n’est pas l’original du fournisseur'] as $attendu) {
            $this->assertStringContainsString($attendu, $html);
        }

        // Hors périmètre : refus, comme la fiche.
        $autreSite = Site::factory()->for($this->org)->create();
        RegleValidationRole::where('role_name', 'admin_entreprise')->update(['perimetre' => 'agences_selectionnees', 'sites' => json_encode([$autreSite->id])]);
        $intrus = User::factory()->create(['organization_id' => $this->org->id]);
        $intrus->assignRole('admin_entreprise');
        $intrus->givePermissionTo(self::PERMISSIONS_SAISIE);
        $this->actingAs($intrus)->get(route('achats.factures.pdf', $facture))->assertForbidden();
    }

    public function test_migration_autorise_l_auto_validation_des_factures_pour_les_regles_super_admin_seulement(): void
    {
        $this->regle('super_admin');
        (require database_path('migrations/2026_10_10_100000_add_peut_valider_ses_propres_factures_to_regles_validation_roles_table.php'))->up();

        $this->assertTrue(RegleValidationRole::where('role_name', 'super_admin')->firstOrFail()->peut_valider_ses_propres_factures);
        $this->assertFalse(RegleValidationRole::where('role_name', 'admin_entreprise')->firstOrFail()->peut_valider_ses_propres_factures);
    }

    public function test_pages_liste_saisie_et_fiche(): void
    {
        $r = $this->receptionner($this->commande, [$this->ligneA->id => 10]);
        $facture = $this->saisir($this->payload([$this->ligne($this->ligneRecue($r, $this->ligneA), 10)]));

        $this->actingAs($this->user)->get(route('achats.factures.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('factures.total', 1));
        $this->actingAs($this->user)->get(route('achats.factures.create', ['commande' => $this->commande->id]))->assertOk()
            ->assertInertia(fn ($page) => $page->where('receptions.0.lignes.0.facturable', 10));
        $this->actingAs($this->user)->get(route('achats.factures.show', $facture))->assertOk()
            ->assertInertia(fn ($page) => $page->where('facture.lignes.0.qte', 10)->where('actions.peut_valider', false));
        $this->actingAs($this->user)->get(route('achats.show', $this->commande))
            ->assertInertia(fn ($page) => $page->where('commande.factures.0.id', $facture->id));
    }
}
