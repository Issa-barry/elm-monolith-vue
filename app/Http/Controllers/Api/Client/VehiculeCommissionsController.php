<?php

namespace App\Http\Controllers\Api\Client;

use App\Enums\StatutCommission;
use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Models\CommissionEnveloppePart;
use App\Models\User;
use App\Models\Vehicule;
use App\Services\Client\ClientIdentityResolver;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehiculeCommissionsController extends Controller
{
    public function __invoke(Request $request, string $vehiculeId, ClientIdentityResolver $identityResolver): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $vehicule = Vehicule::find($vehiculeId);
        if (! $vehicule) {
            return response()->json(['message' => 'Véhicule introuvable.'], 404);
        }

        $identity = $identityResolver->resolve($user);
        $proprietaire = $identity->proprietaire;
        $livreur = $identity->livreur;

        if ($proprietaire === null && $livreur === null) {
            return response()->json([]);
        }

        $parts = CommissionEnveloppePart::query()
            ->join('commission_enveloppes AS ce', 'ce.id', '=', 'commission_enveloppe_parts.enveloppe_id')
            ->join('commandes_ventes AS cmd', function ($join) {
                $join->on('cmd.id', '=', 'ce.source_id')
                    ->where('ce.source_type', '=', CommandeVente::class);
            })
            ->where('cmd.vehicule_id', $vehiculeId)
            ->when($identity->organizationId, fn ($q) => $q->where('ce.organization_id', $identity->organizationId))
            ->where(function ($q) use ($proprietaire, $livreur) {
                if ($proprietaire !== null) {
                    $q->orWhere(fn ($sq) => $sq
                        ->where('commission_enveloppe_parts.beneficiaire_type', 'proprietaire')
                        ->where('commission_enveloppe_parts.beneficiaire_id', $proprietaire->id)
                    );
                }
                if ($livreur !== null) {
                    $q->orWhere(fn ($sq) => $sq
                        ->where('commission_enveloppe_parts.beneficiaire_type', 'livreur')
                        ->where('commission_enveloppe_parts.beneficiaire_id', $livreur->id)
                    );
                }
            })
            ->select([
                'commission_enveloppe_parts.id',
                'commission_enveloppe_parts.montant_net',
                'commission_enveloppe_parts.montant_actuel',
                'commission_enveloppe_parts.montant_verse',
                'commission_enveloppe_parts.statut',
                'ce.earned_at AS commission_date',
                'cmd.reference',
            ])
            ->orderByDesc('ce.earned_at')
            ->get()
            ->map(function ($row) {
                $date = $row->commission_date
                    ? Carbon::parse($row->commission_date)
                    : null;

                $statutMobile = match ($row->statut) {
                    StatutCommission::PAYE->value => 'paye',
                    StatutCommission::PARTIEL->value => 'partiel',
                    default => 'en_attente',
                };

                $montantAPayer = $row->montant_actuel !== null ? (float) $row->montant_actuel : (float) $row->montant_net;

                return [
                    'id' => $row->id,
                    'reference' => $row->reference ?? '—',
                    'date' => $date?->toISOString(),
                    'montant_net' => (float) $row->montant_net,
                    'montant_a_payer' => $montantAPayer,
                    'montant_verse' => (float) $row->montant_verse,
                    'montant_restant' => max(0.0, $montantAPayer - (float) $row->montant_verse),
                    'statut' => $statutMobile,
                    'mois' => $date ? $this->labelMois($date) : '—',
                ];
            });

        return response()->json($parts->values());
    }

    private function labelMois(Carbon $date): string
    {
        $mois = [
            1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
            5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
            9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
        ];

        return ($mois[$date->month] ?? '').' '.$date->year;
    }
}
