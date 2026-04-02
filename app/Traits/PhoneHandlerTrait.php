<?php

namespace App\Traits;

use Illuminate\Validation\ValidationException;

trait PhoneHandlerTrait
{
    protected static function supportedPays(): array
    {
        return [
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
    }

    protected static function phoneLocalLengths(): array
    {
        return [
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
    }

    private function splitPhone(?string $telephone, ?string $codePhonePays, ?string $codePays, ?string $pays): array
    {
        if (! $telephone) {
            return [null, $codePhonePays, $codePays, $pays];
        }

        $raw = trim($telephone);

        if ($codePhonePays && str_starts_with($raw, $codePhonePays)) {
            $local = substr($raw, strlen($codePhonePays));

            return [preg_replace('/\D+/', '', $local) ?: null, $codePhonePays, $codePays, $pays];
        }

        $sorted = static::supportedPays();
        uasort($sorted, fn ($a, $b) => strlen($b[1]) <=> strlen($a[1]));
        foreach ($sorted as $code => [$name, $dial]) {
            if (str_starts_with($raw, $dial)) {
                $local = substr($raw, strlen($dial));

                return [preg_replace('/\D+/', '', $local) ?: null, $dial, $code, $name];
            }
        }

        return [preg_replace('/\D+/', '', $raw) ?: null, $codePhonePays, $codePays, $pays];
    }

    private function validateLocalPhoneLength(array $data): void
    {
        if (empty($data['telephone']) || empty($data['code_pays'])) {
            return;
        }

        $expectedLength = static::phoneLocalLengths()[$data['code_pays']] ?? null;
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

    private function normalizePersonData(array $data): array
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
            $data['telephone'] = $this->buildInternationalPhone($data['telephone'], $data['code_phone_pays'] ?? null);
        }

        return $data;
    }

    private function buildInternationalPhone(string $telephone, ?string $codePhonePays): ?string
    {
        $telephone = trim($telephone);
        $telephoneDigits = preg_replace('/\D+/', '', $telephone) ?? '';

        if ($telephoneDigits === '') {
            return null;
        }

        if (str_starts_with($telephone, '+')) {
            return '+'.$telephoneDigits;
        }

        if ($codePhonePays) {
            $dialDigits = preg_replace('/\D+/', '', $codePhonePays) ?? '';
            $localDigits = preg_replace('/^0/', '', $telephoneDigits);

            return $dialDigits !== '' ? '+'.$dialDigits.$localDigits : $telephoneDigits;
        }

        return $telephoneDigits;
    }
}
