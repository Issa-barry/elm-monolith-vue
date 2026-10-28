<?php

namespace App\Services\Rh;

use App\Models\ServiceRh;
use Illuminate\Validation\ValidationException;

/**
 * Création et modification d'un service RH, strictement dans l'organisation donnée : libellé et code
 * uniques par organisation, code proposé depuis le libellé (même règle que les fonctions RH).
 */
class ServiceRhService
{
    public function __construct(private readonly FonctionRhNamingService $naming) {}

    /** @param array{libelle: string, code?: ?string} $data */
    public function creer(array $data, string $organizationId): ServiceRh
    {
        $code = $this->codeValide($data, $organizationId);

        return ServiceRh::create([
            'organization_id' => $organizationId,
            'libelle' => $this->libelleValide($data['libelle'], $organizationId),
            'code' => $code ?? $this->naming->uniqueTrinome($data['libelle'], $organizationId, null, ServiceRh::class),
            'is_active' => true,
        ]);
    }

    /** @param array{libelle: string, code?: ?string} $data */
    public function modifier(ServiceRh $service, array $data): ServiceRh
    {
        $code = $this->codeValide($data, $service->organization_id, $service->id);

        $service->update([
            'libelle' => $this->libelleValide($data['libelle'], $service->organization_id, $service->id),
            'code' => $code ?? $service->code,
        ]);

        return $service;
    }

    private function libelleValide(string $libelle, string $organizationId, ?string $ignoreId = null): string
    {
        $libelle = trim($libelle);

        if ($this->naming->libelleTaken($libelle, $organizationId, $ignoreId, ServiceRh::class)) {
            throw ValidationException::withMessages([
                'libelle' => "Un service portant ce libellé existe déjà (« {$libelle} »).",
            ]);
        }

        return $libelle;
    }

    private function codeValide(array $data, string $organizationId, ?string $ignoreId = null): ?string
    {
        if (blank($data['code'] ?? null)) {
            return null;
        }

        $code = $this->naming->normalizeTrinome($data['code']);

        if ($this->naming->codeTaken($code, $organizationId, $ignoreId, ServiceRh::class)) {
            throw ValidationException::withMessages([
                'code' => "Ce code est déjà utilisé par un autre service (« {$code} »).",
            ]);
        }

        return $code;
    }
}
