<?php

namespace App\Http\Controllers\Depenses\Types;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDepenseTypeRequest;
use App\Models\DepenseType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

class StoreDepenseTypeController extends Controller
{
    public function __invoke(StoreDepenseTypeRequest $request): RedirectResponse
    {
        $this->authorize('create', DepenseType::class);

        $orgId = auth()->user()->organization_id;

        try {
            DepenseType::create([
                ...$request->validated(),
                'organization_id' => $orgId,
                'code' => $this->generateCode($request->libelle, $orgId),
            ]);
        } catch (UniqueConstraintViolationException) {
            return back()->withErrors([
                'libelle' => 'Un type de dépense avec un nom équivalent existe déjà (ou a été supprimé) dans cette organisation.',
            ]);
        }

        return back()->with('success', 'Type de dépense créé.');
    }

    private function generateCode(string $libelle, string $orgId, ?string $excludeId = null): string
    {
        $base = Str::slug($libelle, '_');
        $code = $base;
        $i = 2;

        while (
            DepenseType::withTrashed()
                ->where('organization_id', $orgId)
                ->where('code', $code)
                ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
                ->exists()
        ) {
            $code = $base.'_'.$i++;
        }

        return $code;
    }
}
