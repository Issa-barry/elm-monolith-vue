<?php

namespace Tests\Unit;

use App\Enums\CategorieTarifaireVehicule;
use App\Models\ProduitVariante;
use App\Services\PrixUsineResolver;
use Tests\TestCase;

/**
 * Couvre la résolution centralisée du prix usine applicable (tricycle vs autres véhicules) —
 * cf. analyse du modèle de tarification tricycle/autres véhicules. Doit rester le SEUL point de
 * vérité consommé par CommandeVenteController et PdvCheckoutService. Purement unitaire (pas de
 * DB) : PrixUsineResolver ne dépend que des attributs de la variante passée en mémoire.
 */
class PrixUsineResolverTest extends TestCase
{
    private function variante(?int $prixUsine, ?int $prixUsineTricycle): ProduitVariante
    {
        return new ProduitVariante([
            'prix_usine' => $prixUsine,
            'prix_usine_tricycle' => $prixUsineTricycle,
        ]);
    }

    public function test_resout_le_tarif_tricycle_quand_la_categorie_est_tricycle(): void
    {
        $variante = $this->variante(5100, 5050);

        $prix = PrixUsineResolver::resolve($variante, CategorieTarifaireVehicule::TRICYCLE);

        $this->assertSame(5050, $prix);
    }

    public function test_resout_le_tarif_standard_quand_la_categorie_est_autre_vehicule(): void
    {
        $variante = $this->variante(5100, 5050);

        $prix = PrixUsineResolver::resolve($variante, CategorieTarifaireVehicule::AUTRE_VEHICULE);

        $this->assertSame(5100, $prix);
    }

    public function test_resout_le_tarif_standard_quand_aucune_categorie_nest_connue(): void
    {
        $variante = $this->variante(5100, 5050);

        $prix = PrixUsineResolver::resolve($variante, null);

        $this->assertSame(5100, $prix);
    }

    public function test_retombe_sur_le_tarif_standard_pour_un_tricycle_sans_tarif_dedie(): void
    {
        // Variante historique jamais éditée depuis l'ajout du tarif tricycle : pas de casse pour
        // les données existantes, le repli est automatique et centralisé ici (jamais réimplémenté
        // ailleurs).
        $variante = $this->variante(5100, null);

        $prix = PrixUsineResolver::resolve($variante, CategorieTarifaireVehicule::TRICYCLE);

        $this->assertSame(5100, $prix);
    }

    public function test_retombe_sur_zero_sans_aucun_tarif_renseigne(): void
    {
        $variante = $this->variante(null, null);

        $this->assertSame(0, PrixUsineResolver::resolve($variante, CategorieTarifaireVehicule::TRICYCLE));
        $this->assertSame(0, PrixUsineResolver::resolve($variante, CategorieTarifaireVehicule::AUTRE_VEHICULE));
    }
}
