<?php

namespace App\Enums;

enum GraviteAnomalieEncaissement: string
{
    case CRITIQUE = 'critique';
    case ATTENTION = 'attention';
    case INFORMATION = 'information';

    public function label(): string
    {
        return match ($this) {
            self::CRITIQUE => 'Critique',
            self::ATTENTION => 'Attention',
            self::INFORMATION => 'Information',
        };
    }

    /** Seules les anomalies critiques et « attention » partent par e-mail et sont relancées. */
    public function parEmail(): bool
    {
        return $this !== self::INFORMATION;
    }

    public function rang(): int
    {
        return match ($this) {
            self::CRITIQUE => 0,
            self::ATTENTION => 1,
            self::INFORMATION => 2,
        };
    }
}
