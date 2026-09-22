<?php

namespace Tests\Feature\Comptabilite;

use App\Enums\StatutMouvementFonds;
use App\Models\CompteComptable;
use App\Models\CompteTresorerie;
use App\Models\MouvementFonds;
use App\Models\Organization;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Badge « Mouvements à confirmer » (menu Comptabilité > Trésorerie > Mouvements + cloche de
 * notifications) : compteur live partagé par HandleInertiaRequests::mouvementsFondsAConfirmer(),
 * même mécanisme que transferts_a_receptionner pour la logistique — pas de notification
 * persistée, le badge disparaît de lui-même dès que le mouvement change de statut.
 */
class MouvementFondsBadgeNotificationTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    private Site $siege;

    private Site $agence;

    private CompteTresorerie $caisseSiege;

    private CompteTresorerie $caisseAgence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['tresorerie.create', 'tresorerie.read', 'tresorerie.envoyer', 'tresorerie.recevoir']);

        $this->siege = Site::create(['organization_id' => $this->org->id, 'nom' => 'Siège', 'type' => 'siege', 'localisation' => 'Conakry']);
        // Le site créé par initOrgAndUser() : c'est celui de l'utilisateur, l'« agence » destinataire des tests.
        $this->agence = $this->user->sites()->first();

        $compteCaisse = CompteComptable::where('organization_id', $this->org->id)->where('numero', '571000')->firstOrFail();
        $this->caisseSiege = CompteTresorerie::create([
            'organization_id' => $this->org->id, 'site_id' => $this->siege->id,
            'compte_comptable_id' => $compteCaisse->id, 'type' => 'caisse', 'libelle' => 'Caisse Siège',
        ]);
        $this->caisseAgence = CompteTresorerie::create([
            'organization_id' => $this->org->id, 'site_id' => $this->agence->id,
            'compte_comptable_id' => $compteCaisse->id, 'type' => 'caisse', 'libelle' => 'Caisse Agence',
        ]);
    }

    private function creerMouvement(Site $origine, Site $destination, StatutMouvementFonds $statut, string $nature = 'inter_sites', ?Organization $org = null): MouvementFonds
    {
        $orgId = $org?->id ?? $this->org->id;

        return MouvementFonds::create([
            'organization_id' => $orgId,
            'nature' => $nature,
            'site_origine_id' => $origine->id,
            'site_destination_id' => $destination->id,
            'compte_tresorerie_origine_id' => $this->caisseSiege->id,
            'montant' => 100_000,
            'statut' => $statut->value,
        ]);
    }

    /** @param  list<string>  $siteIds */
    private function creerNonAdmin(array $siteIds, array $permissions = ['tresorerie.read', 'tresorerie.recevoir']): User
    {
        Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $user = User::factory()->create(['organization_id' => $this->org->id]);
        $user->assignRole('manager');
        $user->givePermissionTo($permissions);
        foreach ($siteIds as $i => $siteId) {
            $user->sites()->attach($siteId, ['role' => 'employe', 'is_default' => $i === 0]);
        }

        return $user;
    }

    private function badgeVia(User $acteur): int
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

    public function test_compte_un_mouvement_entre_agences_envoye_vers_le_site_de_l_utilisateur(): void
    {
        $this->creerMouvement($this->siege, $this->agence, StatutMouvementFonds::ENVOYE);

        $this->assertSame(1, $this->badgeVia($this->user));
    }

    public function test_exclut_un_versement_de_caisse_interne_deja_envoye(): void
    {
        // interne_caisses : même site en origine et destination, traité par sa propre page.
        $this->creerMouvement($this->agence, $this->agence, StatutMouvementFonds::ENVOYE, 'interne_caisses');

        $this->assertSame(0, $this->badgeVia($this->user));
    }

    public function test_exclut_tout_statut_different_d_envoye(): void
    {
        foreach ([StatutMouvementFonds::BROUILLON, StatutMouvementFonds::RECU, StatutMouvementFonds::ANNULE, StatutMouvementFonds::CONTESTE, StatutMouvementFonds::RETOURNE] as $statut) {
            $this->creerMouvement($this->siege, $this->agence, $statut);
        }

        $this->assertSame(0, $this->badgeVia($this->user));
    }

    public function test_un_non_admin_exclut_un_mouvement_dont_son_site_est_l_origine_pas_la_destination(): void
    {
        // Non-admin, contrairement à $this->user (admin_entreprise) : lui seul est restreint à ses
        // propres sites rattachés (cf. dérogation admin testée séparément ci-dessous).
        $nonAdmin = $this->creerNonAdmin([$this->agence->id]);
        $autreAgence = Site::create(['organization_id' => $this->org->id, 'nom' => 'Autre agence', 'type' => 'agence', 'localisation' => 'Conakry']);
        // L'agence du non-admin est ici l'ORIGINE, pas la destination : rien à confirmer pour lui.
        $this->creerMouvement($this->agence, $autreAgence, StatutMouvementFonds::ENVOYE);

        $this->assertSame(0, $this->badgeVia($nonAdmin));
    }

    public function test_un_non_admin_exclut_aussi_un_mouvement_destine_a_un_site_auquel_il_n_est_pas_rattache(): void
    {
        $nonAdmin = $this->creerNonAdmin([$this->agence->id]);
        // Destiné au siège, auquel le non-admin n'est pas rattaché (seulement à $this->agence).
        $this->creerMouvement($this->agence, $this->siege, StatutMouvementFonds::ENVOYE);

        $this->assertSame(0, $this->badgeVia($nonAdmin));
    }

    /**
     * Incident du 22/09/2026 : un super admin rattaché uniquement au Siège (donc jamais à
     * l'agence destinataire) avait envoyé un mouvement vers une autre agence et ne voyait aucun
     * badge, alors que l'écran Mouvements lui permettait déjà de cliquer « Confirmer réception »
     * sur ce même mouvement (MouvementFondsPolicy::recevoir() laisse un admin agir même sans y
     * être personnellement affecté). $this->user (admin_entreprise) n'est rattaché qu'à
     * $this->agence, jamais à $this->siege : la dérogation admin doit malgré tout compter ce
     * mouvement destiné au Siège.
     */
    public function test_un_admin_voit_le_badge_meme_pour_un_site_auquel_il_n_est_pas_rattache(): void
    {
        $this->creerMouvement($this->agence, $this->siege, StatutMouvementFonds::ENVOYE);

        $this->assertSame(1, $this->badgeVia($this->user));
    }

    public function test_exclut_un_utilisateur_sans_la_permission_tresorerie_recevoir(): void
    {
        $this->creerMouvement($this->siege, $this->agence, StatutMouvementFonds::ENVOYE);
        $this->user->syncPermissions(['tresorerie.read']);

        $this->assertSame(0, $this->badgeVia($this->user));
    }

    public function test_ignore_un_mouvement_d_une_autre_organisation(): void
    {
        $autreOrg = Organization::factory()->create();
        $autreSiteOrigine = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'S1', 'type' => 'siege', 'localisation' => 'X']);
        // Même ID de site que l'agence de l'utilisateur ? Impossible (ULID généré) — la portée
        // organisation_id suffit, ce test vérifie qu'aucune fuite inter-organisation n'existe.
        $autreSiteDestination = Site::create(['organization_id' => $autreOrg->id, 'nom' => 'S2', 'type' => 'agence', 'localisation' => 'X']);
        $this->creerMouvement($autreSiteOrigine, $autreSiteDestination, StatutMouvementFonds::ENVOYE, 'inter_sites', $autreOrg);

        $this->assertSame(0, $this->badgeVia($this->user));
    }

    public function test_disparait_des_confirmation_de_la_reception(): void
    {
        $mouvement = $this->creerMouvement($this->siege, $this->agence, StatutMouvementFonds::ENVOYE);
        $this->assertSame(1, $this->badgeVia($this->user));

        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.mouvements.recevoir', $mouvement), [
                'compte_tresorerie_destination_id' => $this->caisseAgence->id,
            ])
            ->assertRedirect();

        $this->assertSame(0, $this->badgeVia($this->user));
    }

    public function test_apparait_des_l_envoi_via_la_route(): void
    {
        $mouvement = MouvementFonds::create([
            'organization_id' => $this->org->id,
            'nature' => 'inter_sites',
            'site_origine_id' => $this->siege->id,
            'site_destination_id' => $this->agence->id,
            'compte_tresorerie_origine_id' => $this->caisseSiege->id,
            'montant' => 250_000,
            'statut' => StatutMouvementFonds::BROUILLON->value,
        ]);
        $this->assertSame(0, $this->badgeVia($this->user));

        // sent_by = l'utilisateur lui-même ici : la séparation envoi/réception ne s'applique
        // qu'aux versements de caisse interne (cf. MouvementFonds::separationEnvoiReceptionRespectee()),
        // pas aux mouvements entre agences, dont la séparation se fait par site.
        $this->actingAs($this->user)
            ->post(route('comptabilite.tresorerie.mouvements.envoyer', $mouvement))
            ->assertRedirect();

        $this->assertSame(1, $this->badgeVia($this->user));
    }

    public function test_un_deuxieme_utilisateur_de_la_meme_agence_voit_aussi_le_badge(): void
    {
        $collegue = $this->creerNonAdmin([$this->agence->id]);

        $this->creerMouvement($this->siege, $this->agence, StatutMouvementFonds::ENVOYE);

        $this->assertSame(1, $this->badgeVia($collegue));
    }
}
