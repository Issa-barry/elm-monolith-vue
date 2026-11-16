<?php

namespace App\Services\Client;

use App\Enums\ClientType;
use App\Models\Client;
use App\Models\User;
use App\Services\CashbackEligibiliteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Qualification commerciale d'un client créé à l'inscription d'un livreur ou d'un propriétaire
 * (ADR 0066) : l'utilisateur habilité choisit la nature (et le cashback qui en découle) ; nature,
 * cashback, `qualifie_le` et `qualifie_par` sont enregistrés ensemble, en une seule écriture sous
 * verrou — jamais un client à moitié qualifié, jamais deux qualifications concurrentes.
 *
 * Règles de cashback inchangées (CashbackEligibiliteService, CASHBACK-001/002) : un Revendeur est
 * obligatoirement éligible avec un montant par pack positif.
 */
class QualificationClientService
{
    /**
     * @param  array{type: string, cashback_eligible?: bool, cashback_montant_par_pack?: int|null}  $saisie
     *
     * @throws ValidationException
     */
    public function qualifier(Client $client, User $par, array $saisie): Client
    {
        $saisie = CashbackEligibiliteService::resoudreEligibilite($saisie);
        $eligible = (bool) ($saisie['cashback_eligible'] ?? false);
        $montant = $eligible ? ($saisie['cashback_montant_par_pack'] ?? null) : null;

        CashbackEligibiliteService::validerCoherence($saisie['type'], $eligible, $montant);

        return DB::transaction(function () use ($client, $par, $saisie, $eligible, $montant) {
            /** @var Client $verrouille */
            $verrouille = Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();

            if ($verrouille->estQualifie()) {
                throw ValidationException::withMessages([
                    'type' => 'Ce client est déjà qualifié.',
                ]);
            }

            $verrouille->forceFill([
                'type' => ClientType::from($saisie['type']),
                'cashback_eligible' => $eligible,
                'cashback_montant_par_pack' => $montant,
                'qualifie_le' => now(),
                'qualifie_par' => $par->id,
            ])->save();

            return $verrouille;
        });
    }
}
