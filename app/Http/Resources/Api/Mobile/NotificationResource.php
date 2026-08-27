<?php

namespace App\Http\Resources\Api\Mobile;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/**
 * Contrat API unique pour toute notification, quelle que soit la classe
 * Laravel `Notification` qui l'a créée — le frontend (Nuxt/mobile) ne doit
 * jamais connaître `App\Notifications\*` ni les payloads historiques
 * hétérogènes stockés en base (cf. rapport notifications, phase
 * "finalisation API", 2026-08-27).
 *
 * `CommandeValideeNotification` et `CommissionManquanteNotification`
 * (antérieures à la phase 1) stockent `commande_id`/`reference` au lieu de
 * `resource` — normalisées ici à la lecture, jamais en réécrivant les lignes
 * déjà en base. Toutes les classes phase 1
 * (CommissionGenereeNotification/CommissionPayeeNotification/
 * DepenseValideeNotification/TransfertCreeNotification/
 * TransfertReceptionneeNotification) stockent déjà `montant`/`resource`
 * directement — passthrough simple pour elles.
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /**
     * Identifiants techniques stables exposés à l'API — jamais le nom de
     * classe PHP ni l'ancien type snake_case interne. Liste blanche unique,
     * à étendre ici (et nulle part ailleurs) pour toute notification future.
     */
    private const TYPE_MAP = [
        'commande_validee' => 'delivery.assigned',
        'commission_manquante' => 'commission.missing',
        'commission_generee' => 'commission.generated',
        'commission_payee' => 'commission.paid',
        'depense_validee' => 'expense.validated',
        'transfert_cree' => 'transfer.created',
        'transfert_receptionne' => 'transfer.received',
    ];

    public function toArray(Request $request): array
    {
        $data = $this->data;

        return [
            'id' => $this->id,
            'type' => $this->resolveType($data),
            'titre' => $this->resolveTitre($data),
            'message' => $this->resolveMessage($data),
            'montant' => $this->resolveMontant($data),
            'resource' => $this->resolveResource($data),
            'lu' => $this->read_at !== null,
            'read_at' => $this->read_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
        ];
    }

    /**
     * Identifiant technique stable — jamais l'ancien type snake_case interne
     * ni un nom de classe PHP. Une valeur absente de TYPE_MAP (jamais censée
     * arriver, liste blanche exhaustive) reste passthrough plutôt que de
     * masquer un événement non encore mappé.
     */
    private function resolveType(array $data): string
    {
        $raw = (string) ($data['type'] ?? 'unknown');

        return self::TYPE_MAP[$raw] ?? $raw;
    }

    private function resolveTitre(array $data): ?string
    {
        return isset($data['titre']) ? (string) $data['titre'] : null;
    }

    private function resolveMessage(array $data): ?string
    {
        return isset($data['message']) ? (string) $data['message'] : null;
    }

    private function resolveMontant(array $data): ?float
    {
        return isset($data['montant']) ? (float) $data['montant'] : null;
    }

    /**
     * Passthrough pour les classes phase 1 (déjà `{type, id}`). Synthétisé
     * pour les 2 classes historiques, qui n'ont jamais eu de clé `resource` —
     * toutes deux portent `commande_id` (jamais fabriqué : absent = null).
     *
     * @return ?array{type: string, id: string}
     */
    private function resolveResource(array $data): ?array
    {
        if (isset($data['resource']['type'], $data['resource']['id'])) {
            return $data['resource'];
        }

        if (isset($data['commande_id'])) {
            return ['type' => 'commande_vente', 'id' => $data['commande_id']];
        }

        return null;
    }
}
