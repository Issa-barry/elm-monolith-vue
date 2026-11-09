<?php

namespace App\Services\Contacts;

use App\Models\Client;
use App\Models\Organization;
use App\Models\Personne;
use App\Support\Clients\ClientUniqueness;
use Illuminate\Support\Facades\DB;

/**
 * Règle R1 du référentiel Contacts (ADR 0060) : la fiche Contact (Personne) fait foi pour
 * l'identité ; les colonnes d'identité de `clients`, lues par les ventes, le PDV, le cashback et
 * l'espace client, en sont une copie alimentée uniquement par ce service.
 *
 * Branchée au lot 7a, mais ACTIVE par organisation seulement après le rapport des écarts et leur
 * arbitrage (D16) : `contacts:ecarts-clients --activer`, refusé tant qu'un conflit reste à arbitrer,
 * renseigne `organizations.synchronisation_identite_client_le`. Avant l'activation, l'identité d'un
 * client s'écrit exactement comme avant (CLIENTPERSONNE-002) ; après, toute modification de la
 * Personne — quel que soit l'écran : Client, Contacts, Propriétaire, espace client… — est recopiée
 * sur ses clients (Personne::booted()), et un client créé reçoit l'identité de sa Personne.
 *
 * Tout ou rien : les clients de la personne sont mis à jour dans une seule transaction ; un conflit
 * d'unicité (téléphone ou e-mail déjà porté par un autre client) refuse l'ensemble avec un message.
 * Seules les colonnes d'identité sont écrites : nature, cashback, dérogation et tarifs grossiste ne
 * sont jamais touchés.
 */
final class ClientIdentiteSynchronisation
{
    /** Colonnes de `clients` alimentées depuis la Personne. */
    public const COLONNES = [
        'nom', 'prenom', 'nom_complet', 'telephone', 'email',
        'pays', 'code_pays', 'code_phone_pays', 'ville', 'adresse',
    ];

    /**
     * @return int nombre de clients dont l'identité a changé
     */
    public function depuisPersonne(Personne $personne): int
    {
        return DB::transaction(function () use ($personne) {
            $clients = Client::where('personne_id', $personne->id)
                ->where('organization_id', $personne->organization_id)
                ->lockForUpdate()
                ->get();

            $modifies = 0;
            foreach ($clients as $client) {
                $identite = $this->identite($personne);

                if (! empty($identite['telephone'])) {
                    ClientUniqueness::assertPhoneUniqueInOrg($identite['telephone'], $client->organization_id, $client->id);
                }
                if (! empty($identite['email'])) {
                    ClientUniqueness::assertEmailUniqueInOrg($identite['email'], $client->organization_id, $client->id);
                }

                $client->fill($identite);
                if ($client->isDirty()) {
                    $client->save();
                    $modifies++;
                }
            }

            return $modifies;
        });
    }

    /** La synchronisation est-elle active pour cette organisation (D16) ? */
    public static function estActivePour(?string $organizationId): bool
    {
        return $organizationId !== null
            && Organization::whereKey($organizationId)->whereNotNull('synchronisation_identite_client_le')->exists();
    }

    /**
     * Client tout juste créé (back-office, inscription) : il reçoit l'identité de sa Personne, si la
     * synchronisation est active pour son organisation. Sans effet sinon.
     */
    public function apresCreation(Client $client): void
    {
        if ($client->personne_id === null || ! self::estActivePour($client->organization_id)) {
            return;
        }

        $personne = Personne::find($client->personne_id);
        if ($personne !== null) {
            $this->depuisPersonne($personne);
            $client->refresh();
        }
    }

    /** @return array<string, ?string> */
    public function identite(Personne $personne): array
    {
        $nomComplet = trim($personne->nom_complet);

        return [
            'nom' => $personne->nom,
            'prenom' => $personne->prenom,
            'nom_complet' => $nomComplet === '' ? null : $nomComplet,
            'telephone' => $personne->telephone,
            'email' => $personne->email,
            'pays' => $personne->pays,
            'code_pays' => $personne->code_pays,
            'code_phone_pays' => $personne->code_phone_pays,
            'ville' => $personne->ville,
            'adresse' => $personne->adresse,
        ];
    }
}
