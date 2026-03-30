<?php

namespace App\Support;

class AppVersion
{
    public static function label(): string
    {
        return sprintf('V: %s le %s', self::baseVersion(), self::displayedAt());
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

    private static function displayedAt(): string
    {
        $timezone = (string) config('app.timezone', 'UTC');

        return now($timezone)->format('d/m/Y \à H:i');
    }
}
