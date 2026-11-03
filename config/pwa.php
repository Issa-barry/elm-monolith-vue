<?php

// Noms de l'application installée (manifest /manifest.json et titre iPhone) — cf. docs/pwa.md.
// Volontairement séparés d'APP_NAME, qui sert aussi au titre des pages et aux e-mails.
$name = env('PWA_NAME') ?: 'ELM';

return [

    'name' => $name,

    // Nom sous l'icône : si PWA_SHORT_NAME est absente ou vide, le nom complet est repris.
    'short_name' => env('PWA_SHORT_NAME') ?: $name,

];
