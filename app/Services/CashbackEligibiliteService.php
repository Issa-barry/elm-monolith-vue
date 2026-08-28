<?php

namespace App\Services;

use App\Enums\ClientType;
use Illuminate\Validation\ValidationException;

/**
 * Règle de cohérence du cashback client — CASHBACK-001/002 (cf. docs/cashback.md). Un client
 * Revendeur est obligatoirement éligible au cashback, avec un montant par pack strictement
 * positif ; les autres natures (Externe/Distributeur) restent facultatives mais doivent, si
 * activées, porter elles aussi un montant positif — jamais un cashback "actif" sans tarif
 * applicable. Seul point d'entrée pour créer/modifier un client (ClientController::store()/
 * update()), jamais une règle dupliquée côté frontend uniquement (cf. section 14 du chantier :
 * aucun canal — Web, API, import — ne doit pouvoir la contourner).
 */
class CashbackEligibiliteService
{
    /**
     * Complète cashback_eligible=true pour un Revendeur UNIQUEMENT quand le champ est absent du
     * payload (ex : appel direct qui omettrait le champ) — ne corrige jamais silencieusement une
     * valeur explicitement soumise à false, qui doit au contraire être REJETÉE par
     * validerCoherence() ci-dessous. L'UI normale (ClientForm.vue) n'affiche même plus de choix
     * Oui/Non pour un Revendeur et envoie toujours true : ce repli ne joue donc qu'en défense
     * contre un appel direct incomplet, jamais pour maquiller une tentative de désactivation.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function resoudreEligibilite(array $data): array
    {
        if (($data['type'] ?? null) === ClientType::REVENDEUR->value && ! array_key_exists('cashback_eligible', $data)) {
            $data['cashback_eligible'] = true;
        }

        return $data;
    }

    /**
     * @throws ValidationException
     */
    public static function validerCoherence(string $type, bool $eligible, ?int $montantParPack): void
    {
        $estRevendeur = $type === ClientType::REVENDEUR->value;

        if ($estRevendeur && ! $eligible) {
            // Ne devrait jamais survenir après resoudreEligibilite() côté formulaire normal, mais
            // reste vérifié ici : aucun appelant (y compris direct) ne doit pouvoir désactiver le
            // cashback d'un Revendeur.
            throw ValidationException::withMessages([
                'cashback_eligible' => 'Le cashback est obligatoire pour un client Revendeur.',
            ]);
        }

        if (! $eligible) {
            return;
        }

        if ($montantParPack === null || $montantParPack <= 0) {
            throw ValidationException::withMessages([
                'cashback_montant_par_pack' => $estRevendeur
                    ? 'Renseignez un montant de cashback par pack pour ce client Revendeur.'
                    : 'Renseignez un montant de cashback par pack pour activer le cashback.',
            ]);
        }
    }
}
