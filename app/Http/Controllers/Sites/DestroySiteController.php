<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use Illuminate\Http\RedirectResponse;

class DestroySiteController extends Controller
{
    public function __invoke(Site $site): RedirectResponse
    {
        $this->authorize('delete', $site);

        if ($site->enfants()->exists()) {
            return back()->with('error', 'Impossible de supprimer ce site car il possède des sites enfants. Veuillez d\'abord les réaffecter ou les supprimer.');
        }

        $site->delete();

        return redirect()->route('sites.index')
            ->with('success', 'Site supprimé.');
    }
}
