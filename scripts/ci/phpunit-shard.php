<?php

/**
 * Prépare la configuration PHPUnit d'un shard de la CI (job `PHPUnit (shard n/N)` de tests.yml).
 *
 * Usage : php scripts/ci/phpunit-shard.php <shard> <total> [sortie]
 *
 * Reprend phpunit.xml à l'identique (variables d'environnement, bootstrap, source) et remplace
 * ses suites par la liste des fichiers de test attribués à ce shard. La répartition est
 * déterministe : fichiers triés par taille décroissante, chacun donné au shard le moins chargé
 * (la taille du fichier sert d'estimation de sa durée). Chaque fichier de tests/Unit et
 * tests/Feature appartient à exactement un shard.
 */
$shard = (int) ($argv[1] ?? 0);
$total = (int) ($argv[2] ?? 0);
$sortie = $argv[3] ?? 'phpunit.shard.xml';

if ($total < 1 || $shard < 1 || $shard > $total) {
    fwrite(STDERR, "Usage : php scripts/ci/phpunit-shard.php <shard 1..total> <total> [sortie]\n");
    exit(1);
}

$racine = dirname(__DIR__, 2);
$fichiers = [];

foreach (['tests/Unit', 'tests/Feature'] as $dossier) {
    $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("{$racine}/{$dossier}", FilesystemIterator::SKIP_DOTS));
    foreach ($iterateur as $fichier) {
        if ($fichier->isFile() && str_ends_with($fichier->getFilename(), 'Test.php')) {
            $relatif = str_replace('\\', '/', substr($fichier->getPathname(), strlen($racine) + 1));
            $fichiers[$relatif] = $fichier->getSize();
        }
    }
}

// Tri stable : taille décroissante, puis chemin (même répartition sur toutes les machines).
uksort($fichiers, fn (string $a, string $b) => [$fichiers[$b], $a] <=> [$fichiers[$a], $b]);

$charges = array_fill(1, $total, 0);
$attribues = array_fill(1, $total, []);
foreach ($fichiers as $chemin => $taille) {
    $cible = array_keys($charges, min($charges))[0];
    $charges[$cible] += $taille;
    $attribues[$cible][] = $chemin;
}

$config = new DOMDocument;
$config->preserveWhiteSpace = false;
$config->formatOutput = true;
$config->load("{$racine}/phpunit.xml");

$suites = $config->getElementsByTagName('testsuites')->item(0);
while ($suites->firstChild) {
    $suites->removeChild($suites->firstChild);
}
$suite = $config->createElement('testsuite');
$suite->setAttribute('name', "Shard {$shard}/{$total}");
sort($attribues[$shard]);
foreach ($attribues[$shard] as $chemin) {
    $suite->appendChild($config->createElement('file', $chemin));
}
$suites->appendChild($suite);

$config->save("{$racine}/{$sortie}");

printf(
    "Shard %d/%d : %d fichiers sur %d (%.0f %% du volume) → %s\n",
    $shard,
    $total,
    count($attribues[$shard]),
    count($fichiers),
    100 * $charges[$shard] / max(1, array_sum($charges)),
    $sortie,
);
