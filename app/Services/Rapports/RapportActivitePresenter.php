<?php

namespace App\Services\Rapports;

use App\Models\User;
use App\Support\Rapports\RapportPerimetre;

/**
 * Props de l'écran Rapports/Activite, communes au rapport d'activité et à « Ma situation » : même
 * moteur (RapportActiviteService), même page, seuls le périmètre et les filtres proposés changent.
 */
class RapportActivitePresenter
{
    public function __construct(
        private readonly RapportActiviteService $rapports,
        private readonly RapportPerimetreResolver $perimetres,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function props(User $user, RapportPerimetre $p): array
    {
        $baseUrl = $p->maSituation ? '/backoffice/ma-situation' : '/backoffice/rapports/activite';

        return [
            'mode' => $p->maSituation ? 'ma_situation' : 'rapport',
            'url' => $baseUrl,
            'export_url' => $baseUrl.'/export',
            'filters' => [
                'periode' => $p->periode->cle,
                'date_from' => $p->periode->cle === 'personnalisee' ? $p->debut()->toDateString() : null,
                'date_to' => $p->periode->cle === 'personnalisee' ? $p->fin()->toDateString() : null,
                'site_ids' => $p->maSituation || $p->siteIds === null ? [] : $p->siteIds,
                'agent_id' => $p->maSituation ? null : $p->agentId,
            ],
            'periode' => [
                ...$p->periode->pourFront(RapportPerimetreResolver::PERIODES),
                'libelle' => $p->periode->libelle(),
            ],
            'sites' => $p->maSituation ? [] : $this->perimetres->sitesProposes($user)->all(),
            'agents' => $p->maSituation
                ? []
                : $this->perimetres->agentsPour($p->organizationId, $p->siteIds)
                    ->map(fn (array $a) => ['value' => $a['id'], 'label' => $a['nom']])
                    ->all(),
            'agent' => $p->maSituation ? ['id' => $user->id, 'nom' => $user->name] : null,
            'limite_lignes' => RapportActiviteService::LIMITE_LIGNES,
            'rapport' => $this->rapports->rapport($p),
        ];
    }
}
