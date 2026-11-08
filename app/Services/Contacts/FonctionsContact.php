<?php

namespace App\Services\Contacts;

use App\Models\Client;
use App\Models\Employe;
use App\Models\EntrepriseInterlocuteur;
use App\Models\EntrepriseTierce;
use App\Models\Fournisseur;
use App\Models\Livreur;
use App\Models\Parrain;
use App\Models\Personne;
use App\Models\Prestataire;
use App\Models\Proprietaire;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Fonctions métier portées par une fiche du référentiel Contacts (ADR 0060), en lecture seule :
 * elles gardent leurs propres ids, que ventes, achats, commissions et comptabilité référencent.
 *
 * Cloisonnement (CONTACT-007) : une fiche d'une autre organisation est refusée ; un parrain n'est
 * retourné que s'il est dans le périmètre d'agences du lecteur (Parrain::visiblesPar(), le même
 * filtre que la liste des parrains). Les permissions par fonction (clients.read...) sont appliquées
 * par l'écran appelant (lot 2).
 *
 * @phpstan-type Fonction array{fonction: string, id: string, actif: ?bool, sous_categorie: ?string, entreprise_tierce_id?: string}
 */
final class FonctionsContact
{
    /**
     * @return list<Fonction>
     */
    public function pourPersonne(Personne $personne, User $lecteur): array
    {
        $this->assertMemeOrganisation($personne->organization_id, $lecteur);

        $fonctions = [];

        foreach (Client::where('personne_id', $personne->id)->orderBy('created_at')->get() as $client) {
            $fonctions[] = $this->ligne('client', $client->id, $client->is_active, $client->type?->value);
        }
        foreach (Fournisseur::where('personne_id', $personne->id)->orderBy('created_at')->get() as $fournisseur) {
            $fonctions[] = $this->ligne('fournisseur', $fournisseur->id, $fournisseur->is_active, null);
        }
        foreach (Prestataire::where('personne_id', $personne->id)->orderBy('created_at')->get() as $prestataire) {
            $fonctions[] = $this->ligne('prestataire', $prestataire->id, $prestataire->is_active, $prestataire->type?->value);
        }
        foreach (Proprietaire::where('personne_id', $personne->id)->orderBy('created_at')->get() as $proprietaire) {
            $fonctions[] = $this->ligne('proprietaire', $proprietaire->id, $proprietaire->is_active, $proprietaire->type?->value);
        }
        foreach (Parrain::visiblesPar($lecteur)->where('parrains.personne_id', $personne->id)->orderBy('parrains.created_at')->get() as $parrain) {
            $fonctions[] = $this->ligne('parrain', $parrain->id, $parrain->is_active, null);
        }
        foreach (Livreur::where('personne_id', $personne->id)->orderBy('created_at')->get() as $livreur) {
            $fonctions[] = $this->ligne('livreur', $livreur->id, $livreur->is_active, null);
        }
        foreach (Employe::where('personne_id', $personne->id)->orderBy('created_at')->get() as $employe) {
            $fonctions[] = $this->ligne('employe', $employe->id, null, $employe->statut?->value);
        }
        foreach (EntrepriseInterlocuteur::where('personne_id', $personne->id)->orderBy('created_at')->get() as $lien) {
            $fonctions[] = [
                ...$this->ligne('interlocuteur', $lien->id, $lien->is_active, null),
                'entreprise_tierce_id' => $lien->entreprise_tierce_id,
            ];
        }

        return $fonctions;
    }

    /**
     * Fonctions d'une entreprise : Fournisseur, Prestataire, Propriétaire entreprise (lot 5) ; Client
     * s'y ajoutera au lot 7 (rattachement par `entreprise_tierce_id`).
     *
     * @return list<Fonction>
     */
    public function pourEntreprise(EntrepriseTierce $entreprise, User $lecteur): array
    {
        $this->assertMemeOrganisation($entreprise->organization_id, $lecteur);

        $fonctions = [];

        foreach (Fournisseur::where('entreprise_tierce_id', $entreprise->id)->orderBy('created_at')->get() as $fournisseur) {
            $fonctions[] = $this->ligne('fournisseur', $fournisseur->id, $fournisseur->is_active, null);
        }
        foreach (Prestataire::where('entreprise_tierce_id', $entreprise->id)->orderBy('created_at')->get() as $prestataire) {
            $fonctions[] = $this->ligne('prestataire', $prestataire->id, $prestataire->is_active, $prestataire->type?->value);
        }
        foreach (Proprietaire::where('entreprise_tierce_id', $entreprise->id)->orderBy('created_at')->get() as $proprietaire) {
            $fonctions[] = $this->ligne('proprietaire', $proprietaire->id, $proprietaire->is_active, null);
        }

        return $fonctions;
    }

    /** @return Fonction */
    private function ligne(string $fonction, string $id, ?bool $actif, ?string $sousCategorie): array
    {
        return ['fonction' => $fonction, 'id' => $id, 'actif' => $actif, 'sous_categorie' => $sousCategorie];
    }

    private function assertMemeOrganisation(?string $organizationId, User $lecteur): void
    {
        if ($organizationId === null || $organizationId !== $lecteur->organization_id) {
            throw new AuthorizationException("Cette fiche n'appartient pas à votre organisation.");
        }
    }
}
