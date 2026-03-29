<?php

namespace App\Http\Controllers;

use App\Models\Livreur;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

const LIVREUR_PAYS = [
    'GN' => ['Guinée',               '+224'],
    'GW' => ['Guinée-Bissau',        '+245'],
    'SN' => ['Sénégal',              '+221'],
    'ML' => ['Mali',                 '+223'],
    'CI' => ["Côte d'Ivoire",        '+225'],
    'LR' => ['Liberia',              '+231'],
    'SL' => ['Sierra Leone',         '+232'],
    'FR' => ['France',               '+33'],
    'CN' => ['Chine',                '+86'],
    'AE' => ['Émirats arabes unis',  '+971'],
    'IN' => ['Inde',                 '+91'],
];

const LIVREUR_PHONE_LOCAL_LENGTHS = [
    'GN' => 9,
    'GW' => 7,
    'SN' => 9,
    'ML' => 8,
    'CI' => 10,
    'LR' => 8,
    'SL' => 8,
    'FR' => 9,
    'CN' => 11,
    'AE' => 9,
    'IN' => 10,
];

class LivreurController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Livreur::class);

        $livreurs = Livreur::where('organization_id', auth()->user()->organization_id)
            ->orderBy('nom')
            ->get()
            ->map(fn (Livreur $l) => [
                'id' => $l->id,
                'nom' => $l->nom,
                'prenom' => $l->prenom,
                'nom_complet' => trim("{$l->prenom} {$l->nom}"),
                'email' => $l->email,
                'telephone' => $l->telephone,
                'code_phone_pays' => $l->code_phone_pays,
                'adresse' => $l->adresse,
                'ville' => $l->ville,
                'pays' => $l->pays,
                'code_pays' => $l->code_pays,
                'is_active' => $l->is_active,
            ]);

        return Inertia::render('Livreurs/Index', [
            'livreurs' => $livreurs,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Livreur::class);

        return Inertia::render('Livreurs/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Livreur::class);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $data = $request->validate([
            'nom' => 'required|string|max:255',
            'prenom' => 'required|string|max:255',
            'email' => 'nullable|email:rfc,dns|max:255|unique:livreurs,email',
            'telephone' => ['required', 'string', 'regex:/^[+0-9][0-9\s\-(). ]{4,24}$/', 'unique:livreurs,telephone'],
            'code_pays' => ['required', Rule::in(array_keys(LIVREUR_PAYS))],
            'ville' => 'required|string|max:100',
            'adresse' => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ], [
            'nom.required' => 'Le nom est obligatoire.',
            'prenom.required' => 'Le prénom est obligatoire.',
            'email.email' => "L'adresse email est invalide.",
            'email.unique' => 'Cet email est déjà utilisé.',
            'telephone.required' => 'Le numéro de téléphone est obligatoire.',
            'telephone.regex' => 'Le numéro de téléphone est invalide.',
            'telephone.unique' => 'Ce numéro de téléphone est déjà utilisé.',
            'code_pays.required' => 'Le pays est obligatoire.',
            'code_pays.in' => 'Pays invalide.',
            'ville.required' => 'La ville est obligatoire.',
        ]);

        if (! empty($data['code_pays']) && isset(LIVREUR_PAYS[$data['code_pays']])) {
            [$data['pays'], $data['code_phone_pays']] = LIVREUR_PAYS[$data['code_pays']];
        }

        $this->validateLocalPhoneLength($data);

        $data = $this->normalizeData($data);

        Livreur::create([...$data, 'organization_id' => $orgId]);

        return redirect()->route('livreurs.index')
            ->with('success', 'Livreur créé avec succès.');
    }

    public function edit(Livreur $livreur): Response
    {
        $this->authorize('update', $livreur);

        [$telephone, $codePhonePays, $codePays, $pays] = $this->splitPhone(
            $livreur->telephone,
            $livreur->code_phone_pays,
            $livreur->code_pays,
            $livreur->pays,
        );

        return Inertia::render('Livreurs/Edit', [
            'livreur' => [
                'id' => $livreur->id,
                'nom' => $livreur->nom,
                'prenom' => $livreur->prenom,
                'email' => $livreur->email,
                'telephone' => $telephone,
                'adresse' => $livreur->adresse,
                'ville' => $livreur->ville,
                'pays' => $pays,
                'code_pays' => $codePays,
                'code_phone_pays' => $codePhonePays,
                'is_active' => $livreur->is_active,
            ],
        ]);
    }

    /**
     * Sépare un numéro complet (+224622000003) en [chiffres_locaux, dial, code_pays, pays].
     * Si le numéro ne commence pas par un dial connu, le retourne tel quel.
     */
    private function splitPhone(?string $telephone, ?string $codePhonePays, ?string $codePays, ?string $pays): array
    {
        if (! $telephone) {
            return [null, $codePhonePays, $codePays, $pays];
        }

        // Si on connaît déjà le dial et que le numéro commence par lui → strip
        if ($codePhonePays && str_starts_with($telephone, $codePhonePays)) {
            return [substr($telephone, strlen($codePhonePays)), $codePhonePays, $codePays, $pays];
        }

        // Auto-détection : trier par longueur de dial décroissante (évite +97 avant +971)
        $sorted = LIVREUR_PAYS;
        uasort($sorted, fn ($a, $b) => strlen($b[1]) <=> strlen($a[1]));
        foreach ($sorted as $code => [$name, $dial]) {
            if (str_starts_with($telephone, $dial)) {
                return [substr($telephone, strlen($dial)), $dial, $code, $name];
            }
        }

        return [$telephone, $codePhonePays, $codePays, $pays];
    }

    public function update(Request $request, Livreur $livreur): RedirectResponse
    {
        $this->authorize('update', $livreur);

        $data = $request->validate([
            'nom' => 'required|string|max:255',
            'prenom' => 'required|string|max:255',
            'email' => ['nullable', 'email:rfc,dns', 'max:255', Rule::unique('livreurs', 'email')->ignore($livreur->id)],
            'telephone' => ['required', 'string', 'regex:/^[+0-9][0-9\s\-(). ]{4,24}$/', Rule::unique('livreurs', 'telephone')->ignore($livreur->id)],
            'code_pays' => ['required', Rule::in(array_keys(LIVREUR_PAYS))],
            'ville' => 'required|string|max:100',
            'adresse' => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ], [
            'nom.required' => 'Le nom est obligatoire.',
            'prenom.required' => 'Le prénom est obligatoire.',
            'email.email' => "L'adresse email est invalide.",
            'email.unique' => 'Cet email est déjà utilisé.',
            'telephone.required' => 'Le numéro de téléphone est obligatoire.',
            'telephone.regex' => 'Le numéro de téléphone est invalide.',
            'telephone.unique' => 'Ce numéro de téléphone est déjà utilisé.',
            'code_pays.required' => 'Le pays est obligatoire.',
            'code_pays.in' => 'Pays invalide.',
            'ville.required' => 'La ville est obligatoire.',
        ]);

        if (! empty($data['code_pays']) && isset(LIVREUR_PAYS[$data['code_pays']])) {
            [$data['pays'], $data['code_phone_pays']] = LIVREUR_PAYS[$data['code_pays']];
        }

        $this->validateLocalPhoneLength($data);

        $data = $this->normalizeData($data);

        $livreur->update($data);

        return redirect()->route('livreurs.index')
            ->with('success', 'Livreur mis à jour avec succès.');
    }

    private function validateLocalPhoneLength(array $data): void
    {
        if (empty($data['telephone']) || empty($data['code_pays'])) {
            return;
        }

        $expectedLength = LIVREUR_PHONE_LOCAL_LENGTHS[$data['code_pays']] ?? null;
        if (! $expectedLength) {
            return;
        }

        $digits = preg_replace('/\D+/', '', (string) $data['telephone']) ?? '';
        if ($digits === '') {
            return;
        }

        $isValidLength = strlen($digits) === $expectedLength
            || (strlen($digits) === ($expectedLength + 1) && str_starts_with($digits, '0'));

        if (! $isValidLength) {
            throw ValidationException::withMessages([
                'telephone' => "Le numero doit contenir {$expectedLength} chiffres (ou ".($expectedLength + 1).' avec un 0 initial).',
            ]);
        }
    }

    private function normalizeData(array $data): array
    {
        if (! empty($data['nom'])) {
            $data['nom'] = mb_strtoupper($data['nom'], 'UTF-8');
        }
        if (! empty($data['prenom'])) {
            $data['prenom'] = mb_convert_case(mb_strtolower($data['prenom'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        }
        if (! empty($data['ville'])) {
            $data['ville'] = mb_convert_case(mb_strtolower($data['ville'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        }
        // Combiner dial + chiffres locaux → numéro international complet
        // (seulement si le numéro n'est pas déjà au format international)
        if (! empty($data['telephone'])) {
            $telephone = trim((string) $data['telephone']);
            $telephoneDigits = preg_replace('/\D+/', '', $telephone) ?? '';

            if ($telephoneDigits === '') {
                $data['telephone'] = null;
            } elseif (str_starts_with($telephone, '+')) {
                $data['telephone'] = '+'.$telephoneDigits;
            } elseif (! empty($data['code_phone_pays'])) {
                $dialDigits = preg_replace('/\D+/', '', (string) $data['code_phone_pays']) ?? '';
                $localDigits = preg_replace('/^0/', '', $telephoneDigits);
                $data['telephone'] = $dialDigits !== ''
                    ? '+'.$dialDigits.$localDigits
                    : $telephoneDigits;
            } else {
                $data['telephone'] = $telephoneDigits;
            }
        }

        return $data;
    }

    public function destroy(Livreur $livreur): RedirectResponse
    {
        $this->authorize('delete', $livreur);
        $livreur->delete();

        return redirect()->route('livreurs.index')
            ->with('success', 'Livreur supprimé.');
    }
}
