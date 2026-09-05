<?php

namespace App\Services;

use App\Models\Client;
use App\Models\EntrepriseTierce;
use App\Models\Personne;

/**
 * Recherche à qui appartient déjà un numéro de téléphone dans une organisation, tous types de
 * tiers confondus (Client, Fournisseur, Prestataire, Propriétaire, Livreur, Employé,
 * Utilisateur) — utilisé pour enrichir les messages de doublon avec le nom et le type du
 * propriétaire réel, plutôt qu'un message générique.
 *
 * Ne remplace AUCUN contrôle d'unicité existant (chaque contrôleur garde son propre contrôle
 * intra-type, ex: ClientController::assertPhoneUniqueInOrg(), EquipeLivraisonController::
 * detecterConflitTelephone()) — ce service ne fait qu'INFORMER quand le numéro existe ailleurs.
 * Ne pas en déduire une règle de blocage cross-type : partager un numéro entre rôles différents
 * (ex: un client qui est aussi fournisseur) est un cas légitime dans ce projet, cf.
 * Personne::resoudreOuCreer()/EntrepriseTierce::resoudreOuCreer() qui réutilisent délibérément une
 * identité existante plutôt que de le signaler comme un conflit.
 *
 * Client est un îlot de données à part (colonne `telephone` propre, jamais liée à `Personne`) —
 * c'est pourquoi il est vérifié séparément en premier, avant les régimes Personne/EntrepriseTierce
 * partagés par les autres rôles.
 */
class TelephoneOwnerLookupService
{
    /**
     * @return array{type: string, label: string, nom: string}|null
     */
    public static function find(string $organizationId, string $telephone, ?string $excludeClientId = null): ?array
    {
        $digits = Personne::normaliserTelephone($telephone);
        if ($digits === '') {
            return null;
        }

        $client = Client::where('organization_id', $organizationId)
            ->where('telephone', '+'.$digits)
            ->whereNull('deleted_at')
            ->when($excludeClientId, fn ($q) => $q->where('id', '!=', $excludeClientId))
            ->first();
        if ($client) {
            return ['type' => 'client', 'label' => 'Client', 'nom' => $client->nom_complet];
        }

        $entreprise = EntrepriseTierce::where('organization_id', $organizationId)
            ->where('telephone_normalise', $digits)
            ->with([
                'fournisseurs' => fn ($q) => $q->latest()->limit(1),
                'prestataires' => fn ($q) => $q->latest()->limit(1),
            ])
            ->first();
        if ($entreprise) {
            if ($entreprise->fournisseurs->isNotEmpty()) {
                return ['type' => 'fournisseur', 'label' => 'Fournisseur', 'nom' => $entreprise->raison_sociale];
            }
            if ($entreprise->prestataires->isNotEmpty()) {
                return ['type' => 'prestataire', 'label' => 'Prestataire', 'nom' => $entreprise->raison_sociale];
            }

            return ['type' => 'entreprise', 'label' => 'Tiers', 'nom' => $entreprise->raison_sociale];
        }

        $personne = Personne::where('organization_id', $organizationId)
            ->where('telephone_normalise', $digits)
            ->with(['proprietaire', 'livreur', 'employe', 'fournisseur', 'prestataire', 'user'])
            ->first();
        if ($personne) {
            return match (true) {
                (bool) $personne->proprietaire => ['type' => 'proprietaire', 'label' => 'Propriétaire', 'nom' => $personne->nom_complet],
                (bool) $personne->livreur => ['type' => 'livreur', 'label' => 'Livreur', 'nom' => $personne->nom_complet],
                (bool) $personne->fournisseur => ['type' => 'fournisseur', 'label' => 'Fournisseur', 'nom' => $personne->nom_complet],
                (bool) $personne->prestataire => ['type' => 'prestataire', 'label' => 'Prestataire', 'nom' => $personne->nom_complet],
                (bool) $personne->employe => ['type' => 'employe', 'label' => 'Employé', 'nom' => $personne->nom_complet],
                (bool) $personne->user => ['type' => 'utilisateur', 'label' => 'Utilisateur', 'nom' => $personne->nom_complet],
                default => ['type' => 'contact', 'label' => 'Contact', 'nom' => $personne->nom_complet],
            };
        }

        return null;
    }
}
