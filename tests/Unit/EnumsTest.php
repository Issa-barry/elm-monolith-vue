<?php

namespace Tests\Unit;

use App\Enums\PackingStatut;
use App\Enums\PrestataireType;
use App\Enums\ProduitStatut;
use App\Enums\ProduitType;
use App\Enums\SiteRole;
use App\Enums\SiteStatut;
use App\Enums\SiteType;
use App\Enums\StatutCommandeAchat;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutFactureVente;
use App\Enums\TypeVehicule;
use PHPUnit\Framework\TestCase;

class EnumsTest extends TestCase
{
    // ── PackingStatut ─────────────────────────────────────────────────────────

    public function test_packing_statut_labels(): void
    {
        $this->assertSame('Impayée', PackingStatut::IMPAYEE->label());
        $this->assertSame('Partielle', PackingStatut::PARTIELLE->label());
        $this->assertSame('Payée', PackingStatut::PAYEE->label());
        $this->assertSame('Annulée', PackingStatut::ANNULEE->label());
    }

    public function test_packing_statut_options_returns_all_cases(): void
    {
        $options = PackingStatut::options();
        $this->assertCount(4, $options);
        $this->assertSame('impayee', $options[0]['value']);
        $this->assertSame('Impayée', $options[0]['label']);
    }

    public function test_packing_statut_values(): void
    {
        $values = PackingStatut::values();
        $this->assertContains('impayee', $values);
        $this->assertContains('partielle', $values);
        $this->assertContains('payee', $values);
        $this->assertContains('annulee', $values);
    }

    // ── PrestataireType ───────────────────────────────────────────────────────

    public function test_prestataire_type_labels(): void
    {
        $this->assertSame('Machiniste', PrestataireType::MACHINISTE->label());
        $this->assertSame('Mécanicien', PrestataireType::MECANICIEN->label());
        $this->assertSame('Consultant', PrestataireType::CONSULTANT->label());
        $this->assertSame('Fournisseur', PrestataireType::FOURNISSEUR->label());
    }

    public function test_prestataire_type_values(): void
    {
        $values = PrestataireType::values();
        $this->assertContains('machiniste', $values);
        $this->assertContains('mecanicien', $values);
        $this->assertContains('consultant', $values);
        $this->assertContains('fournisseur', $values);
    }

    public function test_prestataire_type_options_returns_all_cases(): void
    {
        $options = PrestataireType::options();
        $this->assertCount(4, $options);
        foreach ($options as $option) {
            $this->assertArrayHasKey('value', $option);
            $this->assertArrayHasKey('label', $option);
        }
    }

    // ── ProduitStatut ─────────────────────────────────────────────────────────

    public function test_produit_statut_labels(): void
    {
        $this->assertSame('Actif', ProduitStatut::ACTIF->label());
        $this->assertSame('Inactif', ProduitStatut::INACTIF->label());
        $this->assertSame('Archivé', ProduitStatut::ARCHIVE->label());
    }

    public function test_produit_statut_allowed_transitions_from_actif(): void
    {
        $transitions = ProduitStatut::ACTIF->allowedTransitions();
        $this->assertContains(ProduitStatut::INACTIF, $transitions);
        $this->assertContains(ProduitStatut::ARCHIVE, $transitions);
        $this->assertNotContains(ProduitStatut::ACTIF, $transitions);
    }

    public function test_produit_statut_allowed_transitions_from_inactif(): void
    {
        $transitions = ProduitStatut::INACTIF->allowedTransitions();
        $this->assertContains(ProduitStatut::ACTIF, $transitions);
        $this->assertContains(ProduitStatut::ARCHIVE, $transitions);
    }

    public function test_produit_statut_allowed_transitions_from_archive(): void
    {
        $transitions = ProduitStatut::ARCHIVE->allowedTransitions();
        $this->assertContains(ProduitStatut::ACTIF, $transitions);
        $this->assertContains(ProduitStatut::INACTIF, $transitions);
    }

    public function test_produit_statut_can_transition_to_returns_true(): void
    {
        $this->assertTrue(ProduitStatut::ACTIF->canTransitionTo(ProduitStatut::INACTIF));
        $this->assertTrue(ProduitStatut::ACTIF->canTransitionTo(ProduitStatut::ARCHIVE));
    }

    public function test_produit_statut_can_transition_to_returns_false_for_same(): void
    {
        $this->assertFalse(ProduitStatut::ACTIF->canTransitionTo(ProduitStatut::ACTIF));
    }

    public function test_produit_statut_values(): void
    {
        $values = ProduitStatut::values();
        $this->assertContains('actif', $values);
        $this->assertContains('inactif', $values);
        $this->assertContains('archive', $values);
    }

    public function test_produit_statut_options(): void
    {
        $options = ProduitStatut::options();
        $this->assertCount(3, $options);
        $this->assertSame('actif', $options[0]['value']);
        $this->assertSame('Actif', $options[0]['label']);
    }

    // ── StatutCommandeAchat ───────────────────────────────────────────────────

    public function test_statut_commande_achat_labels(): void
    {
        $this->assertSame('En cours', StatutCommandeAchat::EN_COURS->label());
        $this->assertSame('Réceptionnée', StatutCommandeAchat::RECEPTIONNEE->label());
        $this->assertSame('Annulée', StatutCommandeAchat::ANNULEE->label());
    }

    public function test_statut_commande_achat_options(): void
    {
        $options = StatutCommandeAchat::options();
        $this->assertCount(3, $options);
        foreach ($options as $option) {
            $this->assertArrayHasKey('value', $option);
            $this->assertArrayHasKey('label', $option);
        }
    }

    // ── StatutCommandeVente ───────────────────────────────────────────────────

    public function test_statut_commande_vente_labels(): void
    {
        $this->assertSame('En cours', StatutCommandeVente::EN_COURS->label());
        $this->assertSame('Livrée', StatutCommandeVente::LIVREE->label());
        $this->assertSame('Clôturée', StatutCommandeVente::CLOTUREE->label());
        $this->assertSame('Annulée', StatutCommandeVente::ANNULEE->label());
    }

    public function test_statut_commande_vente_options(): void
    {
        $options = StatutCommandeVente::options();
        $this->assertCount(4, $options);
        foreach ($options as $option) {
            $this->assertArrayHasKey('value', $option);
            $this->assertArrayHasKey('label', $option);
        }
    }

    // ── StatutFactureVente ────────────────────────────────────────────────────

    public function test_statut_facture_vente_labels(): void
    {
        $this->assertSame('Impayée', StatutFactureVente::IMPAYEE->label());
        $this->assertSame('Partiel', StatutFactureVente::PARTIEL->label());
        $this->assertSame('Payée', StatutFactureVente::PAYEE->label());
        $this->assertSame('Annulée', StatutFactureVente::ANNULEE->label());
    }

    public function test_statut_facture_vente_options(): void
    {
        $options = StatutFactureVente::options();
        $this->assertCount(4, $options);
        foreach ($options as $option) {
            $this->assertArrayHasKey('value', $option);
            $this->assertArrayHasKey('label', $option);
        }
    }

    // ── TypeVehicule ──────────────────────────────────────────────────────────

    public function test_type_vehicule_labels(): void
    {
        $this->assertSame('Camion', TypeVehicule::CAMION->label());
        $this->assertSame('Camionnette', TypeVehicule::CAMIONNETTE->label());
        $this->assertSame('Moto', TypeVehicule::MOTO->label());
        $this->assertSame('Tricycle', TypeVehicule::TRICYCLE->label());
        $this->assertSame('Voiture', TypeVehicule::VOITURE->label());
    }

    public function test_type_vehicule_default_capacite_packs(): void
    {
        $this->assertSame(200, TypeVehicule::CAMION->defaultCapacitePacks());
        $this->assertSame(80, TypeVehicule::CAMIONNETTE->defaultCapacitePacks());
        $this->assertSame(40, TypeVehicule::VOITURE->defaultCapacitePacks());
        $this->assertSame(30, TypeVehicule::TRICYCLE->defaultCapacitePacks());
        $this->assertSame(10, TypeVehicule::MOTO->defaultCapacitePacks());
    }

    public function test_type_vehicule_allowed_values(): void
    {
        $values = TypeVehicule::allowedValues();
        $this->assertContains('camion', $values);
        $this->assertContains('camionnette', $values);
        $this->assertContains('moto', $values);
        $this->assertContains('tricycle', $values);
        $this->assertContains('voiture', $values);
    }

    public function test_type_vehicule_options(): void
    {
        $options = TypeVehicule::options();
        $this->assertCount(5, $options);
        foreach ($options as $option) {
            $this->assertArrayHasKey('value', $option);
            $this->assertArrayHasKey('label', $option);
            $this->assertArrayHasKey('capacite_defaut', $option);
        }
    }

    public function test_type_vehicule_normalize_returns_valid_value(): void
    {
        $this->assertSame('camion', TypeVehicule::normalize('camion'));
        $this->assertSame('camion', TypeVehicule::normalize(' CAMION '));
        $this->assertSame('moto', TypeVehicule::normalize('moto'));
    }

    public function test_type_vehicule_normalize_returns_null_for_invalid(): void
    {
        $this->assertNull(TypeVehicule::normalize('invalid'));
        $this->assertNull(TypeVehicule::normalize(null));
    }

    // ── SiteRole ──────────────────────────────────────────────────────────────

    public function test_site_role_labels(): void
    {
        $this->assertSame('Responsable', SiteRole::RESPONSABLE->label());
        $this->assertSame('Employé', SiteRole::EMPLOYE->label());
    }

    public function test_site_role_options(): void
    {
        $options = SiteRole::options();
        $this->assertCount(2, $options);
        $this->assertSame('responsable', $options[0]['value']);
        $this->assertSame('Responsable', $options[0]['label']);
        $this->assertSame('employe', $options[1]['value']);
        $this->assertSame('Employé', $options[1]['label']);
    }

    public function test_site_role_cases(): void
    {
        $cases = SiteRole::cases();
        $this->assertCount(2, $cases);
    }

    // ── SiteStatut ────────────────────────────────────────────────────────────

    public function test_site_statut_labels(): void
    {
        $this->assertSame('Actif', SiteStatut::ACTIVE->label());
        $this->assertSame('Inactif', SiteStatut::INACTIVE->label());
        $this->assertSame('Suspendu', SiteStatut::SUSPENDUE->label());
    }

    public function test_site_statut_options(): void
    {
        $options = SiteStatut::options();
        $this->assertCount(3, $options);
    }

    // ── SiteType ──────────────────────────────────────────────────────────────

    public function test_site_type_labels(): void
    {
        $this->assertSame('Siège', SiteType::SIEGE->label());
        $this->assertSame('Usine', SiteType::USINE->label());
        $this->assertSame('Dépôt', SiteType::DEPOT->label());
        $this->assertSame('Agence', SiteType::AGENCE->label());
    }

    public function test_site_type_options(): void
    {
        $options = SiteType::options();
        $this->assertCount(4, $options);
    }

    // ── ProduitType ───────────────────────────────────────────────────────────

    public function test_produit_type_labels(): void
    {
        $this->assertSame('Matériel', ProduitType::MATERIEL->label());
        $this->assertSame('Service', ProduitType::SERVICE->label());
        $this->assertSame('Fabricable', ProduitType::FABRICABLE->label());
        $this->assertSame('Achat / Vente', ProduitType::ACHAT_VENTE->label());
    }

    public function test_produit_type_has_stock(): void
    {
        $this->assertTrue(ProduitType::MATERIEL->hasStock());
        $this->assertFalse(ProduitType::SERVICE->hasStock());
        $this->assertTrue(ProduitType::FABRICABLE->hasStock());
        $this->assertTrue(ProduitType::ACHAT_VENTE->hasStock());
    }

    public function test_produit_type_is_vendable(): void
    {
        $this->assertFalse(ProduitType::MATERIEL->isVendable());
        $this->assertFalse(ProduitType::SERVICE->isVendable());
        $this->assertTrue(ProduitType::FABRICABLE->isVendable());
        $this->assertTrue(ProduitType::ACHAT_VENTE->isVendable());
    }

    public function test_produit_type_is_achetable(): void
    {
        $this->assertTrue(ProduitType::MATERIEL->isAchetable());
        $this->assertFalse(ProduitType::SERVICE->isAchetable());
        $this->assertFalse(ProduitType::FABRICABLE->isAchetable());
        $this->assertTrue(ProduitType::ACHAT_VENTE->isAchetable());
    }

    public function test_produit_type_vendable_values(): void
    {
        $values = ProduitType::vendableValues();
        $this->assertContains('fabricable', $values);
        $this->assertContains('achat_vente', $values);
        $this->assertNotContains('materiel', $values);
        $this->assertNotContains('service', $values);
    }

    public function test_produit_type_achetable_values(): void
    {
        $values = ProduitType::achetableValues();
        $this->assertContains('materiel', $values);
        $this->assertContains('achat_vente', $values);
        $this->assertNotContains('fabricable', $values);
        $this->assertNotContains('service', $values);
    }

    public function test_produit_type_required_prices(): void
    {
        $this->assertContains('prix_achat', ProduitType::MATERIEL->requiredPrices());
        $this->assertEmpty(ProduitType::SERVICE->requiredPrices());
        $this->assertContains('prix_usine', ProduitType::FABRICABLE->requiredPrices());
        $this->assertContains('prix_vente', ProduitType::FABRICABLE->requiredPrices());
        $this->assertContains('prix_achat', ProduitType::ACHAT_VENTE->requiredPrices());
        $this->assertContains('prix_vente', ProduitType::ACHAT_VENTE->requiredPrices());
    }

    public function test_produit_type_values(): void
    {
        $values = ProduitType::values();
        $this->assertContains('materiel', $values);
        $this->assertContains('service', $values);
        $this->assertContains('fabricable', $values);
        $this->assertContains('achat_vente', $values);
    }

    public function test_produit_type_options(): void
    {
        $options = ProduitType::options();
        $this->assertCount(4, $options);
        foreach ($options as $option) {
            $this->assertArrayHasKey('value', $option);
            $this->assertArrayHasKey('label', $option);
        }
    }
}
