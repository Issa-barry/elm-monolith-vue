<?php

namespace App\Services\Contacts;

use App\Models\Client;
use App\Models\Personne;
use App\Support\Clients\ClientUniqueness;
use Illuminate\Support\Facades\DB;

/**
 * Règle R1 du référentiel Contacts (ADR 0060) : la fiche Contact (Personne) fait foi pour
 * l'identité ; les colonnes d'identité de `clients`, lues par les ventes, le PDV, le cashback et
 * l'espace client, en sont une copie alimentée uniquement par ce service.
 *
 * NON BRANCHÉ : aucun point d'écriture existant ne l'appelle. Le branchement (lot 7) n'aura lieu
 * qu'après le rapport des écarts Client/Personne et leur arbitrage (décision D16), et inversera
 * alors la règle CLIENTPERSONNE-002 (docs/identite-client-personne.md). D'ici là, l'identité d'un
 * client s'écrit exactement comme aujourd'hui.
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
