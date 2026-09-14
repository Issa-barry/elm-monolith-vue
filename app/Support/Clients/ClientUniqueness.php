<?php

namespace App\Support\Clients;

use App\Models\Client;
use Illuminate\Validation\ValidationException;

/**
 * Contrôles d'unicité téléphone/email par organisation — extrait de ClientController, partagé
 * entre la création et la modification (`$ignoreId` exclut le client en cours d'édition de lui-même).
 */
final class ClientUniqueness
{
    public static function assertPhoneUniqueInOrg(string $phone, string $orgId, ?string $ignoreId = null): void
    {
        $autreClient = Client::where('organization_id', $orgId)
            ->where('telephone', $phone)
            ->whereNull('deleted_at')
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->first();

        if ($autreClient) {
            throw ValidationException::withMessages([
                'telephone' => "Ce numéro de téléphone est déjà utilisé par un autre client : {$autreClient->nom_complet}.",
            ]);
        }
    }

    public static function assertEmailUniqueInOrg(string $email, string $orgId, ?string $ignoreId = null): void
    {
        $exists = Client::where('organization_id', $orgId)
            ->where('email', $email)
            ->whereNull('deleted_at')
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'email' => 'Cet email est déjà utilisé par un autre client.',
            ]);
        }
    }
}
