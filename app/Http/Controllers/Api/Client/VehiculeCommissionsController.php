<?php

namespace App\Http\Controllers\Api\Client;

use App\Enums\StatutCommission;
use App\Http\Controllers\Controller;
use App\Models\CommissionPart;
use App\Models\Livreur;
use App\Models\Proprietaire;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehiculeCommissionsController extends Controller
{
    public function __invoke(Request $request, string $vehiculeId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $vehicule = Vehicule::find($vehiculeId);
        if (! $vehicule) {
            return response()->json(['message' => 'Véhicule introuvable.'], 404);
        }

        $proprietaire = Proprietaire::query()
            ->when($user->organization_id, fn ($q) => $q->where('organization_id', $user->organization_id))
            ->where(fn ($q) => $q->where('user_id', $user->id)
                ->when($user->telephone, fn ($q2) => $q2->orWhere('telephone', $user->telephone)))
            ->first();

        $livreur = Livreur::query()
            ->when($user->organization_id, fn ($q) => $q->where('organization_id', $user->organization_id))
            ->where(fn ($q) => $q->where('user_id', $user->id)
                ->when($user->telephone, fn ($q2) => $q2->orWhere('telephone', $user->telephone)))
            ->first();

        if ($proprietaire === null && $livreur === null) {
            return response()->json([]);
        }

        $parts = CommissionPart::query()
            ->join('commissions_ventes AS cv', 'cv.id', '=', 'commission_parts.commission_vente_id')
            ->join('commandes_ventes AS cmd', 'cmd.id', '=', 'cv.commande_vente_id')
            ->where('cv.vehicule_id', $vehiculeId)
            ->when($user->organization_id, fn ($q) => $q->where('cv.organization_id', $user->organization_id))
            ->where(function ($q) use ($proprietaire, $livreur) {
                if ($proprietaire !== null) {
                    $q->orWhere(fn ($sq) => $sq
                        ->where('commission_parts.type_beneficiaire', 'proprietaire')
                        ->where('commission_parts.proprietaire_id', $proprietaire->id)
                    );
                }
                if ($livreur !== null) {
                    $q->orWhere(fn ($sq) => $sq
                        ->where('commission_parts.type_beneficiaire', 'livreur')
                        ->where('commission_parts.livreur_id', $livreur->id)
                    );
                }
            })
            ->select([
                'commission_parts.id',
                'commission_parts.montant_net',
                'commission_parts.montant_verse',
                'commission_parts.statut',
                'cv.created_at AS commission_date',
                'cmd.reference',
            ])
            ->orderByDesc('cv.created_at')
            ->get()
            ->map(function ($row) {
                $date = $row->commission_date
                    ? \Carbon\Carbon::parse($row->commission_date)
                    : null;

                $statutMobile = match ($row->statut) {
                    StatutCommission::PAYE->value, 'paye' => 'paye',
                    default => 'en_attente',
                };

                return [
                    'id'              => $row->id,
                    'reference'       => $row->reference ?? '—',
                    'date'            => $date?->toISOString(),
                    'montant_net'     => (float) $row->montant_net,
                    'montant_verse'   => (float) $row->montant_verse,
                    'montant_restant' => max(0.0, (float) $row->montant_net - (float) $row->montant_verse),
                    'statut'          => $statutMobile,
                    'mois'            => $date ? $this->labelMois($date) : '—',
                ];
            });

        return response()->json($parts->values());
    }

    private function labelMois(\Carbon\Carbon $date): string
    {
        $mois = [
            1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
            5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
            9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
        ];

        return ($mois[$date->month] ?? '') . ' ' . $date->year;
    }
}
