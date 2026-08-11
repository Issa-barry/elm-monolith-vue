<?php

namespace Tests\Unit;

use App\Enums\PackingStatut;
use App\Enums\PrestataireType;
use App\Enums\ProduitStatut;
use App\Enums\SiteRole;
use App\Enums\SiteStatut;
use App\Enums\SiteType;
use App\Enums\StatutCommandeAchat;
use App\Enums\StatutCommandeVente;
use App\Enums\StatutFactureVente;
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
    }

    public function test_prestataire_type_values(): void
    {
        $values = PrestataireType::values();
        $this->assertContains('machiniste', $values);
        $this->assertContains('mecanicien', $values);
        $this->assertContains('consultant', $values);
    }

    public function test_prestataire_type_options_returns_all_cases(): void
    {
        $options = PrestataireType::options();
        $this->assertCount(3, $options);
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
        $this->assertSame('Brouillon', StatutCommandeVente::BROUILLON->label());
        $this->assertSame('À charger', StatutCommandeVente::A_CHARGER->label());
        $this->assertSame('Chargement en cours', StatutCommandeVente::CHARGEMENT_EN_COURS->label());
        $this->assertSame('Livraison en cours', StatutCommandeVente::LIVRAISON_EN_COURS->label());
        $this->assertSame('Livrée', StatutCommandeVente::LIVREE->label());
        $this->assertSame('Clôturée', StatutCommandeVente::CLOTUREE->label());
        $this->assertSame('Annulée', StatutCommandeVente::ANNULEE->label());
    }

    public function test_statut_commande_vente_options(): void
    {
        $options = StatutCommandeVente::options();
        $this->assertCount(8, $options);
        foreach ($options as $option) {
            $this->assertArrayHasKey('value', $option);
            $this->assertArrayHasKey('label', $option);
        }
    }

    // ── StatutFactureVente ────────────────────────────────────────────────────

    public function test_statut_facture_vente_labels(): void
    {
        $this->assertSame('Impayée', StatutFactureVente::IMPAYEE->label());
        $this->assertSame('Partiellement payée', StatutFactureVente::PARTIEL->label());
        $this->assertSame('Payée', StatutFactureVente::PAYEE->label());
        $this->assertSame('Annulée', StatutFactureVente::ANNULEE->label());
    }

    public function test_statut_facture_vente_options(): void
    {
        $options = StatutFactureVente::options();
        $this->assertCount(5, $options);
        foreach ($options as $option) {
            $this->assertArrayHasKey('value', $option);
            $this->assertArrayHasKey('label', $option);
        }
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
    // Depuis la refonte CRUD (remplace l'ancien enum figé App\Enums\ProduitType), les
    // capacités structurelles vivent sur des lignes App\Models\ProduitType par organisation
    // (cf. ProduitTypeDefaultSeeder pour les 4 types historiques, testés en Feature via
    // ProduitTypeTest). Ici, on ne teste que les méthodes pures dérivées des attributs,
    // utilisables sur une instance non persistée.

    public function test_produit_type_required_prices_derivees_des_booleens(): void
    {
        $materiel = new \App\Models\ProduitType(['prix_achat_requis' => true, 'prix_usine_requis' => false, 'prix_vente_requis' => false]);
        $this->assertSame(['prix_achat'], $materiel->requiredPrices());

        $service = new \App\Models\ProduitType(['prix_achat_requis' => false, 'prix_usine_requis' => false, 'prix_vente_requis' => false]);
        $this->assertSame([], $service->requiredPrices());

        $fabricable = new \App\Models\ProduitType(['prix_achat_requis' => false, 'prix_usine_requis' => true, 'prix_vente_requis' => true]);
        $this->assertSame(['prix_usine', 'prix_vente'], $fabricable->requiredPrices());

        $achatVente = new \App\Models\ProduitType(['prix_achat_requis' => true, 'prix_usine_requis' => false, 'prix_vente_requis' => true]);
        $this->assertSame(['prix_achat', 'prix_vente'], $achatVente->requiredPrices());
    }

    public function test_produit_type_champ_prix_reference(): void
    {
        $type = new \App\Models\ProduitType(['champ_prix_reference' => 'prix_achat']);
        $this->assertSame('prix_achat', $type->champPrixReference());

        $sansReference = new \App\Models\ProduitType(['champ_prix_reference' => null]);
        $this->assertNull($sansReference->champPrixReference());
    }

    public function test_produit_type_vendable_achetable_sont_des_booleens_stockes(): void
    {
        $type = new \App\Models\ProduitType(['vendable' => true, 'achetable' => false]);
        $this->assertTrue($type->isVendable());
        $this->assertFalse($type->isAchetable());
    }
}
