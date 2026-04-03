<?php

namespace App\Support;

class AppVersion
{
    public static function label(): string
    {
        $label = sprintf('V: %s', self::baseVersion());
        $releasedAt = self::releasedAt();

        if ($releasedAt === null) {
            return $label;
        }

        return sprintf('%s (release du %s)', $label, $releasedAt);
    }

    public static function current(): string
    {
        static $resolved = null;

        if ($resolved !== null) {
            return $resolved;
        }

        $baseVersion = self::baseVersion();
        $gitVersion = self::gitVersion();

        if ($gitVersion === null) {
            return $resolved = $baseVersion;
        }

        if (preg_match('/^\d+\.\d+\.\d+$/', $baseVersion) === 1) {
            return $resolved = "{$baseVersion}+{$gitVersion}";
        }

        return $resolved = $gitVersion;
    }

    private static function baseVersion(): string
    {
        $fromEnv = trim((string) env('APP_VERSION', ''));
        if ($fromEnv !== '') {
            return $fromEnv;
        }

        $versionFile = base_path('VERSION');
        if (is_file($versionFile)) {
            $fromFile = trim((string) file_get_contents($versionFile));
            if ($fromFile !== '') {
                return $fromFile;
            }
        }

        return '0.1.0';
    }

    private static function gitVersion(): ?string
    {
        if (! is_dir(base_path('.git')) || ! function_exists('exec')) {
            return null;
        }

        $repoPath = escapeshellarg(base_path());
        $commands = [
            "git -C {$repoPath} describe --tags --always --dirty --abbrev=7 2>NUL",
            "git -C {$repoPath} describe --tags --always --dirty --abbrev=7 2>/dev/null",
        ];

        foreach ($commands as $command) {
            $output = [];
            $exitCode = 1;

            @exec($command, $output, $exitCode);

            if ($exitCode === 0) {
                $value = trim((string) ($output[0] ?? ''));
                if ($value !== '') {
                    return ltrim($value, 'v');
                }
            }
        }

        return null;
    }

    private static function releasedAt(): ?string
    {
        $raw = trim((string) env('APP_RELEASED_AT', ''));
        if ($raw === '') {
            $releaseAtFile = base_path('RELEASED_AT');
            if (is_file($releaseAtFile)) {
                $raw = trim((string) file_get_contents($releaseAtFile));
            }
        }

        if ($raw === '') {
            return null;
        }

        $timezone = (string) config('app.timezone', 'UTC');

        try {
            return \Illuminate\Support\Carbon::parse($raw)
                ->setTimezone($timezone)
                ->format('d/m/Y H:i');
        } catch (\Throwable) {
            return null;
        }
    }
}
