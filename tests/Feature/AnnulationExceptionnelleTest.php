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
use App\Enums\OtpPurpose;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutCommission;
use App\Enums\StatutFactureVente;
use App\Mail\AnnulationExceptionnelleCodeMail;
use App\Models\AnnulationExceptionnelle;
use App\Models\CashbackSolde;
use App\Models\CashbackTransaction;
use App\Models\Categorie;
use App\Models\Client;
use App\Models\CommandeVente;
use App\Models\CommandeVenteLigne;
use App\Models\CommissionCibleType;
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
use App\Models\Parametre;
use App\Models\PieceComptable;
use App\Models\Proprietaire;
use App\Models\Site;
use App\Models\User;
use App\Models\UserAuthIdentity;
use App\Models\VarianteStock;
use App\Models\Vehicule;
use App\Services\CommandeVenteService;
use App\Services\Commission\CommissionProcessusDefaults;
use App\Services\Comptabilite\EcritureComptableService;
use App\Services\OtpService;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Concerns\HasProduitVariante;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Annulation exceptionnelle d'une commande saisie par erreur (décision produit du 24/09/2026, cf.
 * docs/annulation-exceptionnelle.md, ADR 0004) : permission dédiée, code à usage unique envoyé par
 * e-mail à l'utilisateur authentifié, garde-fous revérifiés à la confirmation, régularisations
 * (encaissements contrepassés, facture annulée, stock réintégré, commissions annulées, cashback
 * retiré) et trace dans annulations_exceptionnelles.
 *
 * `.env.testing` fixe OTP_FIXED_CODE=123456 : le code envoyé est toujours celui-là.
 */
class AnnulationExceptionnelleTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, HasProduitVariante, RefreshDatabase;

    private const PRIX_VENTE = 2000;

    private const CODE = '123456';

    private const MOTIF = 'Commande de formation saisie par erreur en production';

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
            'factures.encaisser', 'ventes.annuler_exceptionnel',
        ]);
        // Le code est envoyé à l'adresse e-mail de l'utilisateur : la factory n'en crée pas par défaut.
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

        $this->categorie = Categorie::create([
            'organization_id' => $this->org->id,
            'nom' => 'Défaut',
            'statut' => 'actif',
        ]);

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
            'montant' => 50,
            'effective_from' => now()->subDay()->toDateString(),
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
            'capacite_packs' => 50,
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
            'montant_unitaire' => 0, 'effective_from' => now()->subDay(),
        ]);

        return $vehicule->fresh();
    }

    /**
     * Commande standard de 10 packs chargée (stock initial 100 → 90), en livraison.
     *
     * @return array{commande: CommandeVente, ligne: CommandeVenteLigne}
     */
    private function commandeEnLivraison(): array
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
            ['nom' => 'Pack eau', 'categorie_id' => $this->categorie->id],
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

        return ['commande' => $commande->fresh(), 'ligne' => $ligne->fresh()];
    }

    /** @return array{commande: CommandeVente, ligne: CommandeVenteLigne} */
    private function commandeEncaissee(float $montant = 10 * self::PRIX_VENTE): array
    {
        $contexte = $this->commandeEnLivraison();
        $this->encaisser($contexte['commande'], $montant);

        return ['commande' => $contexte['commande']->fresh(), 'ligne' => $contexte['ligne']];
    }

    private function encaisser(CommandeVente $commande, float $montant): void
    {
        $this->actingAs($this->user)
            ->post(route('encaissements.store', $commande->fresh('facture')->facture), [
                'montant' => $montant,
                'mode_paiement' => 'especes',
            ])
            ->assertSessionHasNoErrors();
    }

    private function stock(CommandeVenteLigne $ligne): int
    {
        return (int) VarianteStock::where('produit_variante_id', $ligne->variante_id)
            ->where('site_id', $this->site->id)
            ->value('qte_stock');
    }

    /** @return array<string, mixed> */
    private function recapitulatif(CommandeVente $commande, ?User $user = null): array
    {
        return $this->actingAs($user ?? $this->user)
            ->getJson(route('ventes.annulation-exceptionnelle.show', $commande))
            ->assertOk()
            ->json();
    }

    private function demanderCode(CommandeVente $commande, string $empreinte, string $motif = self::MOTIF, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user)
            ->postJson(route('ventes.annulation-exceptionnelle.code', $commande), [
                'motif' => $motif,
                'empreinte' => $empreinte,
            ]);
    }

    private function confirmer(CommandeVente $commande, string $empreinte, string $code = self::CODE, string $motif = self::MOTIF, ?User $user = null)
    {
        return $this->actingAs($user ?? $this->user)
            ->post(route('ventes.annulation-exceptionnelle.confirmer', $commande), [
                'motif' => $motif,
                'empreinte' => $empreinte,
                'code' => $code,
            ]);
    }

    /** Parcours complet réussi : récapitulatif → code → confirmation. */
    private function annulerExceptionnellement(CommandeVente $commande): void
    {
        $empreinte = $this->recapitulatif($commande)['empreinte'];
        $this->demanderCode($commande, $empreinte)->assertOk();
        $this->confirmer($commande, $empreinte)
            ->assertRedirect(route('ventes.show', $commande))
            ->assertSessionHasNoErrors();
    }

    private function soldeCaisse(): float
    {
        return app(TresorerieDisponibiliteService::class)->soldePourSupport($this->caisse);
    }

    // ── Parcours nominal ──────────────────────────────────────────────────────

    public function test_annule_une_commande_chargee_facturee_et_encaissee_avec_toutes_ses_regularisations(): void
    {
        ['commande' => $commande, 'ligne' => $ligne] = $this->commandeEncaissee();
        $facture = $commande->facture;
        $encaissement = $facture->encaissements()->sole();
        $pieceVente = PieceComptable::where('source_id', $facture->id)->where('type_evenement', EvenementComptable::VENTE_FACTUREE->value)->firstOrFail();
        $pieceEncaissement = PieceComptable::where('source_id', $encaissement->id)->where('type_evenement', EvenementComptable::ENCAISSEMENT_VENTE_RECU->value)->firstOrFail();

        $this->assertTrue($facture->isPayee(), 'précondition : facture payée');
        $this->assertSame(90, $this->stock($ligne), 'précondition : stock sorti au chargement');
        $this->assertEquals(20000.0, $this->soldeCaisse(), 'précondition : espèces dans la caisse dédiée');
        $this->assertNotEmpty($commande->commissions, 'précondition : commission générée');

        $this->annulerExceptionnellement($commande);

        $commande = $commande->fresh(['facture', 'commissions.parts']);
        $this->assertEquals(StatutCommandeVente::ANNULEE_ERREUR_SAISIE, $commande->statut);
        $this->assertSame($this->user->id, $commande->annulee_par);
        $this->assertStringContainsString(self::MOTIF, $commande->motif_annulation);

        // Facture annulée et écriture de vente contrepassée.
        $this->assertEquals(StatutFactureVente::ANNULEE, $commande->facture->statut_facture);
        $this->assertTrue(PieceComptable::where('type_evenement', 'contrepassation_de_'.$pieceVente->id)->exists());

        // Encaissement retiré, son écriture contrepassée (jamais effacée) : la caisse revient à 0.
        $this->assertNull(EncaissementVente::find($encaissement->id));
        $this->assertNotNull($pieceEncaissement->fresh());
        $this->assertTrue(PieceComptable::where('type_evenement', 'contrepassation_de_'.$pieceEncaissement->id)->exists());
        $this->assertEquals(0.0, $this->soldeCaisse());

        // Stock réintégré, commissions annulées.
        $this->assertSame(100, $this->stock($ligne));
        $this->assertTrue($commande->commissions->every(fn ($c) => $c->statut === StatutCommission::ANNULEE));
        $this->assertTrue($commande->commissions->flatMap->parts->every(fn ($p) => $p->statut === StatutCommission::ANNULEE));

        // Journal d'activité.
        $this->assertTrue($commande->activites()->where('action', 'annulee_erreur_saisie')->exists());
    }

    public function test_la_trace_d_audit_conserve_qui_quand_pourquoi_et_les_montants_sans_jamais_le_code(): void
    {
        ['commande' => $commande] = $this->commandeEncaissee();
        $empreinte = $this->recapitulatif($commande)['empreinte'];
        $statutAvant = $commande->statut->value;

        $this->annulerExceptionnellement($commande);

        $trace = AnnulationExceptionnelle::where('commande_vente_id', $commande->id)->sole();
        $this->assertSame($this->org->id, $trace->organization_id);
        $this->assertSame($this->user->id, $trace->user_id);
        $this->assertSame(self::MOTIF, $trace->motif);
        $this->assertSame($statutAvant, $trace->statut_avant);
        $this->assertSame($empreinte, $trace->empreinte);
        $this->assertSame(ModeConfirmationAnnulationExceptionnelle::EMAIL_CODE->value, $trace->methode_confirmation);
        $this->assertNotNull($trace->code_demande_at);
        $this->assertNotNull($trace->confirmee_at);
        $this->assertEquals(20000.0, (float) $trace->montant_commande);
        $this->assertEquals(20000.0, (float) $trace->montant_facture);
        $this->assertEquals(20000.0, (float) $trace->montant_encaisse);
        $this->assertNotEmpty($trace->regularisations);
        $this->assertContains('encaissements_contrepasses', array_column($trace->regularisations, 'type'));
        $this->assertContains('stock_reintegre', array_column($trace->regularisations, 'type'));

        // Jamais le code, ni en clair dans aucune colonne.
        $this->assertStringNotContainsString(self::CODE, json_encode($trace->getAttributes()));
        // Adresse de destination conservée masquée, jamais en clair.
        $this->assertSame(OtpService::mask($this->user->email), $trace->code_envoye_a);
        $this->assertNotSame($this->user->email, $trace->code_envoye_a);
    }

    public function test_le_code_est_envoye_a_l_adresse_de_l_utilisateur_authentifie_uniquement(): void
    {
        ['commande' => $commande] = $this->commandeEncaissee();
        $empreinte = $this->recapitulatif($commande)['empreinte'];

        $this->demanderCode($commande, $empreinte)
            ->assertOk()
            ->assertJsonPath('expire_minutes', 10)
            ->assertJsonPath('destination', OtpService::mask($this->user->email));

        Mail::assertSent(AnnulationExceptionnelleCodeMail::class, function (AnnulationExceptionnelleCodeMail $mail) use ($commande) {
            return $mail->hasTo($this->user->email)
                && count($mail->to) === 1
                && $mail->referenceCommande === $commande->reference
                && $mail->envelope()->subject === 'Code de confirmation — Annulation exceptionnelle';
        });
    }

    // ── Code ──────────────────────────────────────────────────────────────────

    public function test_un_code_incorrect_ne_modifie_rien(): void
    {
        ['commande' => $commande, 'ligne' => $ligne] = $this->commandeEncaissee();
        $empreinte = $this->recapitulatif($commande)['empreinte'];
        $this->demanderCode($commande, $empreinte)->assertOk();

        $this->confirmer($commande, $empreinte, '000000')->assertSessionHasErrors('code');

        $this->assertNotEquals(StatutCommandeVente::ANNULEE_ERREUR_SAISIE, $commande->fresh()->statut);
        $this->assertSame(1, $commande->facture->encaissements()->count());
        $this->assertSame(90, $this->stock($ligne));
        $this->assertSame(0, AnnulationExceptionnelle::count());
    }

    public function test_refuse_d_envoyer_un_code_a_un_compte_sans_adresse_email(): void
    {
        ['commande' => $commande] = $this->commandeEncaissee();
        $empreinte = $this->recapitulatif($commande)['empreinte'];
        $sansEmail = $this->makeUserWithPermissions($this->org, ['ventes.read', 'ventes.annuler_exceptionnel']);
        $sansEmail->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->demanderCode($commande, $empreinte, self::MOTIF, $sansEmail)
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
        Mail::assertNothingSent();
    }

    public function test_sans_code_demande_la_confirmation_est_refusee(): void
    {
        ['commande' => $commande] = $this->commandeEncaissee();
        $empreinte = $this->recapitulatif($commande)['empreinte'];

        $this->confirmer($commande, $empreinte)->assertSessionHasErrors('code');

        $this->assertNotEquals(StatutCommandeVente::ANNULEE_ERREUR_SAISIE, $commande->fresh()->statut);
    }

    public function test_le_code_est_verrouille_apres_trop_de_tentatives(): void
    {
        ['commande' => $commande] = $this->commandeEncaissee();
        $empreinte = $this->recapitulatif($commande)['empreinte'];
        $this->demanderCode($commande, $empreinte)->assertOk();

        for ($i = 0; $i < 5; $i++) {
            $this->confirmer($commande, $empreinte, '000000');
        }

        // Même le bon code ne passe plus : il faut en redemander un.
        $this->confirmer($commande, $empreinte)->assertSessionHasErrors('code');
        $this->assertNotEquals(StatutCommandeVente::ANNULEE_ERREUR_SAISIE, $commande->fresh()->statut);
    }

    public function test_le_code_ne_vaut_que_pour_le_motif_confirme(): void
    {
        ['commande' => $commande] = $this->commandeEncaissee();
        $empreinte = $this->recapitulatif($commande)['empreinte'];
        $this->demanderCode($commande, $empreinte)->assertOk();

        $this->confirmer($commande, $empreinte, self::CODE, 'Un tout autre motif que celui confirmé')
            ->assertSessionHasErrors('code');

        $this->assertNotEquals(StatutCommandeVente::ANNULEE_ERREUR_SAISIE, $commande->fresh()->statut);
    }

    public function test_le_code_n_est_jamais_conserve_en_clair_dans_le_cache(): void
    {
        $otp = app(OtpService::class);
        $code = $otp->generate('agent@example.com', OtpPurpose::ANNULATION_EXCEPTIONNELLE, 'commande:X');
        $cle = 'otp:'.md5('agent@example.com|'.OtpPurpose::ANNULATION_EXCEPTIONNELLE->value.'|commande:X');

        $this->assertTrue(Cache::has($cle));
        $this->assertNotSame($code, Cache::get($cle));
        $this->assertTrue($otp->verify('agent@example.com', $code, OtpPurpose::ANNULATION_EXCEPTIONNELLE, 'commande:X'));
        // Usage unique.
        $this->assertFalse($otp->verify('agent@example.com', $code, OtpPurpose::ANNULATION_EXCEPTIONNELLE, 'commande:X'));
    }

    // ── Mode de confirmation (paramètre d'organisation) ──────────────────────

    public function test_par_defaut_le_code_email_est_exige_meme_par_appel_direct(): void
    {
        ['commande' => $commande, 'ligne' => $ligne] = $this->commandeEncaissee();
        $recap = $this->recapitulatif($commande);
        $this->assertSame('email_code', $recap['mode_confirmation']);

        // Sans code (le frontend l'aurait « oublié ») : refusé par le serveur.
        $this->actingAs($this->user)
            ->post(route('ventes.annulation-exceptionnelle.confirmer', $commande), [
                'motif' => self::MOTIF,
                'empreinte' => $recap['empreinte'],
            ])
            ->assertSessionHasErrors('code');

        $this->assertNotEquals(StatutCommandeVente::ANNULEE_ERREUR_SAISIE, $commande->fresh()->statut);
        $this->assertSame(90, $this->stock($ligne));
    }

    public function test_en_mode_simple_la_confirmation_se_fait_directement_sans_code(): void
    {
        Parametre::setModeConfirmationAnnulationExceptionnelle($this->org->id, ModeConfirmationAnnulationExceptionnelle::SIMPLE);
        ['commande' => $commande, 'ligne' => $ligne] = $this->commandeEncaissee();
        $recap = $this->recapitulatif($commande);
        $this->assertSame('simple', $recap['mode_confirmation']);

        $this->actingAs($this->user)
            ->post(route('ventes.annulation-exceptionnelle.confirmer', $commande), [
                'motif' => self::MOTIF,
                'empreinte' => $recap['empreinte'],
            ])
            ->assertRedirect(route('ventes.show', $commande))
            ->assertSessionHasNoErrors();

        $this->assertEquals(StatutCommandeVente::ANNULEE_ERREUR_SAISIE, $commande->fresh()->statut);
        $this->assertSame(100, $this->stock($ligne));
        $this->assertEquals(0.0, $this->soldeCaisse());
        Mail::assertNothingSent();

        $trace = AnnulationExceptionnelle::where('commande_vente_id', $commande->id)->sole();
        $this->assertSame(ModeConfirmationAnnulationExceptionnelle::SIMPLE->value, $trace->methode_confirmation);
        $this->assertNull($trace->code_envoye_a);
        $this->assertNull($trace->code_demande_at);
        $this->assertSame(self::MOTIF, $trace->motif);
    }

    public function test_en_mode_simple_aucun_code_n_est_envoye(): void
    {
        Parametre::setModeConfirmationAnnulationExceptionnelle($this->org->id, ModeConfirmationAnnulationExceptionnelle::SIMPLE);
        ['commande' => $commande] = $this->commandeEncaissee();

        $this->demanderCode($commande, $this->recapitulatif($commande)['empreinte'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('code');
        Mail::assertNothingSent();
    }

    public function test_en_mode_simple_l_empreinte_et_les_refus_restent_obligatoires(): void
    {
        Parametre::setModeConfirmationAnnulationExceptionnelle($this->org->id, ModeConfirmationAnnulationExceptionnelle::SIMPLE);
        ['commande' => $commande] = $this->commandeEncaissee(5000);
        $empreinte = $this->recapitulatif($commande)['empreinte'];

        $this->encaisser($commande, 3000);
        $this->confirmer($commande, $empreinte)->assertSessionHasErrors('empreinte');

        CommissionEnveloppePart::whereIn('enveloppe_id', $commande->commissions()->pluck('id'))->update(['validated_at' => now()]);
        $this->confirmer($commande, $this->recapitulatif($commande)['empreinte'])->assertSessionHasErrors('annulation');

        $this->assertNotEquals(StatutCommandeVente::ANNULEE_ERREUR_SAISIE, $commande->fresh()->statut);
    }

    public function test_repasser_en_mode_code_apres_affichage_exige_le_code(): void
    {
        Parametre::setModeConfirmationAnnulationExceptionnelle($this->org->id, ModeConfirmationAnnulationExceptionnelle::SIMPLE);
        ['commande' => $commande] = $this->commandeEncaissee();
        $empreinte = $this->recapitulatif($commande)['empreinte'];

        // Le réglage change entre l'affichage et la confirmation : le serveur applique le réglage courant.
        Parametre::setModeConfirmationAnnulationExceptionnelle($this->org->id, ModeConfirmationAnnulationExceptionnelle::EMAIL_CODE);

        $this->actingAs($this->user)
            ->post(route('ventes.annulation-exceptionnelle.confirmer', $commande), [
                'motif' => self::MOTIF,
                'empreinte' => $empreinte,
            ])
            ->assertSessionHasErrors('code');
        $this->assertNotEquals(StatutCommandeVente::ANNULEE_ERREUR_SAISIE, $commande->fresh()->statut);
    }

    // ── Données modifiées entre affichage et confirmation ────────────────────

    public function test_refuse_la_demande_de_code_si_les_donnees_ont_change_depuis_le_recapitulatif(): void
    {
        ['commande' => $commande] = $this->commandeEncaissee(5000);
        $empreinte = $this->recapitulatif($commande)['empreinte'];

        $this->encaisser($commande, 3000);

        $this->demanderCode($commande, $empreinte)->assertStatus(422)->assertJsonValidationErrors('empreinte');
        Mail::assertNothingSent();
    }

    public function test_refuse_la_confirmation_si_les_donnees_ont_change_apres_l_envoi_du_code(): void
    {
        ['commande' => $commande, 'ligne' => $ligne] = $this->commandeEncaissee(5000);
        $empreinte = $this->recapitulatif($commande)['empreinte'];
        $this->demanderCode($commande, $empreinte)->assertOk();

        $this->encaisser($commande, 3000);

        $this->confirmer($commande, $empreinte)->assertSessionHasErrors('empreinte');
        $this->assertNotEquals(StatutCommandeVente::ANNULEE_ERREUR_SAISIE, $commande->fresh()->statut);
        $this->assertSame(2, $commande->facture->encaissements()->count());
        $this->assertSame(90, $this->stock($ligne));
    }

    // ── Garde-fous ────────────────────────────────────────────────────────────

    public function test_refuse_si_la_commission_a_deja_ete_validee(): void
    {
        ['commande' => $commande] = $this->commandeEncaissee();
        CommissionEnveloppePart::whereIn('enveloppe_id', $commande->commissions()->pluck('id'))
            ->update(['validated_at' => now()]);

        $recap = $this->recapitulatif($commande);
        $this->assertNotEmpty($recap['blocages']);
        $this->assertStringContainsString('commission', $recap['blocages'][0]);

        $this->demanderCode($commande, $recap['empreinte'])->assertStatus(422)->assertJsonValidationErrors('annulation');
        Mail::assertNothingSent();
    }

    public function test_refuse_si_les_especes_ont_deja_quitte_la_caisse_dediee(): void
    {
        ['commande' => $commande] = $this->commandeEncaissee();

        // Versement simulé : l'argent sort de la caisse dédiée de l'agent.
        app(EcritureComptableService::class)->comptabiliser(
            evenement: EvenementComptable::SOLDE_OUVERTURE_TRESORERIE,
            source: $this->caisse,
            organizationId: $this->org->id,
            dateComptable: Carbon::now(),
            libelle: 'Versement test',
            lignes: [
                ['compte_comptable_id' => $this->caisse->compte_comptable_id, 'sens' => 'credit', 'montant' => 20000],
                ['role' => 'contrepartie_ouverture', 'sens' => 'debit', 'montant' => 20000],
            ],
            siteId: $this->site->id,
            createdBy: $this->user->id,
        );
        $this->assertEquals(0.0, $this->soldeCaisse());

        $recap = $this->recapitulatif($commande);
        $this->assertCount(1, $recap['blocages']);
        $this->assertStringContainsString('ont déjà quitté la caisse', $recap['blocages'][0]);
        $this->demanderCode($commande, $recap['empreinte'])->assertStatus(422);
    }

    public function test_refuse_si_un_retour_de_livraison_a_deja_ete_enregistre(): void
    {
        ['commande' => $commande, 'ligne' => $ligne] = $this->commandeEnLivraison();
        $this->actingAs($this->user)->post(route('ventes.retour.store', $commande), [
            'motif' => 'client_refus',
            'lignes' => [['id' => $ligne->id, 'quantite' => 3]],
        ])->assertSessionHasNoErrors();
        $this->encaisser($commande, 7 * self::PRIX_VENTE);

        $recap = $this->recapitulatif($commande->fresh());
        $this->assertStringContainsString('retour de livraison', implode(' ', $recap['blocages']));
    }

    public function test_une_commande_encore_annulable_normalement_n_est_pas_eligible(): void
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'statut' => StatutCommandeVente::BROUILLON,
        ]);

        $recap = $this->recapitulatif($commande);
        $this->assertStringContainsString('annulée normalement', implode(' ', $recap['blocages']));

        $this->actingAs($this->user)->get(route('ventes.show', $commande))
            ->assertInertia(fn (Assert $page) => $page->where('commande.can_annuler_exceptionnel', false));
    }

    public function test_une_commande_deja_annulee_exceptionnellement_ne_peut_pas_l_etre_a_nouveau(): void
    {
        ['commande' => $commande] = $this->commandeEncaissee();
        $this->annulerExceptionnellement($commande);

        $recap = $this->recapitulatif($commande->fresh());
        $this->assertStringContainsString('déjà annulée', implode(' ', $recap['blocages']));
    }

    // ── Cashback ──────────────────────────────────────────────────────────────

    public function test_le_cashback_en_attente_est_retire_et_le_solde_client_restaure(): void
    {
        ['commande' => $commande] = $this->commandeEncaissee();
        $client = Client::factory()->create(['organization_id' => $this->org->id]);
        $commande->update(['client_id' => $client->id]);
        $gain = CashbackTransaction::create([
            'organization_id' => $this->org->id,
            'client_id' => $client->id,
            'type' => CashbackTransaction::TYPE_GAIN,
            'montant' => 500,
            'montant_verse' => 0,
            'statut' => CashbackTransaction::STATUT_EN_ATTENTE,
            'vente_id' => $commande->id,
        ]);
        $solde = CashbackSolde::create([
            'organization_id' => $this->org->id,
            'client_id' => $client->id,
            'cumul_achats' => 25000,
            'cashback_en_attente' => 800,
            'total_cashback_gagne' => 800,
            'total_cashback_verse' => 0,
        ]);

        $this->annulerExceptionnellement($commande->fresh());

        $this->assertNull(CashbackTransaction::find($gain->id));
        $solde->refresh();
        $this->assertSame(300, (int) $solde->cashback_en_attente);
        $this->assertSame(300, (int) $solde->total_cashback_gagne);
        $this->assertSame(5000, (int) $solde->cumul_achats);
    }

    public function test_refuse_si_le_cashback_a_deja_ete_valide(): void
    {
        ['commande' => $commande] = $this->commandeEncaissee();
        $client = Client::factory()->create(['organization_id' => $this->org->id]);
        CashbackTransaction::create([
            'organization_id' => $this->org->id,
            'client_id' => $client->id,
            'type' => CashbackTransaction::TYPE_GAIN,
            'montant' => 500,
            'montant_verse' => 0,
            'statut' => CashbackTransaction::STATUT_VALIDE,
            'vente_id' => $commande->id,
        ]);

        $recap = $this->recapitulatif($commande);
        $this->assertStringContainsString('cashback', implode(' ', $recap['blocages']));
    }

    // ── Autorisations et isolation ────────────────────────────────────────────

    public function test_sans_la_permission_toutes_les_routes_sont_refusees_et_le_bouton_masque(): void
    {
        ['commande' => $commande] = $this->commandeEncaissee();
        $empreinte = $this->recapitulatif($commande)['empreinte'];
        $sansPermission = $this->makeUserWithPermissions($this->org, ['ventes.read', 'ventes.update']);
        $sansPermission->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $this->actingAs($sansPermission)->getJson(route('ventes.annulation-exceptionnelle.show', $commande))->assertForbidden();
        $this->demanderCode($commande, $empreinte, self::MOTIF, $sansPermission)->assertForbidden();
        $this->confirmer($commande, $empreinte, self::CODE, self::MOTIF, $sansPermission)->assertForbidden();

        $this->actingAs($sansPermission)->get(route('ventes.show', $commande))
            ->assertInertia(fn (Assert $page) => $page->where('commande.can_annuler_exceptionnel', false));
        $this->actingAs($this->user)->get(route('ventes.show', $commande))
            ->assertInertia(fn (Assert $page) => $page->where('commande.can_annuler_exceptionnel', true));
    }

    public function test_un_utilisateur_d_une_autre_organisation_est_refuse_meme_super_admin(): void
    {
        ['commande' => $commande] = $this->commandeEncaissee();
        $empreinte = $this->recapitulatif($commande)['empreinte'];

        $autreOrg = Organization::factory()->create();
        $intrus = $this->makeUserWithPermissions($autreOrg, ['ventes.read', 'ventes.annuler_exceptionnel']);
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $intrus->assignRole('super_admin');
        $this->attachDefaultSite($autreOrg, $intrus);

        $this->actingAs($intrus)->getJson(route('ventes.annulation-exceptionnelle.show', $commande))->assertForbidden();
        $this->demanderCode($commande, $empreinte, self::MOTIF, $intrus)->assertForbidden();
        $this->confirmer($commande, $empreinte, self::CODE, self::MOTIF, $intrus)->assertForbidden();

        $this->assertNotEquals(StatutCommandeVente::ANNULEE_ERREUR_SAISIE, $commande->fresh()->statut);
        Mail::assertNothingSent();
    }

    public function test_la_migration_accorde_la_permission_au_seul_super_admin(): void
    {
        $superAdmin = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $admin = Role::firstOrCreate(['name' => 'admin_entreprise', 'guard_name' => 'web']);
        $superAdmin->revokePermissionTo(Permission::firstOrCreate(['name' => 'ventes.annuler_exceptionnel', 'guard_name' => 'web']));

        $migration = require database_path('migrations/2026_09_24_100100_backfill_ventes_annuler_exceptionnel_permission.php');
        $migration->up();
        $migration->up();

        $this->assertTrue($superAdmin->fresh()->hasPermissionTo('ventes.annuler_exceptionnel'));
        $this->assertFalse($admin->fresh()->hasPermissionTo('ventes.annuler_exceptionnel'));
        $this->assertSame(1, Permission::where('name', 'ventes.annuler_exceptionnel')->count());
    }
}
