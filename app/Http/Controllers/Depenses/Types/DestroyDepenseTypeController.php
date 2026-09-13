<?php

namespace App\Http\Controllers\Depenses\Types;

use App\Http\Controllers\Controller;
use App\Models\DepenseType;
use Illuminate\Http\RedirectResponse;

class DestroyDepenseTypeController extends Controller
{
    public function __invoke(DepenseType $depense_type): RedirectResponse
    {
        $this->authorize('delete', $depense_type);

        if ($depense_type->depenses()->exists()) {
            return back()->withErrors(['delete' => 'Ce type est utilisé dans des dépenses. Désactivez-le plutôt que de le supprimer.']);
        }

        $depense_type->delete();

        return back()->with('success', 'Type de dépense supprimé.');
    }
}
