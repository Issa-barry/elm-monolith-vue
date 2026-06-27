<?php

namespace App\Support\Commission;

use Illuminate\Http\Request;

final class CommissionDetailFilters
{
    /**
     * Parsing partagé des filtres globaux (période + véhicule + agence) des pages
     * détail Commission (Vente / Logistique / Propriétaire). Le filtrage réel des
     * données reste dans chaque contrôleur, qui n'a pas le même modèle de données.
     *
     * @return array{periode: string, vehicule_ids: list<string>, site_ids: list<string>}
     */
    public static function fromRequest(Request $request, string $defaultPeriode = ''): array
    {
        return [
            'periode' => (string) $request->input('periode', $defaultPeriode),
            'vehicule_ids' => array_values(array_filter((array) $request->input('vehicule_id', []))),
            'site_ids' => array_values(array_filter((array) $request->input('site_ids', []))),
        ];
    }
}
