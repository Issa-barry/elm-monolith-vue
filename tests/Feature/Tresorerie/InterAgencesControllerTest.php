<?php

namespace Tests\Feature\Tresorerie;

use App\Enums\AuditEvent;
use App\Enums\NatureMouvementFonds;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutMouvementFonds;
use App\Models\CommandeVente;
use App\Models\CompteTresorerie;
use App\Models\EncaissementVente;
use App\Models\FactureVente;
use App\Models\MouvementFonds;
use App\Models\MouvementFondsEncaissement;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\Tresorerie\DetteInterAgencesService;
use App\Services\Tresorerie\MouvementFondsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasCaissesDediees;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Trésorerie → Inter-agences (ADR 0012, lot 2) : écrans « À verser / À recevoir » et détail, et
 * « Régler » (création + envoi en une transaction). L'autorisation est entièrement serveur
 * (`MouvementFondsPolicy::regler`) ; le montant n'est jamais reçu du navigateur ; la dette vient
 * toujours des services du lot 1.
 */
class InterAgencesControllerTest extends TestCase
{
    use HasAdminSetup, HasCaissesDediees, HasOrgAndUser, RefreshDatabase;

    private const PERMISSIONS_REGLEMENT = ['tresorerie.read', 'tresorerie.create', 'tresorerie.envoyer', 'tresorerie.recevoir'];

    private Site $agenceA;

    private Site $agenceB;

    private CompteTresorerie $orangeB;

    private CompteTresorerie $caisseA;

    private CompteTresorerie $caisseB;

    private User $agentB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser([...self::PERMISSIONS_REGLEMENT, 'ventes.read']);
        $this->agenceA = $this->user->sites()->firstOrFail();
        $this->agenceB = $this->creerSite('Agence Kindia');

        $this->orangeB = $this->creerSupportAgence($this->agenceB->id, 'mobile_money', '561100', 'orange_money', 'Orange Money de Kindia');
        $this->caisseA = $this->creerSupportAgence($this->agenceA->id, 'caisse', '571000', null, 'Caisse Matoto');
        $this->caisseB = $this->creerSupportAgence($this->agenceB->id, 'caisse', '571000', null, 'Caisse Kindia');

        $this->agentB = $this->creerUtilisateurNonAdmin($this->agenceB, self::PERMISSIONS_REGLEMENT, 'Mariama', 'Kindia');
    }

    private function creerSite(string $nom, ?Organization $org = null): Site
    {
        return Site::create([
            'organization_id' => ($org ?? $this->org)->id,
            'nom' => $nom,
            'type' => 'depot',
            'localisation' => $nom,
        ]);
    }

    /** Encaissement Mobile Money d'une commande de `$agenceCommande`, reçu par l'agence du support. */
    private function encaissement(float $montant, ?Site $agenceCommande = null, ?CompteTresorerie $support = null): EncaissementVente
    {
        $agenceCommande ??= $this->agenceA;
        $support ??= $this->orangeB;

        $commande = CommandeVente::factory()->create([
            'organization_id' => $agenceCommande->organization_id,
            'site_id' => $agenceCommande->id,
            'statut' => StatutCommandeVente::LIVREE,
            'total_commande' => $montant,
        ]);
        $facture = FactureVente::factory()->create([
            'organization_id' => $agenceCommande->organization_id,
            'commande_vente_id' => $commande->id,
            'site_id' => $agenceCommande->id,
            'montant_net' => $montant,
        ]);

        return EncaissementVente::create([
            'facture_vente_id' => $facture->id,
            'site_encaissement_id' => $support->site_id,
            'montant' => $montant,
            'date_encaissement' => now()->toDateString(),
            'mode_paiement' => 'mobile_money',
            'operateur_mobile_money' => 'orange_money',
            'compte_tresorerie_id' => $support->id,
            'reference_paiement' => 'OM-'.uniqid(),
            'created_by' => $this->agentB->id,
        ]);
    }

    /** @param  list<EncaissementVente>  $encaissements */
    private function regler(User $acteur, array $encaissements, ?CompteTresorerie $support = null, array $surcharge = [])
    {
        return $this->actingAs($acteur)->post(
            route('comptabilite.tresorerie.inter-agences.reglements.store', [$this->agenceB, $this->agenceA]),
            array_merge([
                'encaissement_ids' => array_map(fn (EncaissementVente $e) => $e->id, $encaissements),
                'compte_tresorerie_origine_id' => ($support ?? $this->orangeB)->id,
            ], $surcharge),
        );
    }

    private function aucunReglement(): void
    {
        $this->assertSame(0, MouvementFonds::where('nature', NatureMouvementFonds::REGLEMENT_AGENCES->value)->count());
        $this->assertSame(0, MouvementFondsEncaissement::count());
    }

    // ── Écran « À verser / À recevoir » ──────────────────────────────────────

    public function test_l_admin_voit_une_seule_ligne_par_sens_de_reversement(): void
    {
        $this->encaissement(200_000);
        $this->encaissement(300_000);

        $this->actingAs($this->user)->get(route('comptabilite.tresorerie.inter-agences.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Comptabilite/Tresorerie/InterAgences/Index')
                ->has('reversements', 1)
                ->where('reversements.0.debiteur.id', $this->agenceB->id)
                ->where('reversements.0.debiteur.nom', 'Agence Kindia')
                ->where('reversements.0.creancier.id', $this->agenceA->id)
                ->where('reversements.0.creancier.nom', 'Site Principal')
                ->where('reversements.0.a_verser', 500_000)
                ->where('reversements.0.en_cours_versement', 0)
                ->where('reversements.0.verse', 0)
                ->where('reversements.0.statut', 'a_verser')
                ->where('reversements.0.statut_label', 'À envoyer')
                ->where('reversements.0.detail_url', route('comptabilite.tresorerie.inter-agences.show', [$this->agenceB, $this->agenceA], false)));
    }

    public function test_le_filtre_agence_restreint_l_affichage(): void
    {
        $this->encaissement(200_000);

        $agenceC = $this->creerSite('Agence Labé');
        $agenceD = $this->creerSite('Agence Boké');
        $supportC = $this->creerSupportAgence($agenceC->id, 'mobile_money', '561100', 'orange_money', 'Orange Labé');
        $this->encaissement(50_000, $agenceD, $supportC);

        // La même ligne est visible depuis l'agence qui verse comme depuis celle qui reçoit.
        foreach ([$this->agenceB, $this->agenceA] as $agence) {
            $this->actingAs($this->user)->get(route('comptabilite.tresorerie.inter-agences.index', ['site_ids' => [$agence->id]]))
                ->assertInertia(fn (Assert $page) => $page
                    ->has('reversements', 1)
                    ->where('reversements.0.debiteur.id', $this->agenceB->id)
                    ->where('reversements.0.creancier.id', $this->agenceA->id)
                    ->where('reversements.0.a_verser', 200_000)
                    ->where('filters.site_ids', [$agence->id]));
        }
    }

    public function test_un_utilisateur_ne_voit_que_ses_agences_et_le_filtre_ne_l_elargit_pas(): void
    {
        $this->encaissement(200_000);

        $this->actingAs($this->agentB)->get(route('comptabilite.tresorerie.inter-agences.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('reversements', 1)
                ->where('reversements.0.debiteur.id', $this->agenceB->id)
                ->has('sites', 1));

        $this->actingAs($this->agentB)->get(route('comptabilite.tresorerie.inter-agences.index', ['site_ids' => [$this->agenceA->id]]))
            ->assertInertia(fn (Assert $page) => $page->has('reversements', 0));
    }

    public function test_les_dettes_dans_les_deux_sens_ne_sont_pas_compensees(): void
    {
        $this->encaissement(200_000);
        $this->encaissement(50_000, $this->agenceB, $this->caisseA);

        $this->actingAs($this->user)->get(route('comptabilite.tresorerie.inter-agences.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('reversements', 2)
                ->where('reversements.0.debiteur.id', $this->agenceB->id)
                ->where('reversements.0.creancier.id', $this->agenceA->id)
                ->where('reversements.0.a_verser', 200_000)
                ->where('reversements.1.debiteur.id', $this->agenceA->id)
                ->where('reversements.1.creancier.id', $this->agenceB->id)
                ->where('reversements.1.a_verser', 50_000));
    }

    public function test_un_reversement_envoye_reste_visible_jusqu_a_reception(): void
    {
        $encaissement = $this->encaissement(200_000);
        $this->regler($this->agentB, [$encaissement])->assertSessionHasNoErrors();

        $this->actingAs($this->user)->get(route('comptabilite.tresorerie.inter-agences.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('reversements', 1)
                ->where('reversements.0.a_verser', 0)
                ->where('reversements.0.en_cours_versement', 200_000)
                ->where('reversements.0.verse', 0)
                ->where('reversements.0.statut', 'en_cours_versement'));
    }

    public function test_deja_verse_et_statut_suivent_les_receptions_et_conservent_la_ligne_soldee(): void
    {
        $premier = $this->encaissement(200_000);
        $second = $this->encaissement(300_000);
        $this->regler($this->agentB, [$premier])->assertSessionHasNoErrors();
        $mouvements = app(MouvementFondsService::class);
        $mouvements->recevoir(MouvementFonds::where('nature', NatureMouvementFonds::REGLEMENT_AGENCES)->sole(), $this->user->id, $this->caisseA->id);

        $this->actingAs($this->user)->get(route('comptabilite.tresorerie.inter-agences.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('reversements', 1)
                ->where('reversements.0.a_verser', 300_000)
                ->where('reversements.0.verse', 200_000)
                ->where('reversements.0.statut', 'partiellement_verse')
                ->where('reversements.0.statut_label', 'Partiellement versé'));

        $this->regler($this->agentB, [$second])->assertSessionHasNoErrors();
        $this->actingAs($this->user)->get(route('comptabilite.tresorerie.inter-agences.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('reversements.0.a_verser', 0)
                ->where('reversements.0.en_cours_versement', 300_000)
                ->where('reversements.0.verse', 200_000)
                ->where('reversements.0.statut', 'en_cours_versement'));

        $mouvements->recevoir(MouvementFonds::where('nature', NatureMouvementFonds::REGLEMENT_AGENCES)->where('statut', StatutMouvementFonds::ENVOYE)->sole(), $this->user->id, $this->caisseA->id);
        foreach ([$this->agenceA, $this->agenceB] as $agence) {
            $this->actingAs($this->user)->get(route('comptabilite.tresorerie.inter-agences.index', ['site_ids' => [$agence->id]]))
                ->assertInertia(fn (Assert $page) => $page
                    ->has('reversements', 1)
                    ->where('reversements.0.a_verser', 0)
                    ->where('reversements.0.en_cours_versement', 0)
                    ->where('reversements.0.verse', 500_000)
                    ->where('reversements.0.statut', 'verse')
                    ->where('reversements.0.statut_label', 'Versé'));
        }
    }

    public function test_l_ecran_exige_la_lecture_de_la_tresorerie(): void
    {
        $sansDroit = $this->creerUtilisateurNonAdmin($this->agenceB, ['ventes.read'], 'Sans', 'Tresorerie');

        $this->actingAs($sansDroit)->get(route('comptabilite.tresorerie.inter-agences.index'))->assertForbidden();
        $this->actingAs($sansDroit)->get(route('comptabilite.tresorerie.inter-agences.show', [$this->agenceB, $this->agenceA]))->assertForbidden();
    }

    // ── Détail ───────────────────────────────────────────────────────────────

    public function test_le_detail_liste_les_encaissements_et_ne_propose_jamais_une_caisse_d_agent(): void
    {
        $x = $this->encaissement(200_000);
        $caisseAgent = $this->creerCaisseActivePour($this->agentB, $this->agenceB->id);

        $this->actingAs($this->agentB)->get(route('comptabilite.tresorerie.inter-agences.show', [$this->agenceB, $this->agenceA]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Comptabilite/Tresorerie/InterAgences/Show')
                ->has('lignes', 1)
                ->where('lignes.0.encaissement_id', $x->id)
                ->where('lignes.0.statut', DetteInterAgencesService::A_VERSER)
                ->where('lignes.0.selectionnable', true)
                ->where('lignes.0.mode_paiement_label', 'Orange Money')
                ->where('lignes.0.auteur', $this->agentB->name)
                ->has('lignes_a_regler', 1)
                ->where('resume.a_verser', 200_000)
                ->where('peut_regler', true)
                ->where('supports', fn ($supports) => collect($supports)->pluck('id')->doesntContain($caisseAgent->id)
                    && collect($supports)->pluck('id')->contains($this->orangeB->id)
                    && collect($supports)->pluck('id')->doesntContain($this->caisseA->id)));
    }

    public function test_le_detail_est_refuse_a_qui_n_a_acces_a_aucune_des_deux_agences(): void
    {
        $agenceC = $this->creerSite('Agence Labé');
        $agentC = $this->creerUtilisateurNonAdmin($agenceC, self::PERMISSIONS_REGLEMENT, 'Agent', 'Labé');

        $this->actingAs($agentC)->get(route('comptabilite.tresorerie.inter-agences.show', [$this->agenceB, $this->agenceA]))
            ->assertForbidden();
    }

    public function test_une_agence_d_une_autre_organisation_est_introuvable(): void
    {
        $etrangere = $this->creerSite('Ailleurs', Organization::factory()->create());

        $this->actingAs($this->user)->get(route('comptabilite.tresorerie.inter-agences.show', [$this->agenceB, $etrangere]))
            ->assertNotFound();
        $this->actingAs($this->user)->post(route('comptabilite.tresorerie.inter-agences.reglements.store', [$etrangere, $this->agenceA]), [
            'encaissement_ids' => ['x'],
            'compte_tresorerie_origine_id' => $this->orangeB->id,
        ])->assertNotFound();
    }

    public function test_le_detail_sans_droit_de_reglement_ne_propose_ni_reglement_ni_support(): void
    {
        $this->encaissement(200_000);
        $lecteur = $this->creerUtilisateurNonAdmin($this->agenceB, ['tresorerie.read'], 'Lecteur', 'Kindia');

        $this->actingAs($lecteur)->get(route('comptabilite.tresorerie.inter-agences.show', [$this->agenceB, $this->agenceA]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('peut_regler', false)
                ->where('supports', [])
                ->where('lignes_a_regler', []));
    }

    // ── Régler : création + envoi ────────────────────────────────────────────

    public function test_regler_cree_et_envoie_le_reglement_avec_un_montant_calcule_par_le_serveur(): void
    {
        $x = $this->encaissement(200_000);
        $y = $this->encaissement(150_000);

        $this->regler($this->agentB, [$x, $y], surcharge: ['montant' => 1])->assertSessionHasNoErrors();

        $reglement = MouvementFonds::where('nature', NatureMouvementFonds::REGLEMENT_AGENCES->value)->firstOrFail();
        $this->assertSame(350_000.0, (float) $reglement->montant, 'le montant forcé par la requête est ignoré');
        $this->assertSame(StatutMouvementFonds::ENVOYE, $reglement->statut);
        $this->assertSame($this->agentB->id, $reglement->sent_by);
        $this->assertCount(2, $reglement->lignesReglement);

        $statuts = app(DetteInterAgencesService::class)->lignes($this->org->id)->pluck('statut')->unique()->values()->all();
        $this->assertSame([DetteInterAgencesService::EN_COURS], $statuts);
    }

    public function test_regler_exige_le_droit_d_envoyer(): void
    {
        $x = $this->encaissement(200_000);
        $sansEnvoi = $this->creerUtilisateurNonAdmin($this->agenceB, ['tresorerie.read', 'tresorerie.create'], 'Sans', 'Envoi');

        $this->regler($sansEnvoi, [$x])->assertForbidden();
        $this->aucunReglement();
    }

    public function test_regler_exige_d_etre_affecte_a_l_agence_qui_verse(): void
    {
        $x = $this->encaissement(200_000);
        $agentA = $this->creerUtilisateurNonAdmin($this->agenceA, self::PERMISSIONS_REGLEMENT, 'Agent', 'Matoto');

        $this->regler($agentA, [$x])->assertForbidden();
        $this->aucunReglement();
    }

    public function test_une_caisse_d_agent_est_refusee(): void
    {
        $x = $this->encaissement(200_000);
        $caisseAgent = $this->creerCaisseActivePour($this->agentB, $this->agenceB->id);

        $this->regler($this->agentB, [$x], $caisseAgent)->assertSessionHasErrors('compte_tresorerie_origine_id');
        $this->aucunReglement();
    }

    public function test_un_support_de_l_agence_qui_recoit_est_refuse(): void
    {
        $x = $this->encaissement(200_000);

        $this->regler($this->agentB, [$x], $this->caisseA)->assertSessionHasErrors('compte_tresorerie_origine_id');
        $this->aucunReglement();
    }

    public function test_un_solde_insuffisant_ne_laisse_aucun_reglement_ni_brouillon(): void
    {
        $x = $this->encaissement(200_000);

        // La caisse de Kindia n'a rien reçu : l'envoi échoue, la création est annulée avec lui.
        $this->regler($this->agentB, [$x], $this->caisseB)->assertSessionHasErrors('montant');

        $this->aucunReglement();
        $this->assertSame(DetteInterAgencesService::A_VERSER, app(DetteInterAgencesService::class)->lignes($this->org->id)->first()['statut']);
    }

    public function test_un_encaissement_deja_regle_ne_peut_pas_l_etre_une_seconde_fois(): void
    {
        $x = $this->encaissement(200_000);
        $this->regler($this->agentB, [$x])->assertSessionHasNoErrors();

        $this->regler($this->agentB, [$x])->assertSessionHasErrors('encaissements');

        $this->assertSame(1, MouvementFonds::where('nature', NatureMouvementFonds::REGLEMENT_AGENCES->value)->count());
    }

    public function test_aucun_encaissement_selectionne_est_refuse(): void
    {
        $this->regler($this->agentB, [])->assertSessionHasErrors('encaissement_ids');
        $this->aucunReglement();
    }

    // ── Compteur, Mouvements, fiche commande ─────────────────────────────────

    public function test_le_compteur_de_l_agence_qui_recoit_inclut_le_reglement(): void
    {
        $x = $this->encaissement(200_000);
        $agentA = $this->creerUtilisateurNonAdmin($this->agenceA, self::PERMISSIONS_REGLEMENT, 'Agent', 'Matoto');

        $this->assertSame(0, $this->badge($agentA));

        $this->regler($this->agentB, [$x])->assertSessionHasNoErrors();

        $this->assertSame(1, $this->badge($agentA), 'Matoto doit confirmer la réception');
        $this->assertSame(0, $this->badge($this->agentB), 'Kindia a envoyé, rien à confirmer');
    }

    public function test_les_mouvements_detaillent_les_encaissements_d_un_reglement_et_pas_ceux_d_un_mouvement_ordinaire(): void
    {
        $x = $this->encaissement(200_000);
        $y = $this->encaissement(100_000);
        $this->regler($this->agentB, [$x, $y])->assertSessionHasNoErrors();
        app(MouvementFondsService::class)->creerBrouillon($this->org->id, [
            'site_origine_id' => $this->agenceB->id,
            'site_destination_id' => $this->agenceA->id,
            'compte_tresorerie_origine_id' => $this->orangeB->id,
            'montant' => 1_000,
        ], $this->user->id);

        $this->actingAs($this->user)->get(route('comptabilite.tresorerie.mouvements.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page) use ($x) {
                $page->where('mouvements.data', function ($mouvements) use ($x) {
                    $mouvements = collect($mouvements);
                    $reglement = $mouvements->firstWhere('nature', NatureMouvementFonds::REGLEMENT_AGENCES->value);
                    $ordinaire = $mouvements->firstWhere('nature', NatureMouvementFonds::INTER_SITES->value);

                    return $reglement['nature_label'] === 'Règlement inter-agences'
                        && count($reglement['encaissements_regles']) === 2
                        && collect($reglement['encaissements_regles'])->pluck('facture_reference')->contains($x->facture->reference)
                        && str_contains($reglement['detail_reglement_url'], '/inter-agences/')
                        && $ordinaire['encaissements_regles'] === []
                        && $ordinaire['detail_reglement_url'] === null;
                });
            });
    }

    public function test_la_fiche_commande_affiche_ou_l_argent_a_ete_recu_et_son_reversement(): void
    {
        $x = $this->encaissement(200_000);
        $this->regler($this->agentB, [$x])->assertSessionHasNoErrors();
        $reference = MouvementFonds::firstOrFail()->reference;

        $this->actingAs($this->user)->get(route('ventes.show', $x->facture->commande))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('facture.encaissements.0.encaisse_a', 'Agence Kindia')
                ->where('facture.encaissements.0.pour_autre_agence', true)
                ->where('facture.encaissements.0.reversement.statut', DetteInterAgencesService::EN_COURS)
                ->where('facture.encaissements.0.reversement.statut_label', 'En cours de versement')
                ->where('facture.encaissements.0.reversement.mouvement_reference', $reference));
    }

    public function test_la_fiche_commande_affiche_l_agence_d_un_encaissement_dans_la_meme_agence_sans_reversement(): void
    {
        $orangeA = $this->creerSupportAgence($this->agenceA->id, 'mobile_money', '561100', 'orange_money');
        $x = $this->encaissement(200_000, $this->agenceA, $orangeA);

        $this->actingAs($this->user)->get(route('ventes.show', $x->facture->commande))
            ->assertInertia(fn (Assert $page) => $page
                ->where('facture.encaissements.0.encaisse_a', 'Site Principal')
                ->where('facture.encaissements.0.pour_autre_agence', false)
                ->where('facture.encaissements.0.reversement', null));
    }

    public function test_l_historique_de_la_commande_affiche_le_nom_de_l_agence_jamais_son_identifiant(): void
    {
        $x = $this->encaissement(200_000);
        $commande = $x->facture->commande;
        app(AuditLogService::class)->record($commande, AuditEvent::ENCAISSEMENT_ADDED, $this->user, null, [
            'montant' => 200_000.0,
            'site_encaissement_id' => $this->agenceB->id,
        ]);

        $this->actingAs($this->user)->get(route('ventes.show', $commande))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('historiques', function ($historiques) {
                    $valeurs = collect($historiques)->firstWhere('event_code', AuditEvent::ENCAISSEMENT_ADDED->value)['new_values'];

                    return ($valeurs['agence_encaissement'] ?? null) === 'Agence Kindia'
                        && ! array_key_exists('site_encaissement_id', $valeurs)
                        && ! in_array($this->agenceB->id, $valeurs, true);
                }));
    }

    private function badge(User $acteur): int
    {
        $badge = -1;

        $this->actingAs($acteur)
            ->get(route('comptabilite.tresorerie.mouvements.index'))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$badge) {
                $page->where('mouvements_fonds_a_confirmer', function ($value) use (&$badge) {
                    $badge = $value;

                    return true;
                });
            });

        return $badge;
    }
}
