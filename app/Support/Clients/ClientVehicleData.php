<?php

namespace App\Support\Clients;

use App\Services\ImportFlotte\Normalizers\PhoneNormalizer;
use App\Traits\PhoneHandlerTrait;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validation partagée par les contrôleurs `App\Http\Controllers\Clients\*VehiculeClientController`
 * — extrait de l'ancien `ClientVehicleController`.
 */
final class ClientVehicleData
{
    use PhoneHandlerTrait;

    /**
     * Aucun champ requis, y compris la plaque — cf. règle métier "le transport d'un client
     * externe est une information facultative, pas une condition métier". Le téléphone chauffeur suit
     * la même règle : facultatif, mais normalisé/validé selon le pays choisi (Guinée par défaut)
     * dès qu'il est renseigné.
     */
    public static function validated(Request $request): array
    {
        $data = $request->validate([
            'nom_vehicule' => 'nullable|string|max:100',
            'immatriculation' => 'nullable|string|max:20',
            'chauffeur_nom' => 'nullable|string|max:100',
            'chauffeur_telephone' => 'nullable|string|max:20',
            'chauffeur_code_pays' => ['nullable', Rule::in(array_keys(self::supportedPays()))],
        ]);

        if (empty($data['chauffeur_telephone'])) {
            $data['chauffeur_telephone'] = null;
            $data['chauffeur_code_pays'] = null;
            $data['chauffeur_code_phone_pays'] = null;
            $data['chauffeur_pays'] = null;

            return $data;
        }

        $codePays = $data['chauffeur_code_pays'] ?? 'GN';
        $normalise = (new PhoneNormalizer)->normalize($data['chauffeur_telephone'], $codePays);

        if ($normalise['erreur']) {
            throw ValidationException::withMessages([
                'chauffeur_telephone' => 'Téléphone chauffeur invalide : '.$normalise['erreur'],
            ]);
        }

        [$pays, $codePhonePays] = self::supportedPays()[$codePays];

        $data['chauffeur_telephone'] = $normalise['telephone'];
        $data['chauffeur_code_pays'] = $codePays;
        $data['chauffeur_code_phone_pays'] = $codePhonePays;
        $data['chauffeur_pays'] = $pays;

        return $data;
    }
}
