<?php

namespace App\Http\Controllers\Api\Client;

use App\Http\Controllers\Controller;
use App\Models\Depense;
use App\Models\User;
use App\Models\Vehicule;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VehiculeFraisController extends Controller
{
    private const MOIS = [
        1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
        5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
        9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
    ];

    public function __invoke(Request $request, string $vehiculeId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $vehicule = Vehicule::find($vehiculeId);
        if (! $vehicule) {
            return response()->json([], 404);
        }

        $depenses = Depense::query()
            ->with('depenseType:id,libelle,code')
            ->where('vehicule_id', $vehiculeId)
            ->when($user->organization_id, fn ($q) => $q->where('organization_id', $user->organization_id))
            ->orderByDesc('date_depense')
            ->get()
            ->map(fn (Depense $d) => [
                'id'          => $d->id,
                'date'        => $d->date_depense?->toDateString(),
                'montant'     => (float) $d->montant,
                'type_code'   => $this->normalizeCode($d->depenseType?->code, $d->depenseType?->libelle),
                'type_label'  => $d->depenseType?->libelle ?? 'Autre',
                'statut'      => $d->statut ?? 'en_attente',
                'commentaire' => $d->commentaire,
                'mois'        => $d->date_depense ? $this->labelMois($d->date_depense) : '—',
            ]);

        return response()->json($depenses->values());
    }

    private function normalizeCode(?string $code, ?string $libelle): string
    {
        $raw = $code ?? $libelle ?? 'autre';
        return strtolower(
            str_replace(['é', 'è', 'ê', 'à', 'â', 'î', 'ô', 'û', ' '], ['e', 'e', 'e', 'a', 'a', 'i', 'o', 'u', '_'], $raw)
        );
    }

    private function labelMois(Carbon $date): string
    {
        return (self::MOIS[$date->month] ?? '') . ' ' . $date->year;
    }
}
