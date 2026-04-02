<?php

namespace App\Http\Controllers;

use App\Models\Proprietaire;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

const PROPRIETAIRE_PAYS = [
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

const PROPRIETAIRE_PHONE_LOCAL_LENGTHS = [
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

class ProprietaireController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewAny', Proprietaire::class);

        $proprietaires = Proprietaire::where('organization_id', auth()->user()->organization_id)
            ->orderBy('nom')
            ->get()
            ->map(fn (Proprietaire $p) => [
                'id' => $p->id,
                'nom' => $p->nom,
                'prenom' => $p->prenom,
                'nom_complet' => trim("{$p->prenom} {$p->nom}"),
                'email' => $p->email,
                'telephone' => $p->telephone,
                'code_phone_pays' => $p->code_phone_pays,
                'ville' => $p->ville,
                'pays' => $p->pays,
                'code_pays' => $p->code_pays,
                'adresse' => $p->adresse,
                'is_active' => $p->is_active,
            ]);

        return Inertia::render('Proprietaires/Index', [
            'proprietaires' => $proprietaires,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Proprietaire::class);

        return Inertia::render('Proprietaires/Create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Proprietaire::class);

        $orgId = auth()->user()->organization_id;
        abort_if(! $orgId, 403, "Votre compte n'est associé à aucune organisation.");

        $data = $request->validate([
            'nom' => 'required|string|max:255',
            'prenom' => 'required|string|max:255',
            'email' => 'nullable|email:rfc,dns|max:255',
            'telephone' => ['nullable', 'string', 'regex:/^[+0-9][0-9\s\-(). ]{4,24}$/'],
            'code_pays' => ['nullable', Rule::in(array_keys(PROPRIETAIRE_PAYS))],
            'ville' => 'nullable|string|max:100',
            'adresse' => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ], [
            'nom.required' => 'Le nom est obligatoire.',
            'prenom.required' => 'Le prénom est obligatoire.',
            'email.email' => "L'adresse email est invalide.",
            'telephone.regex' => 'Le numéro de téléphone est invalide.',
            'code_pays.in' => 'Pays invalide.',
        ]);

        if (! empty($data['code_pays']) && isset(PROPRIETAIRE_PAYS[$data['code_pays']])) {
            [$data['pays'], $data['code_phone_pays']] = PROPRIETAIRE_PAYS[$data['code_pays']];
        }

        $this->validateLocalPhoneLength($data);

        $data = $this->normalizeData($data);

        Proprietaire::create([...$data, 'organization_id' => $orgId]);

        return redirect()->route('proprietaires.index')
            ->with('success', 'Propriétaire créé avec succès.');
    }

    public function edit(Proprietaire $proprietaire): Response
    {
        $this->authorize('update', $proprietaire);

        [$telephone, $codePhonePays, $codePays, $pays] = $this->splitPhone(
            $proprietaire->telephone,
            $proprietaire->code_phone_pays,
            $proprietaire->code_pays,
            $proprietaire->pays,
        );

        return Inertia::render('Proprietaires/Edit', [
            'proprietaire' => [
                'id' => $proprietaire->id,
                'nom' => $proprietaire->nom,
                'prenom' => $proprietaire->prenom,
                'email' => $proprietaire->email,
                'telephone' => $telephone,
                'adresse' => $proprietaire->adresse,
                'ville' => $proprietaire->ville,
                'pays' => $pays,
                'code_pays' => $codePays,
                'code_phone_pays' => $codePhonePays,
                'is_active' => $proprietaire->is_active,
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

        $raw = trim($telephone);

        if ($codePhonePays && str_starts_with($raw, $codePhonePays)) {
            $local = substr($raw, strlen($codePhonePays));
            $localDigits = preg_replace('/\D+/', '', $local) ?: null;

            return [$localDigits, $codePhonePays, $codePays, $pays];
        }

        $sorted = PROPRIETAIRE_PAYS;
        uasort($sorted, fn ($a, $b) => strlen($b[1]) <=> strlen($a[1]));
        foreach ($sorted as $code => [$name, $dial]) {
            if (str_starts_with($raw, $dial)) {
                $local = substr($raw, strlen($dial));
                $localDigits = preg_replace('/\D+/', '', $local) ?: null;

                return [$localDigits, $dial, $code, $name];
            }
        }

        $digits = preg_replace('/\D+/', '', $raw) ?: null;

        return [$digits, $codePhonePays, $codePays, $pays];
    }

    public function update(Request $request, Proprietaire $proprietaire): RedirectResponse
    {
        $this->authorize('update', $proprietaire);

        $data = $request->validate([
            'nom' => 'required|string|max:255',
            'prenom' => 'required|string|max:255',
            'email' => 'nullable|email:rfc,dns|max:255',
            'telephone' => ['nullable', 'string', 'regex:/^[+0-9][0-9\s\-(). ]{4,24}$/'],
            'code_pays' => ['nullable', Rule::in(array_keys(PROPRIETAIRE_PAYS))],
            'ville' => 'nullable|string|max:100',
            'adresse' => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ], [
            'nom.required' => 'Le nom est obligatoire.',
            'prenom.required' => 'Le prénom est obligatoire.',
            'email.email' => "L'adresse email est invalide.",
            'telephone.regex' => 'Le numéro de téléphone est invalide.',
            'code_pays.in' => 'Pays invalide.',
        ]);

        if (! empty($data['code_pays']) && isset(PROPRIETAIRE_PAYS[$data['code_pays']])) {
            [$data['pays'], $data['code_phone_pays']] = PROPRIETAIRE_PAYS[$data['code_pays']];
        }

        $this->validateLocalPhoneLength($data);

        $data = $this->normalizeData($data);

        $proprietaire->update($data);

        return redirect()->route('proprietaires.edit', $proprietaire)
            ->with('success', 'Propriétaire mis à jour avec succès.');
    }

    private function validateLocalPhoneLength(array $data): void
    {
        if (empty($data['telephone']) || empty($data['code_pays'])) {
            return;
        }

        $expectedLength = PROPRIETAIRE_PHONE_LOCAL_LENGTHS[$data['code_pays']] ?? null;
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
        if (! empty($data['adresse'])) {
            $data['adresse'] = mb_convert_case(mb_strtolower($data['adresse'], 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        }
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

    public function destroy(Proprietaire $proprietaire): RedirectResponse
    {
        $this->authorize('delete', $proprietaire);
        $proprietaire->delete();

        return redirect()->route('proprietaires.index')
            ->with('success', 'Propriétaire supprimé.');
    }
}
