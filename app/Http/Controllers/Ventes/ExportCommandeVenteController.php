<?php

namespace App\Http\Controllers\Ventes;

use App\Enums\NatureOperation;
use App\Http\Controllers\Controller;
use App\Models\CommandeVente;
use App\Services\Ventes\VenteListExport;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Export "Exporter" de Ventes/Index.vue (bouton du même nom, cf. AGENTS.md #export) — sert aussi
 * distributions.export : même contrôleur que IndexCommandeVenteController, même filtre
 * nature_operation déterminé par le nom de route (jamais un paramètre client), cf. sa docblock.
 */
class ExportCommandeVenteController extends Controller
{
    public function __invoke(Request $request)
    {
        $this->authorize('viewAny', CommandeVente::class);
        abort_unless(auth()->user()->can('ventes.exporter'), 403);

        $user = auth()->user();
        $orgId = $user->organization_id;

        $query = CommandeVente::with(['vehicule.equipe.livreurs', 'client', 'site', 'facture'])
            ->where('organization_id', $orgId)
            ->orderByDesc('created_at');

        // Site (« Agence ») : même règle de périmètre que IndexCommandeVenteController — un
        // non-admin ne peut jamais exporter au-delà de ses propres sites, quoi qu'il envoie.
        if ($user->isAdmin()) {
            $siteIds = array_values(array_filter((array) $request->input('site_ids', [])));
            if (! empty($siteIds)) {
                $query->whereIn('site_id', $siteIds);
            }
        } else {
            $userSiteIds = $user->sites()->pluck('sites.id');
            if ($userSiteIds->isNotEmpty()) {
                $query->whereIn('site_id', $userSiteIds);
            }
        }

        $vehiculeIds = array_values(array_filter((array) $request->input('vehicule_ids', [])));
        if (! empty($vehiculeIds)) {
            $query->whereIn('vehicule_id', $vehiculeIds);
        }

        $statuts = array_values(array_filter((array) $request->input('statuts', [])));
        if (! empty($statuts)) {
            $query->whereIn('statut', $statuts);
        }

        // Période : date début seule → jusqu'à aujourd'hui (simple absence de borne haute,
        // aucune donnée future n'existe) ; aucune date → tout ; les deux → intervalle choisi.
        if ($dateDebut = $request->input('date_debut')) {
            $query->whereDate('created_at', '>=', $dateDebut);
        }
        if ($dateFin = $request->input('date_fin')) {
            $query->whereDate('created_at', '<=', $dateFin);
        }

        $natureFiltree = $request->route()?->getName() === 'distributions.export'
            ? NatureOperation::DISTRIBUTION_CLIENT
            : NatureOperation::VENTE_STANDARD;
        $query->where('nature_operation', $natureFiltree->value);

        $commandes = $query->get();

        $columns = array_values(array_filter((array) $request->input('columns', [])));
        $format = $request->input('format') === 'csv' ? 'csv' : 'xlsx';
        $prefix = $natureFiltree === NatureOperation::DISTRIBUTION_CLIENT ? 'distributions' : 'ventes';

        return Excel::download(
            new VenteListExport($commandes, $columns, $prefix),
            "{$prefix}-".now()->format('Y-m-d').".{$format}",
            $format === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX,
        );
    }
}
