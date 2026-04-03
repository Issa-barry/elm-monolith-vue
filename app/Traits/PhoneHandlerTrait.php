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

    protected function resolveCountryData(array $data): array
    {
        if (! empty($data['code_pays']) && isset(static::supportedPays()[$data['code_pays']])) {
            [$data['pays'], $data['code_phone_pays']] = static::supportedPays()[$data['code_pays']];
        }

        return $data;
    }

    private function ucTitle(string $value): string
    {
        return mb_convert_case(mb_strtolower($value, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    }

    private function digitsOnly(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function splitPhone(?string $telephone, ?string $codePhonePays, ?string $codePays, ?string $pays): array
    {
        if (! $telephone) {
            return [null, $codePhonePays, $codePays, $pays];
        }

        $raw = trim($telephone);
        $result = [$this->digitsOnly($raw) ?: null, $codePhonePays, $codePays, $pays];

        if ($codePhonePays && str_starts_with($raw, $codePhonePays)) {
            $result[0] = $this->digitsOnly(substr($raw, strlen($codePhonePays))) ?: null;

            return $result;
        }

        $sorted = static::supportedPays();
        uasort($sorted, fn ($a, $b) => strlen($b[1]) <=> strlen($a[1]));
        foreach ($sorted as $code => [$name, $dial]) {
            if (str_starts_with($raw, $dial)) {
                $result = [$this->digitsOnly(substr($raw, strlen($dial))) ?: null, $dial, $code, $name];
                break;
            }
        }

        return $result;
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

        $digits = $this->digitsOnly((string) $data['telephone']);
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
            $data['prenom'] = $this->ucTitle($data['prenom']);
        }
        if (! empty($data['ville'])) {
            $data['ville'] = $this->ucTitle($data['ville']);
        }
        if (! empty($data['adresse'])) {
            $data['adresse'] = $this->ucTitle($data['adresse']);
        }
        if (! empty($data['telephone'])) {
            $data['telephone'] = $this->buildInternationalPhone($data['telephone'], $data['code_phone_pays'] ?? null);
        }

        return $data;
    }

    protected function normalizeData(array $data): array
    {
        if (! empty($data['nom'])) {
            $data['nom'] = mb_strtoupper($data['nom'], 'UTF-8');
        }
        if (! empty($data['prenom'])) {
            $data['prenom'] = $this->ucTitle($data['prenom']);
        }
        if (! empty($data['raison_sociale'])) {
            $data['raison_sociale'] = $this->ucTitle($data['raison_sociale']);
        }
        if (! empty($data['ville'])) {
            $data['ville'] = $this->ucTitle($data['ville']);
        }
        if (! empty($data['code_phone_pays']) && ! empty($data['phone'])) {
            $tel = (string) $data['phone'];
            if (! str_starts_with($tel, '+')) {
                $data['phone'] = $data['code_phone_pays'].ltrim($tel, '0');
            }
        }

        return $data;
    }

    private function buildInternationalPhone(string $telephone, ?string $codePhonePays): ?string
    {
        $telephone = trim($telephone);
        $digits = $this->digitsOnly($telephone);

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($telephone, '+')) {
            return '+'.$digits;
        }

        $localDigits = preg_replace('/^0/', '', $digits);
        $dialDigits = $codePhonePays ? $this->digitsOnly($codePhonePays) : '';

        return $dialDigits !== '' ? '+'.$dialDigits.$localDigits : $digits;
    }
}
