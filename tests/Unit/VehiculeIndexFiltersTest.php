<?php

namespace Tests\Unit;

use App\Support\Vehicules\VehiculeIndexFilters;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class VehiculeIndexFiltersTest extends TestCase
{
    private function rows(): Collection
    {
        $base = [
            'type_vehicule_id' => 'type-1', 'type_label' => 'Camion',
            'site_id' => 'site-1', 'agence_id' => 'owner-site', 'agence_nom' => 'Matoto',
            'nom_vehicule' => 'Alpha', 'immatriculation' => 'RC-100',
            'proprietaire_nom' => 'Moussa', 'proprietaire_telephone' => '+224 600 123 456',
            'equipe_nom' => 'Equipe A', 'is_active' => true,
            'livraison_vente' => true, 'livraison_logistique' => false,
            'partages_commission' => ['vente' => 'fait'], 'capacites' => [['capacite_max' => 540]],
        ];

        return collect([
            [...$base, 'id' => 'a'],
            [...$base, 'id' => 'b', 'nom_vehicule' => 'Beta', 'is_active' => false,
                'agence_id' => null, 'agence_nom' => null, 'site_id' => 'site-2',
                'livraison_vente' => false, 'livraison_logistique' => true,
                'partages_commission' => ['logistique_transfert' => 'sans_equipe']],
        ]);
    }

    public function test_filters_combine_and_distinguish_vehicle_site_from_owner_agency(): void
    {
        $this->assertSame(['a'], VehiculeIndexFilters::apply($this->rows(), [
            'site_ids' => ['site-1'], 'agence_proprietaire_id' => 'owner-site',
            'type_vehicule_id' => 'type-1', 'statut' => 'actif', 'usage' => 'vente', 'partage' => 'fait',
        ])->pluck('id')->all());
        $this->assertSame(['b'], VehiculeIndexFilters::apply($this->rows(), [
            'agence_proprietaire_id' => '__none__', 'usage' => 'logistique', 'partage' => 'a_faire',
        ])->pluck('id')->all());
        $this->assertCount(0, VehiculeIndexFilters::apply($this->rows(), ['usage' => 'aucun']));
    }

    public function test_text_search_does_not_match_every_phone_when_search_has_no_digits(): void
    {
        $this->assertSame(['a'], VehiculeIndexFilters::apply($this->rows(), ['nom' => 'alpha'])->pluck('id')->all());
        $this->assertCount(0, VehiculeIndexFilters::apply($this->rows(), ['nom' => 'introuvable']));
        $this->assertCount(2, VehiculeIndexFilters::apply($this->rows(), ['nom' => '600123456']));
        $this->assertCount(2, VehiculeIndexFilters::apply($this->rows(), ['nom' => '540']));
    }
}
