<?php

namespace Tests\Feature\Tresorerie;

use App\Enums\EvenementComptable;
use App\Enums\StatutSupportTresorerie;
use App\Models\CommandeVente;
use App\Models\CompteComptable;
use App\Models\CompteMapping;
use App\Models\CompteTresorerie;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\MouvementFonds;
use App\Models\Organization;
use App\Models\PieceComptable;
use App\Models\Site;
use App\Models\User;
use App\Services\Tresorerie\CaisseAgentResolver;
use App\Services\Tresorerie\CaisseAgentService;
use App\Services\Tresorerie\MouvementFondsService;
use App\Services\Tresorerie\MoyensEncaissementResolver;
use App\Services\Tresorerie\SoldeOuvertureTresorerieService;
use App\Services\Tresorerie\SupportTresorerieValidationService;
use App\Services\Tresorerie\TresorerieDisponibiliteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Lot 1 de l'ADR 0027 — compte Mobile Money dédié à un agent : un par (agent, agence, opérateur),
 * numéro de portefeuille unique dans l'organisation, sous-compte propre sous le compte de
 * l'opérateur, créé à 0 sans écriture. Et surtout son ÉTANCHÉITÉ : ce compte n'est jamais traité
 * comme une caisse en espèces (routage des espèces, versement, approvisionnement, disponible), et
 * le circuit d'encaissement Mobile Money actuel ne le connaît pas encore.
 */
class CompteMobileMoneyAgentTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, RefreshDatabase;

    private CaisseAgentService $service;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['tresorerie.gerer_soldes_ouverture', 'tresorerie.read']);
        $this->service = app(CaisseAgentService::class);
        $this->site = $this->user->sites()->first();
    }

    private function compte(string $numero, ?string $organizationId = null): CompteComptable
    {
        return CompteComptable::where('organization_id', $organizationId ?? $this->org->id)->where('numero', $numero)->firstOrFail();
    }

    private function autreSite(string $nom = 'Kouria'): Site
    {
        return Site::create(['organization_id' => $this->org->id, 'nom' => $nom, 'type' => 'agence', 'localisation' => 'Coyah']);
    }

    private function sousComptesMobileMoney(): int
    {
        return CompteComptable::where('organization_id', $this->org->id)->whereRaw('LENGTH(numero) = 9')->where('numero', 'like', '561%')->count();
    }

    // ── Création ─────────────────────────────────────────────────────────────

    public function test_creer_cree_un_compte_en_brouillon_avec_son_sous_compte_et_sans_ecriture(): void
    {
        $agent = $this->creerAgent($this->site);

        $compte = $this->service->creerCompteMobileMoney($this->org->id, $this->site->id, $agent->id, 'orange_money', ' 620 00 00 01 ');

        $this->assertTrue($compte->isMobileMoneyDedie());
        $this->assertFalse($compte->isCaisseDediee());
        $this->assertSame($agent->id, $compte->agent_id);
        $this->assertSame('mobile_money', $compte->type->value);
        $this->assertSame('orange_money', $compte->operateur_mobile_money->value);
        $this->assertSame('620 00 00 01', $compte->numero);
        $this->assertSame('mobile_money:orange', $compte->moyen_paiement_defaut);
        $this->assertSame("Orange Money — {$agent->name}", $compte->libelle);
        $this->assertFalse($compte->actif, 'créé en brouillon : inutilisable jusqu\'à sa validation');
        $this->assertSame(StatutSupportTresorerie::BROUILLON, $compte->statut());

        $sousCompte = $compte->compte;
        $this->assertSame('561100001', $sousCompte->numero);
        $this->assertSame($this->compte('561100')->id, $sousCompte->parent_id);
        $this->assertSame($this->org->id, $sousCompte->organization_id);
        $this->assertStringContainsString($agent->name, $sousCompte->libelle);

        $this->assertSame(0, PieceComptable::where('organization_id', $this->org->id)->count(), 'aucune écriture à la création');
        $this->assertNull($compte->soldeOuverture);
        $this->assertSame(0.0, app(TresorerieDisponibiliteService::class)->soldePourSupport($compte));
    }

    public function test_les_sous_comptes_sont_numerotes_a_la_suite_du_compte_de_chaque_operateur(): void
    {
        $premier = $this->service->creerCompteMobileMoney($this->org->id, $this->site->id, $this->creerAgent($this->site, 'Aïssata', 'Barry')->id, 'orange_money', '620000001');
        $second = $this->service->creerCompteMobileMoney($this->org->id, $this->site->id, $this->creerAgent($this->site, 'Abdoulaye', 'Diallo')->id, 'orange_money', '620000002');
        $momo = $this->service->creerCompteMobileMoney($this->org->id, $this->site->id, $premier->agent_id, 'momo', '660000001');

        $this->assertSame('561100001', $premier->compte->numero);
        $this->assertSame('561100002', $second->compte->numero);
        $this->assertSame('561200001', $momo->compte->numero);
        $this->assertSame($this->compte('561200')->id, $momo->compte->parent_id);
    }

    public function test_deux_organisations_ont_leur_numerotation_et_peuvent_enregistrer_le_meme_numero(): void
    {
        $autreOrg = Organization::factory()->create();
        $autreSite = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'Autre agence', 'type' => 'agence', 'localisation' => 'Kindia']);
        $autreAgent = User::factory()->create(['organization_id' => $autreOrg->id]);
        $autreAgent->sites()->attach($autreSite->id, ['role' => 'employe', 'is_default' => true]);

        $chezNous = $this->service->creerCompteMobileMoney($this->org->id, $this->site->id, $this->creerAgent($this->site)->id, 'orange_money', '620000001');
        $chezLAutre = $this->service->creerCompteMobileMoney($autreOrg->id, $autreSite->id, $autreAgent->id, 'orange_money', '620000001');

        $this->assertSame('561100001', $chezNous->compte->numero);
        $this->assertSame('561100001', $chezLAutre->compte->numero);
        $this->assertSame($autreOrg->id, $chezLAutre->compte->organization_id);
        $this->assertSame($this->compte('561100', $autreOrg->id)->id, $chezLAutre->compte->parent_id);
    }

    public function test_creer_refuse_un_agent_non_rattache_desactive_ou_d_une_autre_organisation(): void
    {
        $nonRattache = $this->creerAgent($this->autreSite());
        $desactive = $this->creerAgent($this->site, 'Fatou', 'Camara');
        $desactive->update(['is_active' => false]);
        $etranger = User::factory()->create(['organization_id' => Organization::factory()->create()->id]);

        foreach ([$nonRattache, $desactive, $etranger] as $agent) {
            $this->assertErreurValidationSur('agent_id', fn () => $this->service->creerCompteMobileMoney($this->org->id, $this->site->id, $agent->id, 'orange_money', '620000001'));
        }

        $this->assertSame(0, CompteTresorerie::dediees()->count());
        $this->assertSame(0, $this->sousComptesMobileMoney(), 'aucun sous-compte orphelin');
    }

    public function test_creer_refuse_un_operateur_inconnu_sans_portefeuille_ou_sans_compte_parametre(): void
    {
        $agent = $this->creerAgent($this->site);
        CompteMapping::where('organization_id', $this->org->id)->where('moyen_paiement', 'mobile_money:kulu')->delete();

        foreach (['autre', 'inconnu', 'kulu'] as $operateur) {
            $this->assertErreurValidationSur('operateur_mobile_money', fn () => $this->service->creerCompteMobileMoney($this->org->id, $this->site->id, $agent->id, $operateur, '620000001'));
        }

        $this->assertSame(0, CompteTresorerie::dediees()->count());
    }

    public function test_creer_refuse_un_numero_vide(): void
    {
        $agent = $this->creerAgent($this->site);

        foreach ([null, '', '   ', ' - '] as $numero) {
            $this->assertErreurValidationSur('numero', fn () => $this->service->creerCompteMobileMoney($this->org->id, $this->site->id, $agent->id, 'orange_money', $numero));
        }

        $this->assertSame(0, $this->sousComptesMobileMoney());
    }

    // ── Unicités ─────────────────────────────────────────────────────────────

    public function test_un_seul_compte_actif_par_agent_agence_et_operateur(): void
    {
        $agent = $this->creerAgent($this->site);
        $kouria = $this->autreSite();
        $agent->sites()->attach($kouria->id, ['role' => 'employe', 'is_default' => false]);
        $this->creerCompteMobileMoneyActif($this->site->id, $agent->id, 'orange_money', '620000001');

        $this->assertErreurValidationSur('agent_id', fn () => $this->service->creerCompteMobileMoney($this->org->id, $this->site->id, $agent->id, 'orange_money', '620000002'));

        // Un autre opérateur, ou une autre agence, restent possibles.
        $momo = $this->service->creerCompteMobileMoney($this->org->id, $this->site->id, $agent->id, 'momo', '660000001');
        $ailleurs = $this->service->creerCompteMobileMoney($this->org->id, $kouria->id, $agent->id, 'orange_money', '620000003');

        $this->assertSame('momo', $momo->operateur_mobile_money->value);
        $this->assertSame($kouria->id, $ailleurs->site_id);
    }

    public function test_un_numero_ne_porte_qu_un_seul_compte_du_meme_operateur_dans_l_organisation(): void
    {
        $premier = $this->creerAgent($this->site);
        $second = $this->creerAgent($this->autreSite(), 'Ibrahima', 'Bah');
        $brouillon = $this->service->creerCompteMobileMoney($this->org->id, $this->site->id, $premier->id, 'orange_money', '620 00 00 01');

        // Même portefeuille écrit autrement, dans une autre agence : un brouillon le réserve déjà.
        $this->assertErreurValidationSur('numero', fn () => $this->service->creerCompteMobileMoney($this->org->id, $second->sites()->first()->id, $second->id, 'orange_money', '620-000-001'));

        // Le même numéro chez un autre opérateur est un autre portefeuille.
        $momo = $this->service->creerCompteMobileMoney($this->org->id, $second->sites()->first()->id, $second->id, 'momo', '620000001');
        $this->assertSame('620000001', $momo->numero);

        // Désactivé, le compte libère son numéro.
        $actif = app(SupportTresorerieValidationService::class)->valider($brouillon, $this->user);
        $this->service->mettreAJour($actif, ['libelle' => $actif->libelle, 'actif' => false]);
        $repris = $this->service->creerCompteMobileMoney($this->org->id, $second->sites()->first()->id, $second->id, 'orange_money', '620000001');
        $this->assertSame('561100002', $repris->compte->numero);
    }

    public function test_un_numero_deja_porte_par_un_support_de_l_agence_est_refuse(): void
    {
        $this->creerSupportAgence($this->site->id, 'mobile_money', '561100', 'orange_money')->update(['numero' => '620000001']);

        $this->assertErreurValidationSur('numero', fn () => $this->service->creerCompteMobileMoney($this->org->id, $this->site->id, $this->creerAgent($this->site)->id, 'orange_money', '620 000 001'));
    }

    public function test_le_meme_portefeuille_est_reconnu_avec_ou_sans_indicatif(): void
    {
        $this->service->creerCompteMobileMoney($this->org->id, $this->site->id, $this->creerAgent($this->site)->id, 'orange_money', '620 00 00 01');
        $this->creerSupportAgence($this->site->id, 'mobile_money', '561100', 'orange_money')->update(['numero' => '+224 620 00 00 05']);
        $autre = $this->creerAgent($this->site, 'Fatou', 'Camara');

        foreach (['+224 620 00 00 01', '224620000001', '00224 620 000 001', '620000005'] as $numero) {
            $this->assertErreurValidationSur('numero', fn () => $this->service->creerCompteMobileMoney($this->org->id, $this->site->id, $autre->id, 'orange_money', $numero));
        }

        // Un autre numéro, indicatif compris, reste libre.
        $libre = $this->service->creerCompteMobileMoney($this->org->id, $this->site->id, $autre->id, 'orange_money', '+224 620 00 00 02');
        $this->assertSame('+224 620 00 00 02', $libre->numero, 'le numéro est conservé tel que saisi');
    }

    public function test_la_validation_reverifie_l_agent_et_les_unicites(): void
    {
        $validation = app(SupportTresorerieValidationService::class);
        $agent = $this->creerAgent($this->site);
        $premier = $this->service->creerCompteMobileMoney($this->org->id, $this->site->id, $agent->id, 'orange_money', '620000001');
        $doublon = $this->service->creerCompteMobileMoney($this->org->id, $this->site->id, $agent->id, 'orange_money', '620000002');

        $actif = $validation->valider($premier, $this->user);
        $this->assertTrue($actif->actif);
        $this->assertSame(StatutSupportTresorerie::ACTIF, $actif->statut());

        $this->assertErreurValidationSur('statut', fn () => $validation->valider($doublon, $this->user));

        $autre = $this->creerAgent($this->site, 'Fatou', 'Camara');
        $brouillon = $this->service->creerCompteMobileMoney($this->org->id, $this->site->id, $autre->id, 'orange_money', '620000009');
        $autre->update(['is_active' => false]);
        $this->assertErreurValidationSur('statut', fn () => $validation->valider($brouillon, $this->user));
        $this->assertFalse($brouillon->fresh()->actif);
    }

    // ── Modification ─────────────────────────────────────────────────────────

    public function test_le_numero_se_corrige_en_brouillon_et_plus_apres_la_validation(): void
    {
        $agent = $this->creerAgent($this->site);
        $autre = $this->creerAgent($this->site, 'Fatou', 'Camara');
        $this->service->creerCompteMobileMoney($this->org->id, $this->site->id, $autre->id, 'orange_money', '620000009');
        $brouillon = $this->service->creerCompteMobileMoney($this->org->id, $this->site->id, $agent->id, 'orange_money', '620000001');

        $corrige = $this->service->mettreAJour($brouillon, ['libelle' => 'Orange Moussa', 'actif' => false, 'numero' => '620000002']);
        $this->assertSame('620000002', $corrige->numero);
        $this->assertSame('Orange Moussa', $corrige->libelle);

        $this->assertErreurValidationSur('numero', fn () => $this->service->mettreAJour($corrige, ['libelle' => 'Orange Moussa', 'actif' => false, 'numero' => '620 000 009']));
        $this->assertErreurValidationSur('numero', fn () => $this->service->mettreAJour($corrige, ['libelle' => 'Orange Moussa', 'actif' => false, 'numero' => '']));

        $actif = app(SupportTresorerieValidationService::class)->valider($corrige, $this->user);
        $this->assertErreurValidationSur('numero', fn () => $this->service->mettreAJour($actif, ['libelle' => 'Orange Moussa', 'actif' => true, 'numero' => '620000003']));

        // Renvoyer le même numéro, écrit autrement, n'est pas une modification.
        $inchange = $this->service->mettreAJour($actif, ['libelle' => 'Orange Moussa', 'actif' => true, 'numero' => '620 000 002']);
        $this->assertSame('620000002', $inchange->numero);
    }

    public function test_un_compte_ne_se_desactive_que_vide_et_ne_se_reactive_que_si_son_numero_est_libre(): void
    {
        $compte = $this->creerCompteMobileMoneyActif($this->site->id, $this->creerAgent($this->site)->id, 'orange_money', '620000001');
        $piece = $this->alimenterCaisse($compte, 150_000);

        $this->assertErreurValidationSur('actif', fn () => $this->service->mettreAJour($compte, ['libelle' => $compte->libelle, 'actif' => false]));

        $this->viderCaisse($piece);
        $inactif = $this->service->mettreAJour($compte, ['libelle' => $compte->libelle, 'actif' => false]);
        $this->assertSame(StatutSupportTresorerie::INACTIF, $inactif->statut());

        $this->service->creerCompteMobileMoney($this->org->id, $this->site->id, $this->creerAgent($this->site, 'Fatou', 'Camara')->id, 'orange_money', '620000001');
        $this->assertErreurValidationSur('actif', fn () => $this->service->mettreAJour($inactif, ['libelle' => $inactif->libelle, 'actif' => true]));
    }

    // ── Coexistence avec la caisse en espèces ────────────────────────────────

    public function test_un_agent_peut_avoir_une_caisse_et_un_compte_mobile_money_dans_la_meme_agence(): void
    {
        $agent = $this->creerAgent($this->site);
        $autre = $this->creerAgent($this->site, 'Fatou', 'Camara');

        $caisse = $this->creerCaisseActive($this->site->id, $agent->id);
        $compte = $this->creerCompteMobileMoneyActif($this->site->id, $agent->id, 'orange_money', '620000001');

        // Dans l'autre ordre aussi : le compte Mobile Money ne compte pas pour une caisse.
        $this->creerCompteMobileMoneyActif($this->site->id, $autre->id, 'orange_money', '620000002');
        $caisseAutre = $this->creerCaisseActive($this->site->id, $autre->id);

        $this->assertTrue($caisse->fresh()->actif);
        $this->assertTrue($compte->fresh()->actif);
        $this->assertSame('571001', $caisse->compte->numero);
        $this->assertSame('571002', $caisseAutre->compte->numero);
        $this->assertSame('561100001', $compte->compte->numero);
    }

    public function test_la_caisse_de_l_agent_reste_sa_caisse_en_especes_jamais_son_compte_mobile_money(): void
    {
        $resolver = app(CaisseAgentResolver::class);
        $equipe = $this->creerAgent($this->site);
        $sansCaisse = $this->creerAgent($this->site, 'Fatou', 'Camara');
        $this->creerCompteMobileMoneyActif($this->site->id, $equipe->id, 'orange_money', '620000001');
        $caisse = $this->creerCaisseActive($this->site->id, $equipe->id);
        $this->creerCompteMobileMoneyActif($this->site->id, $sansCaisse->id, 'orange_money', '620000002');

        $this->assertSame($caisse->id, $resolver->caisseActive($this->org->id, $equipe->id, $this->site->id)?->id);
        $this->assertSame([$this->site->id], $resolver->sitesAvecCaisseActive($this->org->id, $equipe->id));

        $this->assertNull($resolver->caisseActive($this->org->id, $sansCaisse->id, $this->site->id));
        $this->assertSame([], $resolver->sitesAvecCaisseActive($this->org->id, $sansCaisse->id));
    }

    private function facture(float $montant = 5_000): FactureVente
    {
        $commande = CommandeVente::factory()->create([
            'organization_id' => $this->org->id,
            'site_id' => $this->site->id,
            'statut' => 'livree',
            'total_commande' => $montant,
        ]);

        return FactureVente::factory()->create([
            'organization_id' => $this->org->id,
            'commande_vente_id' => $commande->id,
            'site_id' => $this->site->id,
            'montant_net' => $montant,
        ]);
    }

    private function encaisseur(): User
    {
        $encaisseur = $this->makeUserWithPermissions($this->org, ['ventes.update', 'factures.encaisser']);
        $encaisseur->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        return $encaisseur;
    }

    public function test_les_especes_d_un_agent_qui_a_aussi_un_compte_mobile_money_vont_dans_sa_caisse(): void
    {
        $disponibilite = app(TresorerieDisponibiliteService::class);
        $encaisseur = $this->encaisseur();
        // Compte Mobile Money créé AVANT la caisse : l'ordre de création ne doit rien changer.
        $mobileMoney = $this->creerCompteMobileMoneyActif($this->site->id, $encaisseur->id, 'orange_money', '620000001');
        $caisse = $this->creerCaisseActive($this->site->id, $encaisseur->id);
        $facture = $this->facture();

        $this->actingAs($encaisseur)
            ->post(route('encaissements.store', $facture), [
                'montant' => 5_000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ])
            ->assertSessionHasNoErrors();

        $encaissement = EncaissementVente::where('facture_vente_id', $facture->id)->firstOrFail();
        $piece = PieceComptable::where('source_id', $encaissement->id)
            ->where('type_evenement', EvenementComptable::ENCAISSEMENT_VENTE_RECU->value)
            ->firstOrFail();
        $debit = $piece->lignes()->with('compte')->where('debit', '>', 0)->firstOrFail();

        $this->assertSame($caisse->compte->numero, $debit->compte->numero);
        $this->assertSame(5_000.0, $disponibilite->soldePourSupport($caisse));
        $this->assertSame(0.0, $disponibilite->soldePourSupport($mobileMoney));
    }

    public function test_un_compte_mobile_money_ne_permet_pas_d_encaisser_en_especes(): void
    {
        $encaisseur = $this->encaisseur();
        $this->creerCompteMobileMoneyActif($this->site->id, $encaisseur->id, 'orange_money', '620000001');
        $facture = $this->facture();

        $this->actingAs($encaisseur)
            ->post(route('encaissements.store', $facture), [
                'montant' => 5_000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'especes',
            ])
            ->assertSessionHasErrors(['mode_paiement' => CaisseAgentResolver::MESSAGE_SANS_CAISSE]);

        $this->assertSame(0, EncaissementVente::count());
    }

    // ── Le circuit d'encaissement Mobile Money actuel est inchangé ───────────

    public function test_le_compte_d_un_agent_n_est_propose_a_aucun_encaissement_et_ne_remplace_pas_celui_de_l_agence(): void
    {
        $moyens = app(MoyensEncaissementResolver::class);
        $encaisseur = $this->encaisseur();
        $compteAgent = $this->creerCompteMobileMoneyActif($this->site->id, $encaisseur->id, 'orange_money', '620000001');

        $this->assertSame([], $moyens->pourSite($this->org->id, $this->site->id), 'sans support d\'agence, aucun moyen Mobile Money');

        $compteAgence = $this->creerSupportAgence($this->site->id, 'mobile_money', '561100', 'orange_money');
        $proposes = array_column($moyens->pourSite($this->org->id, $this->site->id), 'compte_tresorerie_id');

        $this->assertSame([$compteAgence->id], $proposes);
        $this->assertNull($moyens->supportPour($this->org->id, $this->site->id, $compteAgent->id, 'mobile_money'));

        // Un encaissement Mobile Money continue d'arriver sur le compte de l'agence.
        $facture = $this->facture();
        $this->actingAs($encaisseur)
            ->post(route('encaissements.store', $facture), [
                'montant' => 5_000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'mobile_money',
                'compte_tresorerie_id' => $compteAgence->id,
                'reference_paiement' => 'OM-REF-001',
            ])
            ->assertSessionHasNoErrors();

        $disponibilite = app(TresorerieDisponibiliteService::class);
        $this->assertSame(5_000.0, $disponibilite->soldePourSupport($compteAgence));
        $this->assertSame(0.0, $disponibilite->soldePourSupport($compteAgent));

        // Et le compte d'un agent, envoyé de force, est refusé.
        $this->actingAs($encaisseur)
            ->post(route('encaissements.store', $this->facture()), [
                'montant' => 5_000,
                'date_encaissement' => now()->toDateString(),
                'mode_paiement' => 'mobile_money',
                'compte_tresorerie_id' => $compteAgent->id,
                'reference_paiement' => 'OM-REF-002',
            ])
            ->assertSessionHasErrors('compte_tresorerie_id');
    }

    // ── Étanchéité : jamais une caisse en espèces ────────────────────────────

    public function test_un_compte_mobile_money_d_agent_ne_se_verse_pas_et_ne_s_approvisionne_pas_comme_une_caisse(): void
    {
        $mouvements = app(MouvementFondsService::class);
        $agent = $this->creerAgent($this->site);
        $compte = $this->creerCompteMobileMoneyActif($this->site->id, $agent->id, 'orange_money', '620000001');
        $this->alimenterCaisse($compte, 200_000);
        $caisseAgence = $this->creerSupportAgence($this->site->id, 'caisse', '571000', null, 'Caisse agence');
        $banque = $this->creerSupportAgence($this->site->id, 'banque', '521000', null, 'Banque agence');
        $this->alimenterCaisse($caisseAgence, 500_000);

        $this->assertErreurValidationSur('compte_tresorerie_id', fn () => $mouvements->verserCaisseAgent($this->org->id, $compte, $caisseAgence->id, 100_000, null, $this->user->id));
        $this->assertErreurValidationSur('compte_tresorerie_destination_id', fn () => $mouvements->approvisionnerCaisseAgent($this->org->id, $caisseAgence, $compte->id, 100_000, null, $this->user->id));
        $this->assertErreurValidationSur('compte_tresorerie_id', fn () => $mouvements->deposerEnBanque($this->org->id, $compte, $banque->id, 100_000, null, $this->user->id));

        $kouria = $this->autreSite();
        $this->assertErreurValidationSur('compte_tresorerie_origine_id', fn () => $mouvements->creerBrouillon($this->org->id, [
            'site_origine_id' => $this->site->id,
            'site_destination_id' => $kouria->id,
            'compte_tresorerie_origine_id' => $compte->id,
            'montant' => 100_000,
        ], $this->user->id));

        $this->assertSame(0, MouvementFonds::count());
        $this->assertSame(200_000.0, app(TresorerieDisponibiliteService::class)->soldePourSupport($compte));
    }

    public function test_le_titulaire_d_un_compte_mobile_money_n_a_pas_le_bouton_verser(): void
    {
        $titulaire = $this->creerUtilisateurNonAdmin($this->site, ['tresorerie.verser', 'tresorerie.envoyer']);
        $compte = $this->creerCompteMobileMoneyActif($this->site->id, $titulaire->id, 'orange_money', '620000001');
        $caisse = $this->creerCaisseActive($this->site->id, $titulaire->id);

        $this->assertFalse($titulaire->can('verser', $compte));
        $this->assertTrue($titulaire->can('verser', $caisse));
        $this->assertFalse($titulaire->can('approvisionner', $compte));
        $this->assertFalse($titulaire->can('deposerEnBanque', $compte));
    }

    public function test_seules_les_caisses_en_especes_sont_proposees_a_l_approvisionnement(): void
    {
        $agent = $this->creerAgent($this->site);
        $caisse = $this->creerCaisseActive($this->site->id, $agent->id);
        $this->creerCompteMobileMoneyActif($this->site->id, $agent->id, 'orange_money', '620000001');

        $this->assertSame([$caisse->id], CompteTresorerie::forOrg($this->org->id)->approvisionnablesPar($this->user)->pluck('id')->all());
    }

    public function test_un_solde_d_ouverture_est_refuse(): void
    {
        $compte = $this->creerCompteMobileMoneyActif($this->site->id, $this->creerAgent($this->site)->id, 'orange_money', '620000001');

        $this->assertErreurValidationSur('compte_tresorerie_id', fn () => app(SoldeOuvertureTresorerieService::class)
            ->enregistrer($this->org->id, $compte, ['date_situation' => '2026-08-01', 'montant' => 100_000], $this->user->id));
    }

    public function test_l_argent_du_compte_est_dans_la_situation_mais_pas_dans_le_disponible_de_l_agence(): void
    {
        $disponibilite = app(TresorerieDisponibiliteService::class);
        $compteAgence = $this->creerSupportAgence($this->site->id, 'mobile_money', '561100', 'orange_money');
        $this->alimenterCaisse($compteAgence, 1_000_000);
        $compteAgent = $this->creerCompteMobileMoneyActif($this->site->id, $this->creerAgent($this->site)->id, 'orange_money', '620000001');
        $this->alimenterCaisse($compteAgent, 250_000);

        $this->assertSame(1_000_000.0, $disponibilite->disponiblePourSite($this->org->id, $this->site->id, Carbon::now()));

        $situation = $disponibilite->situationParSupport($this->org->id, Carbon::now())->keyBy('compte_tresorerie_id');
        $this->assertSame(1_000_000.0, $situation[$compteAgence->id]['solde'], 'le sous-compte de l\'agent ne se mélange pas au compte de l\'agence');
        $this->assertSame(250_000.0, $situation[$compteAgent->id]['solde']);
        $this->assertSame('mobile_money', $situation[$compteAgent->id]['type']);
        $this->assertSame($compteAgent->agent_id, $situation[$compteAgent->id]['agent_id']);
    }

    // ── Suppression de l'utilisateur ─────────────────────────────────────────

    public function test_la_suppression_du_titulaire_d_un_compte_actif_ou_en_brouillon_est_refusee(): void
    {
        Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $superAdmin = User::factory()->create(['organization_id' => $this->org->id]);
        $superAdmin->assignRole('super_admin');
        $superAdmin->sites()->attach($this->site->id, ['role' => 'employe', 'is_default' => true]);

        $titulaire = $this->creerAgent($this->site);
        $compte = $this->creerCompteMobileMoneyActif($this->site->id, $titulaire->id, 'orange_money', '620000001');
        $enBrouillon = $this->creerAgent($this->site, 'Fatou', 'Camara');
        $this->service->creerCompteMobileMoney($this->org->id, $this->site->id, $enBrouillon->id, 'orange_money', '620000002');

        foreach ([$titulaire, $enBrouillon] as $agent) {
            $this->actingAs($superAdmin)
                ->delete(route('users.destroy', $agent))
                ->assertRedirect(route('users.index'))
                ->assertSessionHas('error');
            $this->assertDatabaseHas('users', ['id' => $agent->id]);
        }

        $this->assertSame($titulaire->id, $compte->fresh()->agent_id);
    }
}
