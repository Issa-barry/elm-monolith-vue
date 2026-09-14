<?php

namespace App\Http\Controllers\Produits\Options;

use App\Http\Controllers\Controller;
use App\Models\OptionCatalogue;
use Illuminate\Http\RedirectResponse;

class DestroyOptionCatalogueController extends Controller
{
    public function __invoke(OptionCatalogue $option): RedirectResponse
    {
        $this->authorize('delete', $option);

        if ($option->is_system) {
            return back()->withErrors([
                'delete' => "« {$option->nom} » est une option système proposée par défaut et ne peut pas être supprimée. Vous pouvez toujours ajuster ses valeurs.",
            ]);
        }

        $option->delete();

        return back()->with('success', 'Option supprimée.');
    }
}
