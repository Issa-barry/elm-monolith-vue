<?php

namespace App\Http\Resources\Api\Client;

use App\Models\Client;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fiche de profil métier normalisée, quelle que soit la source réelle
 * (Proprietaire, Client ou Livreur) — le frontend reçoit toujours la même
 * forme, `entreprise` valant `null` quand non applicable plutôt qu'une absence
 * de clé. N'expose que des colonnes réellement stockées (Personne/Proprietaire/
 * Client) — jamais de champ inventé pour satisfaire une maquette (ex: pas de
 * "SIRET", notion absente du modèle ELM).
 */
class ProfileResource extends JsonResource
{
    public function __construct(
        Proprietaire|Client|Livreur|null $resource,
        private readonly User $user,
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'user' => [
                'id' => $this->user->id,
                'telephone' => $this->user->telephone,
                'email' => $this->user->email,
            ],
            'profile' => match (true) {
                $this->resource instanceof Proprietaire => $this->fromProprietaire($this->resource),
                $this->resource instanceof Client => $this->fromClient($this->resource),
                $this->resource instanceof Livreur => $this->fromLivreur($this->resource),
                default => null,
            },
        ];
    }

    private function fromProprietaire(Proprietaire $proprietaire): array
    {
        return [
            'type' => 'proprietaire',
            'identite' => [
                'prenom' => $proprietaire->prenom,
                'nom' => $proprietaire->nom,
                'surnom' => $proprietaire->surnom,
                'nom_affichage' => $proprietaire->nom_affichage,
            ],
            'entreprise' => $proprietaire->est_entreprise ? [
                'raison_sociale' => $proprietaire->raison_sociale,
            ] : null,
            'contact' => [
                'telephone' => $proprietaire->telephone,
                'email' => $proprietaire->email,
            ],
            'localisation' => [
                'pays' => $proprietaire->pays,
                'code_pays' => $proprietaire->code_pays,
                'code_phone_pays' => $proprietaire->code_phone_pays,
                'ville' => $proprietaire->ville,
                'adresse' => $proprietaire->adresse,
            ],
            'actif' => $proprietaire->is_active,
            'notifications' => $this->user->notificationPreferences(),
        ];
    }

    private function fromClient(Client $client): array
    {
        return [
            'type' => 'client',
            'identite' => [
                'prenom' => $client->prenom,
                'nom' => $client->nom,
                'surnom' => null,
                'nom_affichage' => $client->nom_complet,
            ],
            'entreprise' => null,
            'contact' => [
                'telephone' => $client->telephone,
                'email' => $client->email,
            ],
            'localisation' => [
                'pays' => $client->pays,
                'code_pays' => $client->code_pays,
                'code_phone_pays' => $client->code_phone_pays,
                'ville' => $client->ville,
                'adresse' => $client->adresse,
            ],
            'actif' => $client->is_active,
            'notifications' => $this->user->notificationPreferences(),
        ];
    }

    private function fromLivreur(Livreur $livreur): array
    {
        $personne = $livreur->personne;

        return [
            'type' => 'livreur',
            'identite' => [
                'prenom' => $personne?->prenom,
                'nom' => $personne?->nom,
                'surnom' => $personne?->surnom,
                'nom_affichage' => $livreur->libelleAffichage(),
            ],
            'entreprise' => null,
            'contact' => [
                'telephone' => $livreur->telephone,
                'email' => $personne?->email,
            ],
            'localisation' => [
                'pays' => $personne?->pays,
                'code_pays' => $personne?->code_pays,
                'code_phone_pays' => $personne?->code_phone_pays,
                'ville' => $personne?->ville,
                'adresse' => $personne?->adresse,
            ],
            'actif' => $livreur->is_active,
            'notifications' => $this->user->notificationPreferences(),
        ];
    }
}
