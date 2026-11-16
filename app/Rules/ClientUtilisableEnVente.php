<?php

namespace App\Rules;

use App\Models\Client;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Client choisi dans une vente (commande, précommande, PDV) — ADR 0066 :
 *  - il appartient à l'organisation de la vente et n'est pas supprimé (CLAUDE.md § 11) ;
 *  - il est qualifié commercialement : une fiche créée à l'inscription d'un livreur ou d'un
 *    propriétaire ne sert jamais à tarifer une vente avant qu'un utilisateur habilité ait confirmé
 *    sa nature.
 * Exception : le client déjà porté par la commande modifiée reste accepté tant qu'il n'est pas
 * remplacé (les commandes existantes restent modifiables, prix et historique inchangés).
 */
class ClientUtilisableEnVente implements ValidationRule
{
    public const MESSAGE_NON_QUALIFIE = 'Ce client a été créé à l\'inscription d\'un livreur ou d\'un propriétaire : sa nature commerciale doit être confirmée sur sa fiche (action « Qualifier ») avant de lui vendre.';

    public function __construct(
        private readonly string $organizationId,
        private readonly ?string $clientActuelId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $client = Client::query()
            ->select(['id', 'organization_id', 'qualifie_le'])
            ->where('organization_id', $this->organizationId)
            ->find((string) $value);

        if ($client === null) {
            $fail('Le client sélectionné est introuvable.');

            return;
        }

        if (! $client->estQualifie() && (string) $value !== $this->clientActuelId) {
            $fail(self::MESSAGE_NON_QUALIFIE);
        }
    }
}
