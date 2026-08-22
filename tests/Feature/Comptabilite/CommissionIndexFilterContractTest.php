<?php

namespace Tests\Feature\Comptabilite;

use App\Models\Categorie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * Contrat partagé des filtres des quatre listes de commissions.
 *
 * DataFilters envoie les sélecteurs sous forme de tableaux, même lorsqu'ils
 * ne contiennent qu'une valeur. Chaque contrôleur doit normaliser ces valeurs
 * et les restituer à la page pour que le drawer reflète exactement la requête.
 */
class CommissionIndexFilterContractTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
        $this->initOrgAndUser(['comptabilite.read', 'commissions.exporter']);
        $this->actingAs($this->user);
    }

    public function test_vente_restitue_la_combinaison_de_filtres_du_composant_partage(): void
    {
        $siteId = $this->user->sites()->firstOrFail()->id;

        $this->get('/backoffice/comptabilite/commissions/vente?'.http_build_query([
            'site_ids' => [$siteId],
            'statut' => ['impaye'],
            'periode' => ['2026-08-P1'],
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Comptabilite/CommissionVente/Index')
            ->where('filtre_site_ids', [$siteId])
            ->where('filtre_statut', 'impaye')
            ->where('selected_periode', '2026-08-P1')
        );
    }

    public function test_logistique_restitue_la_combinaison_de_filtres_du_composant_partage(): void
    {
        $siteId = $this->user->sites()->firstOrFail()->id;

        $this->get('/backoffice/comptabilite/commissions/logistique?'.http_build_query([
            'site_ids' => [$siteId],
            'statut' => ['paye'],
            'periode' => ['2026-08-P2'],
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Comptabilite/CommissionLogistique/Index')
            ->where('filtre_site_ids', [$siteId])
            ->where('filtre_statut', 'paye')
            ->where('selected_periode', '2026-08-P2')
        );
    }

    public function test_proprietaires_restitue_les_filtres_texte_et_les_selecteurs(): void
    {
        $siteId = $this->user->sites()->firstOrFail()->id;

        $this->get('/backoffice/comptabilite/commissions/proprietaires?'.http_build_query([
            'site_ids' => [$siteId],
            'nom' => 'Moussa',
            'telephone' => '622',
            'statut' => ['partiel'],
            'periode' => ['2026-08-P1'],
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Comptabilite/CommissionProprietaire/Index')
            ->where('filtre_site_ids', [$siteId])
            ->where('filtre_nom', 'Moussa')
            ->where('filtre_telephone', '622')
            ->where('filtre_statut', 'partiel')
            ->where('selected_periode', '2026-08-P1')
        );
    }

    public function test_sites_restitue_ses_filtres_specifiques_en_plus_du_socle_commun(): void
    {
        $siteId = $this->user->sites()->firstOrFail()->id;
        $categorie = Categorie::create([
            'organization_id' => $this->org->id,
            'nom' => 'Catégorie filtre commissions',
            'statut' => 'actif',
        ]);

        $this->get('/backoffice/comptabilite/commissions/sites?'.http_build_query([
            'site_ids' => [$siteId],
            'statut' => ['creee'],
            'periode' => ['2026-08-P1'],
            'categorie_id' => [$categorie->id],
            'site_type' => ['depot'],
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Comptabilite/CommissionSite/Index')
            ->where('filtre_site_ids', [$siteId])
            ->where('filtre_statut', 'creee')
            ->where('filtre_categorie_id', $categorie->id)
            ->where('filtre_site_type', 'depot')
            ->where('selected_periode', '2026-08-P1')
        );
    }

    /**
     * Consultant n'a pas de socle "site" (désignation au niveau organisation, cf. mission) : pas
     * de site_ids à restituer, seulement statut/période/consultant_id.
     */
    public function test_consultants_restitue_son_filtre_specifique_sans_socle_site(): void
    {
        $this->get('/backoffice/comptabilite/commissions/consultants?'.http_build_query([
            'statut' => ['impaye'],
            'periode' => ['2026-08-M'],
            'consultant_id' => ['some-prestataire-id'],
        ]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Comptabilite/CommissionConsultant/Index')
            ->where('filtre_statut', 'impaye')
            ->where('filtre_consultant_id', 'some-prestataire-id')
            ->where('selected_periode', '2026-08-M')
        );
    }
}
