<?php

namespace App\Http\Controllers\Depenses;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\Depense;
use App\Services\AuditLogService;
use Illuminate\Http\RedirectResponse;

class DestroyDepenseController extends Controller
{
    public function __construct(
        private readonly AuditLogService $audit,
    ) {}

    public function __invoke(Depense $depense): RedirectResponse
    {
        $this->authorize('delete', $depense);

        $this->audit->record($depense, AuditEvent::DELETED, auth()->user(), null, null, [
            'module' => 'depenses',
            'site_id' => $depense->site_id,
            'description' => 'Dépense supprimée',
        ]);
        $depense->delete();

        return back()->with('success', 'Dépense supprimée.');
    }
}
