<?php

namespace Tests\Feature\Ventes;

use App\Enums\StatutCommandeVente;
use App\Models\CommandeVente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Concerns\HasAdminSetup;
use Tests\Feature\Concerns\HasOrgAndUser;
use Tests\TestCase;

/**
 * « Mes vues » sur la page Précommandes, sur le modèle du Stock : scope `precommandes` distinct de
 * celui des ventes, critère « En retard » compris, vue par défaut et réinitialisation (all=1).
 */
class PrecommandeSavedViewsTest extends TestCase
{
    use HasAdminSetup, HasOrgAndUser, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initOrgAndUser(['ventes.read']);
        $this->actingAs($this->user);
    }

    private function precommande(string $reference, StatutCommandeVente $statut, int $decalageJours = 2): CommandeVente
    {
        return CommandeVente::create([
            'organization_id' => $this->org->id,
            'site_id' => $this->user->sites()->firstOrFail()->id,
            'reference' => $reference, 'numero' => random_int(1, 999999),
            'statut' => $statut, 'total_commande' => 5000,
            'est_precommande' => true,
            'date_remise_prevue' => today()->addDays($decalageJours),
        ]);
    }

    private function enregistrer(array $filters, bool $parDefaut = false)
    {
        return $this->postJson(route('saved-filters.store', 'precommandes'), [
            'name' => 'Retards à préparer', 'visibility' => 'personal', 'filters' => $filters, 'is_default' => $parDefaut,
        ]);
    }

    public function test_une_vue_par_defaut_filtre_la_liste_sans_fausser_les_compteurs_ni_les_ventes(): void
    {
        $this->precommande('VTE-RETARD', StatutCommandeVente::RESERVEE, -1);
        $this->precommande('VTE-A-L-HEURE', StatutCommandeVente::RESERVEE);
        $this->precommande('VTE-LIVRAISON', StatutCommandeVente::LIVRAISON_EN_COURS);
        $id = $this->enregistrer(['statuts' => ['reservee'], 'en_retard' => '1'], true)->assertCreated()->json('id');

        foreach (['', '?saved_view='.$id] as $query) {
            $this->get('/backoffice/precommandes'.$query)->assertOk()->assertInertia(fn (Assert $p) => $p
                ->where('saved_view.id', $id)
                ->has('commandes', 1)
                ->where('commandes.0.reference', 'VTE-RETARD')
                ->where('filters.en_retard', '1')
                // Compteurs calculés hors Statut / En retard : ils restent des filtres rapides.
                ->where('indicateurs_precommandes.en_cours.nombre', 3));
        }

        $this->get('/backoffice/precommandes?all=1')->assertInertia(fn (Assert $p) => $p
            ->where('saved_view', null)->has('commandes', 3));
        $this->get('/backoffice/ventes')->assertInertia(fn (Assert $p) => $p->where('saved_view', null));
    }

    public function test_une_vue_de_ventes_ne_s_applique_pas_aux_precommandes(): void
    {
        $this->precommande('VTE-PRECO', StatutCommandeVente::RESERVEE);
        $this->postJson(route('saved-filters.store', 'ventes'), [
            'name' => 'Livrées', 'visibility' => 'personal', 'filters' => ['statuts' => ['livree']], 'is_default' => true,
        ])->assertCreated();

        $this->get('/backoffice/precommandes')->assertInertia(fn (Assert $p) => $p
            ->where('saved_view', null)->has('commandes', 1));
    }

    public function test_le_critere_en_retard_n_accepte_que_1(): void
    {
        $this->enregistrer(['en_retard' => 'oui'])->assertUnprocessable()->assertJsonValidationErrors('filters.en_retard');
    }
}
