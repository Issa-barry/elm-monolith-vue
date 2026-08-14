<?php

use App\Support\Theming\ThemeCatalog;

return [

    /*
    |--------------------------------------------------------------------------
    | Politique de thème — définie au déploiement, jamais depuis l'IHM
    |--------------------------------------------------------------------------
    |
    | Chaque environnement (prod/preprod/local/e2e...) a son propre .env, donc
    | sa propre politique, sans qu'aucune notion d'"environment" ne soit
    | nécessaire en base de données — cf. docs/theming.md, section "Direction
    | retenue". Un admin choisit une VALEUR ACTIVE parmi ces listes (persistée
    | via Parametre, cf. ThemeController), jamais la liste elle-même.
    |
    | THEME_ALLOWED_* : CSV de valeurs autorisées pour cet axe. Absent/vide/
    | invalide => toutes les valeurs du catalogue sont autorisées (comportement
    | actuel, non restrictif — cf. ThemeCatalog::parseList()). Un axe avec une
    | seule valeur autorisée est de facto verrouillé (aucun flag "locked"
    | séparé à maintenir en plus de la liste).
    |
    | THEME_DEFAULT_* : valeur par défaut du déploiement, utilisée tant qu'aucun
    | admin n'a rien choisi, ou si l'ancienne valeur choisie n'est plus
    | autorisée après un changement de politique.
    |
    */

    'allowed_presets' => ThemeCatalog::parseList(env('THEME_ALLOWED_PRESETS'), ThemeCatalog::PRESETS),
    'allowed_primaries' => ThemeCatalog::parseList(env('THEME_ALLOWED_PRIMARIES'), ThemeCatalog::PRIMARIES),
    'allowed_surfaces' => ThemeCatalog::parseList(env('THEME_ALLOWED_SURFACES'), ThemeCatalog::SURFACES),

    'default_preset' => env('THEME_DEFAULT_PRESET', ThemeCatalog::FALLBACK_PRESET),
    'default_primary' => env('THEME_DEFAULT_PRIMARY', ThemeCatalog::FALLBACK_PRIMARY),
    'default_surface' => env('THEME_DEFAULT_SURFACE', ThemeCatalog::FALLBACK_SURFACE),

];
