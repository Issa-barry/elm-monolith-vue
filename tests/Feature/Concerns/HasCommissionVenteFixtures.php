<?php

namespace Tests\Feature\Concerns;

use App\Enums\CommissionActivationStatut;
use App\Enums\CommissionStrategieAncrageSite;
use App\Enums\PrestataireType;
use App\Models\Categorie;
use App\Models\CommissionConsultantAffectation;
use App\Models\CommissionProcessus;
use App\Models\Personne;
use App\Models\Prestataire;
use App\Models\Site;

/**
 * Fixtures communes aux tests de commission sur commande Vente (site dépôt, catégorie unique,
 * processus Vente, consultant actif désigné) — partagées entre CommandeVenteGrossisteCommissionTest
 * et CommissionsAuditerVentesCommandTest pour éviter la duplication détectée par SonarCloud sur ces
 * deux fichiers (même scénario de base : catalogue à une catégorie, un site, un consultant).
 */
trait HasCommissionVenteFixtures
{
    private function creerSiteDepotTest(): Site
    {
        $site = Site::create([
            'organization_id' => $this->org->id,
            'nom' => 'Dépôt Test',
            'type' => 'depot',
            'localisation' => 'Conakry',
        ]);
        $this->user->sites()->attach($site->id, ['role' => 'employe', 'is_default' => false]);

        return $site;
    }

    private function creerCategorieBouteilleTest(): Categorie
    {
        return Categorie::create([
            'organization_id' => $this->org->id,
            'nom' => 'Bouteille d\'eau',
            'statut' => 'actif',
        ]);
    }

    private function creerProcessusVenteTest(string $declencheur): CommissionProcessus
    {
        return CommissionProcessus::firstOrCreate(
            ['organization_id' => $this->org->id, 'code' => CommissionProcessus::CODE_VENTE],
            [
                'libelle' => 'Vente',
                'declencheur' => $declencheur,
                'strategie_ancrage_site' => CommissionStrategieAncrageSite::OPERATION->value,
                'statut' => CommissionActivationStatut::ACTIF->value,
            ],
        );
    }

    private function creerConsultantDesigneTest(): Prestataire
    {
        $personne = Personne::create(['organization_id' => $this->org->id, 'nom' => 'Diallo', 'prenom' => 'Abdoulaye']);
        $consultant = Prestataire::create([
            'organization_id' => $this->org->id,
            'personne_id' => $personne->id,
            'type' => PrestataireType::CONSULTANT->value,
            'is_active' => true,
        ]);

        CommissionConsultantAffectation::create([
            'organization_id' => $this->org->id,
            'prestataire_id' => $consultant->id,
            'effective_from' => now()->subDay()->toDateString(),
            'statut' => 'active',
        ]);

        return $consultant;
    }
}
