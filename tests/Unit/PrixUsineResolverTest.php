<?php

namespace Tests\Unit;

use App\Enums\CategorieTarifaireVehicule;
use App\Models\ProduitVariante;
use App\Services\PrixUsineResolver;
use Illuminate\Validation\ValidationException;
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

    public function test_refuse_explicitement_un_tricycle_sans_tarif_dedie(): void
    {
        // Décision métier : les deux tarifs sont deux décisions distinctes, jamais l'un déduit
        // de l'autre — ProduitService garantit déjà leur présence conjointe à la source, donc ce
        // cas ne devrait fonctionnellement jamais survenir pour une variante valide ; s'il
        // survient malgré tout (donnée historique non migrée), on refuse explicitement plutôt
        // que d'appliquer silencieusement un autre prix.
        $this->expectException(ValidationException::class);

        $variante = $this->variante(5100, null);

        PrixUsineResolver::resolve($variante, CategorieTarifaireVehicule::TRICYCLE);
    }

    public function test_retombe_sur_zero_sans_aucun_tarif_autre_vehicule_renseigne(): void
    {
        $variante = $this->variante(null, null);

        $this->assertSame(0, PrixUsineResolver::resolve($variante, CategorieTarifaireVehicule::AUTRE_VEHICULE));
        $this->assertSame(0, PrixUsineResolver::resolve($variante, null));
    }
}
