<?php

namespace Tests\Feature;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionMode;
use App\Enums\CommissionScopeType;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\CommissionUniteCalcul;
use App\Enums\DeclencheurCommissionVente;
use App\Enums\EvenementComptable;
use App\Enums\ModeConfirmationAnnulationExceptionnelle;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutCommission;
use App\Enums\StatutFactureVente;
use App\Enums\StatutFichePaiement;
use App\Enums\TypePeriodePaiement;
use App\Mail\RegularisationVenteCodeMail;
use App\Models\Categorie;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\CommandeVenteRetour;
use App\Models\CommissionCibleType;
use App\Models\CommissionEcartRegularisation;
use App\Models\CommissionEnveloppe;
use App\Models\CommissionEnveloppePaiementItem;
use App\Models\CommissionEnveloppePart;
use App\Models\CommissionProcessus;
use App\Models\CommissionRegle;
use App\Models\CompteTresorerie;
use App\Models\EncaissementVente;
use App\Models\EquipeLivraison;
use App\Models\EquipeLivraisonPartageCategorie;
use App\Models\EquipeLivreur;
use App\Models\Livreur;
use App\Models\Organization;
use App\Models\PaiementFiche;
use App\Models\PaiementFicheLigne;
use App\Models\PaiementFichePaiement;
use App\Models\PaiementPeriode;
use App\Models\Parametre;
use App\Models\PieceComptable;
use App\Models\Proprietaire;
use App\Models\RegularisationVente;
use App\Models\Site;
use App\Models\User;
use App\Models\UserAuthIdentity;
use App\Models\VarianteStock;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use App\Services\Commission\CommissionProcessusDefaults;
use App\Services\Comptabilite\EcritureComptableService;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use App\Services\Ventes\Regularisation\RegularisationCommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Régularisation d'une vente après encaissement (ADR 0043, docs/regularisation-vente.md) : baisse de
 * quantité saisie en trop (incident VTE-301026-029 : 2 700 packs au lieu de 270), facture, stock,
 * comptabilité, excédent encaissé (jamais reçu / à rembourser), commission recalculée immédiatement
 * avec écart tracé quand elle est déjà payée, confirmation et autorisations.
 *
 * `.env.testing` fixe OTP_FIXED_CODE=123456.
 */
class RegularisationVenteTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private const PRIX_VENTE = 2000;

    private const COMMISSION_UNITAIRE = 50;

    private const CODE = '123456';

    private const MOTIF = 'Saisie de 10 packs au lieu de 4 lors de la création';

    private Site $site;

    private Categorie $categorie;

    private CompteTresorerie $caisse;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->initOrgAndUser([
            'ventes.read', 'ventes.create', 'ventes.update',
            'ventes.demarrer_chargement', 'ventes.valider_chargement', 'ventes.enregistrer_retour',
            'factures.encaisser', 'ventes.rembourser', 'ventes.regulariser_apres_encaissement',
            'comptabilite.payer',
        ]);
        $this->user->authIdentities()->create([
            'type' => UserAuthIdentity::TYPE_EMAIL,
            'value' => 'super.admin@example.com',
            'normalized_value' => UserAuthIdentity::normaliser(UserAuthIdentity::TYPE_EMAIL, 'super.admin@example.com'),
            'verified_at' => now(),
        ]);
        $this->user->unsetRelation('authIdentities');

        Parametre::setDeclencheurCommissionVente($this->org->id, DeclencheurCommissionVente::CHARGEMENT_VALIDE);

        $this->site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Site Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => false]);
        $this->caisse = $this->creerCaisseActive($this->site->id, $this->user->id);

        $this->categorie = Categorie::create(['organization_id' => $this->org->id, 'nom' => 'Sachet eau', 'statut' => 'actif']);

        $processus = CommissionProcessus::create([
            'organization_id' => $this->org->id,
            'code' => CommissionProcessus::CODE_VENTE,
            'libelle' => 'Vente',
            'declencheur' => 'chargement_valide',
            'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
            'statut' => CommissionActivationStatut::ACTIF->value,
        ]);
        CommissionRegle::create([
            'organization_id' => $this->org->id,
            'processus_id' => $processus->id,
            'libelle' => 'Propriétaire — Global',
            'scope_type' => CommissionScopeType::GLOBAL->value,
            'cible_type' => CommissionCibleType::CODE_PROPRIETAIRE,
            'mode' => CommissionMode::DIRECT->value,
            'unite_calcul' => CommissionUniteCalcul::PAR_UNITE_VENDUE->value,
            'montant' => self::COMMISSION_UNITAIRE,
            'effective_from' => now()->subDays(3)->toDateString(),
            'statut' => 'active',
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeVehicule(): Vehicule
    {
        $proprietaire = Proprietaire::factory()->create(['organization_id' => $this->org->id]);
        $vehicule = Vehicule::factory()->create([
            'organization_id' => $this->org->id,
            'proprietaire_id' => $proprietaire->id,
        ]);

        $chauffeur = Livreur::factory()->create(['organization_id' => $this->org->id]);
        $equipe = EquipeLivraison::create([
            'organization_id' => $this->org->id,
            'vehicule_id' => $vehicule->id,
            'nom' => 'Équipe Test',
            'is_active' => true,
        ]);
        EquipeLivreur::create(['equipe_id' => $equipe->id, 'livreur_id' => $chauffeur->id, 'role' => 'chauffeur', 'ordre' => 0]);
        EquipeLivraisonPartageCategorie::create([
            'equipe_id' => $equipe->id, 'categorie_id' => $this->categorie->id,
            'processus_id' => CommissionProcessusDefaults::resoudreOuCreer($this->org->id, CommissionProcessus::CODE_VENTE)->id,
            'livreur_id' => $chauffeur->id, 'part_pourcentage' => 0,
            'montant_unitaire' => 0, 'effective_from' => now()->subDays(3),
        ]);

        return $vehicule->fresh();
    }

    /**
     * Vente de 10 packs chargée (stock 100 → 90) puis encaissée en espèces dans la caisse dédiée.
     *
     * @return array{commande: CommandeVente, ligne: CommandeVenteLigne}
     */
    private function venteEncaissee(bool $encaisser = true): array
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'vehicule_id' => $this->makeVehicule()->id,
            'statut' => StatutCommandeVente::BROUILLON,
            'total_commande' => 10 * self::PRIX_VENTE,
        ]);

        $produit = $this->makeProduitAvecVariante(
            $this->org,
            ['nom' => 'Pack de 25 sachets', 'categorie_id' => $this->categorie->id],
            ['prix_vente' => self::PRIX_VENTE, 'prix_usine' => 1500],
        );
        $this->seedVarianteStockSuffisant($produit->variantePrincipale()->first(), $this->site, 100);

        $ligne = $commande->lignes()->create([
            'variante_id' => $produit->variantePrincipale()->first()->id,
            'quantite_demandee' => 10,
            'prix_usine_snapshot' => 1500.0,
            'prix_vente_snapshot' => (float) self::PRIX_VENTE,
            'total_ligne' => 10 * self::PRIX_VENTE,
        ]);

        $this->actingAs($this->user);
        CommandeVenteService::confirmer($commande);
        CommandeVenteService::demarrerChargement($commande);
        CommandeVenteService::validerChargement($commande, [[
            'id' => $ligne->id,
            'quantite_chargee' => 10,
            'type_ecart' => 'conforme',
        ]]);

        if ($encaisser) {
            $this->actingAs($this->user)
                ->post(route('encaissements.store', $commande->fresh('facture')->facture), [
                    'montant' => 10 * self::PRIX_VENTE,
                    'mode_paiement' => 'especes',
                ])
                ->assertSessionHasNoErrors();
        }

        return ['commande' => $commande->fresh(), 'ligne' => $ligne->fresh()];
    }

    /** @return array<string, mixed> */
    private function charge(CommandeVenteLigne $ligne, int $quantite, ?string $traitement): array
    {
        return array_filter([
            'lignes' => [['id' => $ligne->id, 'quantite' => $quantite]],
            'traitement_excedent' => $traitement,
        ], fn ($v) => $v !== null);
    }

    /** @return array<string, mixed> */
    private function recapitulatif(CommandeVente $commande, CommandeVenteLigne $ligne, int $quantite = 4, ?string $traitement = RegularisationVente::EXCEDENT_NON_RECU, ?User $user = null): array
    {
        return $this->actingAs($user ?? $this->user)
            ->postJson(route('ventes.regularisation.recapitulatif', $commande), $this->charge($ligne, $quantite, $traitement))
            ->assertOk()
            ->json();
    }

    private function regulariser(CommandeVente $commande, CommandeVenteLigne $ligne, int $quantite = 4, ?string $traitement = RegularisationVente::EXCEDENT_NON_RECU): void
    {
        $recap = $this->recapitulatif($commande, $ligne, $quantite, $traitement);
        $this->assertSame([], $recap['blocages']);

        $this->actingAs($this->user)
            ->postJson(route('ventes.regularisation.code', $commande), $this->charge($ligne, $quantite, $traitement) + [
                'motif' => self::MOTIF,
                'empreinte' => $recap['empreinte'],
            ])
            ->assertOk();

        $this->actingAs($this->user)
            ->post(route('ventes.regularisation.confirmer', $commande), $this->charge($ligne, $quantite, $traitement) + [
                'motif' => self::MOTIF,
                'empreinte' => $recap['empreinte'],
                'code' => self::CODE,
            ])
            ->assertRedirect(route('ventes.show', $commande))
            ->assertSessionHasNoErrors();
    }

    private function stock(CommandeVenteLigne $ligne): int
    {
        return (int) VarianteStock::where('produit_variante_id', $ligne->variante_id)
            ->where('site_id', $this->site->id)
            ->value('qte_stock');
    }

    /** Versement simulé : l'argent sort de la caisse dédiée de l'agent. */
    private function sortirDeLaCaisse(CompteTresorerie $caisse, float $montant): void
    {
        app(EcritureComptableService::class)->comptabiliser(
            evenement: EvenementComptable::SOLDE_OUVERTURE_TRESORERIE,
            source: $caisse,
            organizationId: $this->org->id,
            dateComptable: Carbon::now(),
            libelle: 'Versement test',
            lignes: [
                ['compte_comptable_id' => $caisse->compte_comptable_id, 'sens' => 'credit', 'montant' => $montant],
                ['role' => 'contrepartie_ouverture', 'sens' => 'debit', 'montant' => $montant],
            ],
            siteId: $this->site->id,
            createdBy: $this->user->id,
        );
    }

    private function soldeCaisse(): float
    {
        return app(TresorerieDisponibiliteService::class)->soldePourSupport($this->caisse);
    }

    private function partProprietaire(CommandeVente $commande): CommissionEnveloppePart
    {
        return CommissionEnveloppePart::whereIn('enveloppe_id', CommissionEnveloppe::where('source_id', $commande->id)
            ->where('cible_type', CommissionCibleType::CODE_PROPRIETAIRE)->select('id'))
            ->where('statut', '!=', StatutCommission::ANNULEE->value)
            ->sole();
    }

    /**
     * Paie `$montant` de la part propriétaire sur une fiche (figée dès ce paiement). `$autreLigne` :
     * autre commission du même bénéficiaire portée par la même fiche.
     */
    private function payerPartProprietaire(CommandeVente $commande, float $montant, float $autreLigne = 0): array
    {
        $part = $this->partProprietaire($commande);
        $periode = PaiementPeriode::create([
            'organization_id' => $this->org->id,
            'reference' => 'PAY-'.uniqid(),
            'type' => TypePeriodePaiement::PROPRIETAIRE->value,
            'date_debut' => now()->startOfMonth(),
            'date_fin' => now()->endOfMonth(),
            'statut' => 'validee',
        ]);
        $fiche = PaiementFiche::create([
            'organization_id' => $this->org->id,
            'periode_id' => $periode->id,
            'reference' => 'FIC-'.uniqid(),
            'beneficiaire_type' => 'proprietaire',
            'beneficiaire_id' => $part->beneficiaire_id,
            'beneficiaire_nom' => 'Propriétaire',
            'site_id' => $this->site->id,
            'montant_brut' => (float) $part->montant_net + $autreLigne,
            'total_deductions' => 0,
            'montant_net' => (float) $part->montant_net + $autreLigne,
            'montant_paye' => 0,
            'statut' => 'a_payer',
        ]);
        if ($autreLigne > 0) {
            PaiementFicheLigne::create([
                'fiche_id' => $fiche->id,
                'source_type' => 'App\\Models\\CommissionLogistiquePart',
                'source_id' => (string) Str::ulid(),
                'type_ligne' => 'commission_logistique',
                'libelle' => 'Autre commission',
                'montant' => $autreLigne,
                'ordre' => 2,
            ]);
        }
        PaiementFicheLigne::create([
            'fiche_id' => $fiche->id,
            'source_type' => CommissionEnveloppePart::class,
            'source_id' => $part->id,
            'type_ligne' => 'commission_vente',
            'libelle' => 'Commission',
            'montant' => (float) $part->montant_net,
            'ordre' => 1,
        ]);
        $paiement = PaiementFichePaiement::create([
            'fiche_id' => $fiche->id,
            'organization_id' => $this->org->id,
            'montant' => $montant,
            'mode_paiement' => 'especes',
            'date_paiement' => now()->toDateString(),
            'created_by' => $this->user->id,
        ]);
        $fiche->forceFill(['montant_paye' => $montant, 'statut' => 'partiellement_paye'])->saveQuietly();
        CommissionEnveloppePaiementItem::create([
            'paiement_id' => $paiement->id,
            'commission_enveloppe_part_id' => $part->id,
            'amount_allocated' => $montant,
        ]);
        $part->recalculStatut();

        return ['part' => $part->fresh(), 'fiche' => $fiche->fresh()];
    }

    /** Paiement d'une fiche par la route réelle (plafond, allocation, trésorerie). */
    private function payerFiche(PaiementFiche $fiche, float $montant): TestResponse
    {
        return $this->actingAs($this->user)->post(route('comptabilite.fiches.paiements.store', $fiche), [
            'montant' => $montant,
            'mode_paiement' => 'especes',
            'date_paiement' => now()->toDateString(),
        ]);
    }

    // ── Parcours nominal ──────────────────────────────────────────────────────

    public function test_excedent_jamais_recu_quantite_facture_stock_caisse_compta_et_commission_corriges(): void
    {
        ['commande' => $commande, 'ligne' => $ligne] = $this->venteEncaissee();
        $statutAvant = $commande->statut;
        $encaissementInitial = $commande->facture->encaissements()->sole();
        $this->assertSame(90, $this->stock($ligne), 'précondition : stock sorti au chargement');
        $this->assertEquals(20000.0, $this->soldeCaisse(), 'précondition : espèces dans la caisse');
        $this->assertEquals(500.0, (float) $this->partProprietaire($commande)->montant_net, 'précondition : commission sur 10 packs');

        $this->regulariser($commande, $ligne);

        $commande = $commande->fresh(['facture.encaissements', 'lignes']);
        $ligne = $ligne->fresh();
        $this->assertSame($statutAvant, $commande->statut, 'le statut de la commande ne change pas');
        $this->assertSame(10, (int) $ligne->quantite_chargee, 'la quantité chargée d\'origine reste tracée');
        $this->assertSame(4, (int) $ligne->quantite_livree);
        $this->assertSame(6, (int) $ligne->quantite_retournee);
        $this->assertEquals(8000.0, (float) $commande->total_commande);
        $this->assertEquals(8000.0, (float) $commande->facture->montant_net);
        $this->assertEquals(StatutFactureVente::PAYEE, $commande->facture->statut_facture);

        // Excédent jamais reçu : l'encaissement est remplacé par un encaissement de 8 000, la caisse
        // perd 12 000 (contrepassation), rien à rembourser.
        $this->assertNull(EncaissementVente::find($encaissementInitial->id));
        $restant = $commande->facture->encaissements->sole();
        $this->assertEquals(8000.0, (float) $restant->montant);
        $this->assertSame($encaissementInitial->created_by, $restant->created_by);
        $this->assertEquals(8000.0, $this->soldeCaisse());
        $this->assertEquals(0.0, $commande->facture->tropPercu());

        // Stock : les 6 packs jamais vendus reviennent.
        $this->assertSame(96, $this->stock($ligne));

        // Comptabilité : pièce de régularisation de la vente sur 12 000.
        $mouvement = CommandeVenteRetour::where('commande_vente_id', $commande->id)->sole();
        $this->assertSame(CommandeVenteRetour::NATURE_REGULARISATION, $mouvement->nature);
        $piece = PieceComptable::where('source_id', $mouvement->id)->where('type_evenement', EvenementComptable::VENTE_RETOUR->value)->sole();
        $this->assertStringContainsString('Régularisation après encaissement', $piece->libelle);

        // Commission non payée : remplacée immédiatement par la commission sur 4 packs.
        $this->assertEquals(200.0, (float) $this->partProprietaire($commande)->montant_net);
        $this->assertEquals(200.0, (float) CommissionEnveloppe::where('source_id', $commande->id)->where('cible_type', CommissionCibleType::CODE_PROPRIETAIRE)->value('montant_total'));
        $this->assertSame(0, CommissionEcartRegularisation::count(), 'aucun paiement : aucun écart à suivre');

        // Trace.
        $trace = RegularisationVente::where('commande_vente_id', $commande->id)->sole();
        $this->assertSame(self::MOTIF, $trace->motif);
        $this->assertSame($this->user->id, $trace->user_id);
        $this->assertEquals(20000.0, (float) $trace->montant_avant);
        $this->assertEquals(8000.0, (float) $trace->montant_apres);
        $this->assertEquals(12000.0, (float) $trace->excedent);
        $this->assertSame(RegularisationVente::EXCEDENT_NON_RECU, $trace->traitement_excedent);
        $this->assertEquals(500.0, (float) $trace->commissions_avant);
        $this->assertEquals(200.0, (float) $trace->commissions_apres);
        $this->assertSame('email_code', $trace->methode_confirmation);
        $this->assertTrue($commande->activites()->where('action', 'regularisee')->exists());
        Mail::assertSent(RegularisationVenteCodeMail::class, fn ($mail) => $mail->hasTo('super.admin@example.com'));
    }

    public function test_excedent_recu_devient_un_trop_percu_rembourse_par_une_operation_distincte(): void
    {
        ['commande' => $commande, 'ligne' => $ligne] = $this->venteEncaissee();

        $this->regulariser($commande, $ligne, 4, RegularisationVente::EXCEDENT_A_REMBOURSER);

        $facture = $commande->fresh('facture')->facture;
        $this->assertEquals(20000.0, (float) $facture->encaissements()->sum('montant'), 'l\'encaissement réel est conservé');
        $this->assertEquals(12000.0, $facture->tropPercu());
        $this->assertEquals(20000.0, $this->soldeCaisse());

        $this->actingAs($this->user)->get(route('ventes.show', $commande))
            ->assertInertia(fn (Assert $page) => $page
                ->where('regularisation_remboursement.trop_percu', 12000)
                ->where('regularisation_remboursement.can_rembourser', true));

        $this->actingAs($this->user)
            ->post(route('ventes.regularisation.remboursement', $commande), [
                'montant' => 12000,
                'mode_paiement' => 'especes',
            ])
            ->assertSessionHasNoErrors();

        $facture->refresh();
        $this->assertEquals(0.0, $facture->tropPercu());
        $this->assertEquals(StatutFactureVente::PAYEE, $facture->statut_facture);
        $this->assertEquals(8000.0, $this->soldeCaisse());
    }

    // ── Commission déjà payée ─────────────────────────────────────────────────

    public function test_commission_deja_payee_recalculee_immediatement_ecart_trace_et_paiements_intacts(): void
    {
        ['commande' => $commande, 'ligne' => $ligne] = $this->venteEncaissee();
        ['part' => $part, 'fiche' => $fiche] = $this->payerPartProprietaire($commande, 500);
        $this->assertSame(StatutCommission::PAYE, $part->statut, 'précondition : part payée');

        $recap = $this->recapitulatif($commande, $ligne);
        $ligneRecap = collect($recap['commissions']['parts'])->firstWhere('part_id', $part->id);
        $this->assertTrue($ligneRecap['engagee']);
        $this->assertEquals(300.0, $ligneRecap['ecart']);

        $this->regulariser($commande, $ligne);

        $part = $part->fresh();
        $this->assertNotNull($part, 'une part payée n\'est jamais supprimée');
        $this->assertEquals(200.0, (float) $part->montant_net, 'montant recalculé sur la quantité corrigée');
        $this->assertEquals(500.0, (float) $part->montant_verse, 'paiement historique intact');
        $this->assertSame(StatutCommission::PAYE, $part->statut);
        $this->assertEquals(500.0, (float) CommissionEnveloppePaiementItem::where('commission_enveloppe_part_id', $part->id)->sum('amount_allocated'));
        $this->assertEquals(500.0, (float) $fiche->fresh()->montant_paye, 'fiche payée intacte');

        $ecart = CommissionEcartRegularisation::sole();
        $this->assertSame($part->id, $ecart->commission_enveloppe_part_id);
        $this->assertEquals(500.0, (float) $ecart->montant_avant);
        $this->assertEquals(200.0, (float) $ecart->montant_apres);
        $this->assertEquals(500.0, (float) $ecart->montant_verse);
        $this->assertEquals(300.0, (float) $ecart->ecart);
        $this->assertSame(CommissionEcartRegularisation::SENS_TROP_PERCU, $ecart->sens);
        $this->assertSame(CommissionEcartRegularisation::STATUT_A_REGULARISER, $ecart->statut);
        $this->assertTrue((float) $ecart->ecart > 0, 'jamais de commission négative : l\'écart est suivi à part');
    }

    /**
     * Fiche de 500 payée 300, commission ramenée à 200 : l'ancien reste (200) n'est plus dû. Ni
     * avant ni après « Marquer régularisé », il ne doit pouvoir être payé (sinon 500 versés pour
     * 200 dus).
     */
    public function test_trop_percu_le_reste_de_la_fiche_n_est_plus_payable_meme_apres_marquage(): void
    {
        ['commande' => $commande, 'ligne' => $ligne] = $this->venteEncaissee();
        ['fiche' => $fiche] = $this->payerPartProprietaire($commande, 300);
        $this->assertEquals(200.0, $fiche->montant_restant, 'précondition');

        $this->regulariser($commande, $ligne);

        $ecart = CommissionEcartRegularisation::sole();
        $this->assertEquals(100.0, (float) $ecart->ecart, '300 versés pour 200 dus');
        $this->assertSame(CommissionEcartRegularisation::SENS_TROP_PERCU, $ecart->sens);

        $fiche->refresh();
        $this->assertEquals(500.0, (float) $fiche->montant_net, 'montants figés intacts (ADR 0010)');
        $this->assertEquals(300.0, (float) $fiche->montant_paye, 'paiements intacts');
        $this->assertEquals(200.0, (float) $fiche->reduction_regularisation);
        $this->assertEquals(0.0, $fiche->montant_restant);
        $this->assertSame(StatutFichePaiement::PAYE, $fiche->statut);
        $this->payerFiche($fiche, 100)->assertSessionHasErrors('montant');

        $this->actingAs($this->user)
            ->post(route('ventes.regularisation.ecarts.regularise', [$commande, $ecart]), ['commentaire' => 'Retenue de 100 GNF faite sur la fiche de novembre'])
            ->assertSessionHasNoErrors();

        $ecart->refresh();
        $this->assertSame(CommissionEcartRegularisation::STATUT_REGULARISE, $ecart->statut);
        $this->assertSame($this->user->id, $ecart->regularise_par);
        $this->assertEquals(0.0, $fiche->fresh()->montant_restant, 'le marquage ne rouvre aucun reste');
        $this->payerFiche($fiche->fresh(), 100)->assertSessionHasErrors('montant');
        $this->assertSame(1, PaiementFichePaiement::where('fiche_id', $fiche->id)->count());
    }

    /**
     * Fiche de 500 payée 100, commission ramenée à 200 : il reste 100 (non contesté) à payer, pas
     * 400. La fiche n'est pas bloquée ; le complément se solde par le paiement et se rouvre si ce
     * paiement est annulé.
     */
    public function test_complement_paye_par_la_fiche_au_montant_recalcule_sans_blocage(): void
    {
        ['commande' => $commande, 'ligne' => $ligne] = $this->venteEncaissee();
        ['part' => $part, 'fiche' => $fiche] = $this->payerPartProprietaire($commande, 100);

        $this->regulariser($commande, $ligne);

        $ecart = CommissionEcartRegularisation::sole();
        $this->assertEquals(-100.0, (float) $ecart->ecart);
        $this->assertSame(CommissionEcartRegularisation::SENS_COMPLEMENT, $ecart->sens);
        $this->assertSame(CommissionEcartRegularisation::STATUT_A_REGULARISER, $ecart->statut);
        $this->assertEquals(100.0, $fiche->fresh()->montant_restant, '200 dus − 100 versés');

        // Un complément ne se marque pas à la main : il pourrait être payé deux fois.
        $this->actingAs($this->user)
            ->post(route('ventes.regularisation.ecarts.regularise', [$commande, $ecart]), ['commentaire' => 'Complément payé en espèces'])
            ->assertStatus(422);

        $this->payerFiche($fiche->fresh(), 150)->assertSessionHasErrors('montant');
        $this->payerFiche($fiche->fresh(), 100)->assertSessionHasNoErrors();

        $part->refresh();
        $this->assertEquals(200.0, (float) $part->montant_verse);
        $this->assertSame(StatutCommission::PAYE, $part->statut);
        $fiche->refresh();
        $this->assertSame(StatutFichePaiement::PAYE, $fiche->statut);
        $this->assertEquals(0.0, $fiche->montant_restant);
        $ecart->refresh();
        $this->assertSame(CommissionEcartRegularisation::STATUT_REGULARISE, $ecart->statut);
        $this->assertSame(CommissionEcartRegularisation::COMMENTAIRE_COMPLEMENT_VERSE, $ecart->commentaire_regularisation);
        $this->assertNull($ecart->regularise_par);

        // Annulation de ce paiement : le complément redevient dû, la fiche aussi.
        $paiement = PaiementFichePaiement::where('fiche_id', $fiche->id)->latest('created_at')->latest('id')->first();
        $this->actingAs($this->user)->delete(route('comptabilite.fiches.paiements.destroy', $paiement))->assertSessionHasNoErrors();

        $this->assertSame(CommissionEcartRegularisation::STATUT_A_REGULARISER, $ecart->fresh()->statut);
        $this->assertEquals(100.0, $fiche->fresh()->montant_restant);
    }

    /**
     * Le trop-perçu d'une commission n'est pas compensé sur les autres commissions de la fiche :
     * elles restent dues en entier, l'écart est suivi à part.
     */
    public function test_trop_percu_non_compense_sur_les_autres_lignes_de_la_fiche(): void
    {
        ['commande' => $commande, 'ligne' => $ligne] = $this->venteEncaissee();
        ['fiche' => $fiche] = $this->payerPartProprietaire($commande, 300, autreLigne: 1000);

        $this->regulariser($commande, $ligne);

        $fiche->refresh();
        $this->assertEquals(1500.0, (float) $fiche->montant_net);
        $this->assertEquals(200.0, (float) $fiche->reduction_regularisation, '500 − 300 déjà versés');
        $this->assertEquals(1000.0, $fiche->montant_restant, 'autre commission due en entier');
        $this->assertSame(CommissionEcartRegularisation::SENS_TROP_PERCU, CommissionEcartRegularisation::sole()->sens);
    }

    // ── Garde-fous ────────────────────────────────────────────────────────────

    public function test_seule_une_baisse_est_acceptee_et_jamais_jusqu_a_zero(): void
    {
        ['commande' => $commande, 'ligne' => $ligne] = $this->venteEncaissee();

        foreach ([12, 10, 0] as $quantite) {
            $this->actingAs($this->user)
                ->postJson(route('ventes.regularisation.recapitulatif', $commande), $this->charge($ligne, $quantite, RegularisationVente::EXCEDENT_NON_RECU))
                ->assertStatus(422)
                ->assertJsonValidationErrors('lignes');
        }
    }

    public function test_le_traitement_de_l_excedent_est_obligatoire(): void
    {
        ['commande' => $commande, 'ligne' => $ligne] = $this->venteEncaissee();

        $recap = $this->recapitulatif($commande, $ligne, 4, null);

        $this->assertEquals(12000.0, $recap['excedent']);
        $this->assertStringContainsString('excédent', implode(' ', $recap['blocages']));
    }

    public function test_refuse_de_retirer_des_especes_qui_ont_deja_quitte_la_caisse(): void
    {
        ['commande' => $commande, 'ligne' => $ligne] = $this->venteEncaissee();
        // Les espèces sont parties de la caisse dédiée (versement) : il n'en reste que 5 000.
        $this->sortirDeLaCaisse($this->caisse, 15000);

        $recap = $this->recapitulatif($commande, $ligne);

        $this->assertStringContainsString('a déjà quitté', implode(' ', $recap['blocages']));
        // « Montant reçu » reste possible : rien n'est retiré de la caisse.
        $this->assertSame([], $this->recapitulatif($commande, $ligne, 4, RegularisationVente::EXCEDENT_A_REMBOURSER)['blocages']);
    }

    public function test_une_vente_non_encaissee_n_est_pas_eligible(): void
    {
        ['commande' => $commande] = $this->venteEncaissee();
        $commande->facture->encaissements()->each(fn (EncaissementVente $e) => $e->delete());

        $this->actingAs($this->user)->get(route('ventes.show', $commande))
            ->assertInertia(fn (Assert $page) => $page->where('commande.can_regulariser', false));
    }

    public function test_par_defaut_le_code_email_est_exige(): void
    {
        ['commande' => $commande, 'ligne' => $ligne] = $this->venteEncaissee();
        $recap = $this->recapitulatif($commande, $ligne);

        $this->actingAs($this->user)
            ->post(route('ventes.regularisation.confirmer', $commande), $this->charge($ligne, 4, RegularisationVente::EXCEDENT_NON_RECU) + [
                'motif' => self::MOTIF,
                'empreinte' => $recap['empreinte'],
            ])
            ->assertSessionHasErrors('code');

        $this->assertSame(0, RegularisationVente::count());
        $this->assertEquals(20000.0, (float) $commande->fresh('facture')->facture->montant_net);
    }

    public function test_en_mode_simple_la_confirmation_se_fait_sans_code(): void
    {
        Parametre::setModeConfirmationAnnulationExceptionnelle($this->org->id, ModeConfirmationAnnulationExceptionnelle::SIMPLE);
        ['commande' => $commande, 'ligne' => $ligne] = $this->venteEncaissee();
        $recap = $this->recapitulatif($commande, $ligne);
        $this->assertSame('simple', $recap['mode_confirmation']);

        $this->actingAs($this->user)
            ->post(route('ventes.regularisation.confirmer', $commande), $this->charge($ligne, 4, RegularisationVente::EXCEDENT_NON_RECU) + [
                'motif' => self::MOTIF,
                'empreinte' => $recap['empreinte'],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('simple', RegularisationVente::sole()->methode_confirmation);
        Mail::assertNothingSent();
    }

    public function test_refuse_si_les_quantites_ont_change_depuis_le_recapitulatif(): void
    {
        Parametre::setModeConfirmationAnnulationExceptionnelle($this->org->id, ModeConfirmationAnnulationExceptionnelle::SIMPLE);
        ['commande' => $commande, 'ligne' => $ligne] = $this->venteEncaissee();
        $empreinte = $this->recapitulatif($commande, $ligne, 4)['empreinte'];

        $this->actingAs($this->user)
            ->post(route('ventes.regularisation.confirmer', $commande), $this->charge($ligne, 3, RegularisationVente::EXCEDENT_NON_RECU) + [
                'motif' => self::MOTIF,
                'empreinte' => $empreinte,
            ])
            ->assertSessionHasErrors('empreinte');

        $this->assertSame(0, RegularisationVente::count());
    }

    public function test_une_double_soumission_n_applique_la_regularisation_qu_une_fois(): void
    {
        ['commande' => $commande, 'ligne' => $ligne] = $this->venteEncaissee();
        $recap = $this->recapitulatif($commande, $ligne);
        $donnees = $this->charge($ligne, 4, RegularisationVente::EXCEDENT_NON_RECU) + ['motif' => self::MOTIF, 'empreinte' => $recap['empreinte']];

        $this->actingAs($this->user)->postJson(route('ventes.regularisation.code', $commande), $donnees)->assertOk();
        $this->actingAs($this->user)->post(route('ventes.regularisation.confirmer', $commande), $donnees + ['code' => self::CODE])
            ->assertSessionHasNoErrors();
        $stockApres = $this->stock($ligne);

        // Même requête rejouée (double clic, rechargement) : refusée, rien n'est appliqué deux fois.
        $this->actingAs($this->user)->post(route('ventes.regularisation.confirmer', $commande), $donnees + ['code' => self::CODE])
            ->assertSessionHasErrors();

        $this->assertSame(1, RegularisationVente::count());
        $this->assertSame(1, CommandeVenteRetour::count());
        $this->assertSame($stockApres, $this->stock($ligne));
        $this->assertEquals(4 * self::PRIX_VENTE, (float) EncaissementVente::where('facture_vente_id', $commande->facture->id)->sum('montant'));
    }

    /** Panne à la dernière étape (commissions) : facture, stock, caisse et comptabilité inchangés. */
    public function test_un_echec_en_cours_de_regularisation_n_applique_rien(): void
    {
        ['commande' => $commande, 'ligne' => $ligne] = $this->venteEncaissee();
        $recap = $this->recapitulatif($commande, $ligne);
        $donnees = $this->charge($ligne, 4, RegularisationVente::EXCEDENT_NON_RECU) + ['motif' => self::MOTIF, 'empreinte' => $recap['empreinte']];
        $this->actingAs($this->user)->postJson(route('ventes.regularisation.code', $commande), $donnees)->assertOk();

        $stock = $this->stock($ligne);
        $solde = $this->soldeCaisse();
        $pieces = PieceComptable::count();
        $partAvant = $this->partProprietaire($commande)->only(['id', 'montant_net', 'statut']);

        $this->partialMock(RegularisationCommissionService::class, function ($mock) {
            $mock->shouldReceive('appliquer')->andThrow(new \RuntimeException('Panne simulée'));
        });

        $this->actingAs($this->user)->post(route('ventes.regularisation.confirmer', $commande), $donnees + ['code' => self::CODE])
            ->assertSessionHasErrors(['regularisation' => 'Régularisation impossible : Panne simulée']);

        $this->assertSame(0, RegularisationVente::count());
        $this->assertSame(0, CommandeVenteRetour::count());
        $this->assertSame($stock, $this->stock($ligne));
        $this->assertEquals($solde, $this->soldeCaisse());
        $this->assertSame($pieces, PieceComptable::count());
        $facture = $commande->fresh('facture')->facture;
        $this->assertEquals(10 * self::PRIX_VENTE, (float) $facture->montant_net);
        $this->assertEquals(10 * self::PRIX_VENTE, (float) EncaissementVente::where('facture_vente_id', $facture->id)->sum('montant'));
        $this->assertEquals($partAvant, $this->partProprietaire($commande)->only(['id', 'montant_net', 'statut']));
    }

    public function test_le_retour_de_livraison_ne_peut_pas_utiliser_le_motif_reserve(): void
    {
        // Commande en livraison, pas encore encaissée : le retour est possible, pas ce motif.
        ['commande' => $commande, 'ligne' => $ligne] = $this->venteEncaissee(encaisser: false);

        $this->actingAs($this->user)
            ->post(route('ventes.retour.store', $commande), [
                'motif' => 'regularisation',
                'lignes' => [['id' => $ligne->id, 'quantite' => 1]],
            ])
            ->assertSessionHasErrors('motif');
    }

    // ── Autorisations et isolation ────────────────────────────────────────────

    public function test_sans_la_permission_les_routes_sont_refusees_et_le_bouton_masque(): void
    {
        ['commande' => $commande, 'ligne' => $ligne] = $this->venteEncaissee();
        $sansPermission = $this->makeUserWithPermissions($this->org, ['ventes.read', 'ventes.update']);
        $sansPermission->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($sansPermission)
            ->postJson(route('ventes.regularisation.recapitulatif', $commande), $this->charge($ligne, 4, RegularisationVente::EXCEDENT_NON_RECU))
            ->assertForbidden();
        $this->actingAs($sansPermission)
            ->post(route('ventes.regularisation.confirmer', $commande), $this->charge($ligne, 4, RegularisationVente::EXCEDENT_NON_RECU) + ['motif' => self::MOTIF, 'empreinte' => str_repeat('a', 64)])
            ->assertForbidden();

        $this->actingAs($sansPermission)->get(route('ventes.show', $commande))
            ->assertInertia(fn (Assert $page) => $page->where('commande.can_regulariser', false));
        $this->actingAs($this->user)->get(route('ventes.show', $commande))
            ->assertInertia(fn (Assert $page) => $page->where('commande.can_regulariser', true));
    }

    public function test_un_super_admin_d_une_autre_organisation_est_refuse(): void
    {
        ['commande' => $commande, 'ligne' => $ligne] = $this->venteEncaissee();

        $autreOrg = Organization::factory()->create();
        $intrus = $this->makeUserWithPermissions($autreOrg, ['ventes.read', 'ventes.regulariser_apres_encaissement']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $intrus->assignRole('super_admin');
        $this->attachDefaultSite($autreOrg, $intrus);

        $this->actingAs($intrus)
            ->postJson(route('ventes.regularisation.recapitulatif', $commande), $this->charge($ligne, 4, RegularisationVente::EXCEDENT_NON_RECU))
            ->assertForbidden();
        $this->actingAs($intrus)
            ->post(route('ventes.regularisation.confirmer', $commande), $this->charge($ligne, 4, RegularisationVente::EXCEDENT_NON_RECU) + ['motif' => self::MOTIF, 'empreinte' => str_repeat('a', 64)])
            ->assertForbidden();

        $this->assertSame(0, RegularisationVente::count());
    }

    public function test_la_migration_accorde_la_permission_au_seul_super_admin(): void
    {
        $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $superAdmin->revokePermissionTo(Permission::firstOrCreate(['name' => 'ventes.regulariser_apres_encaissement', 'guard_name' => 'web']));

        $migration = require database_path('migrations/2026_10_30_130100_backfill_ventes_regulariser_apres_encaissement_permission.php');
        $migration->up();
        $migration->up();

        $this->assertTrue($superAdmin->fresh()->hasPermissionTo('ventes.regulariser_apres_encaissement'));
        $this->assertFalse($admin->fresh()->hasPermissionTo('ventes.regulariser_apres_encaissement'));
        $this->assertSame(1, Permission::where('name', 'ventes.regulariser_apres_encaissement')->count());
    }
}
