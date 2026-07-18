<?php

return [

    'site_name' => 'Eau la Maman',

    'locale' => 'fr_FR',

    'twitter_site' => null,

    // Image OG/Twitter par défaut (1200x630 recommandé) tant qu'aucun visuel dédié n'existe.
    'default_image' => '/images/landing/b2.png',

    'organization' => [
        'name' => 'Eau la Maman',
        'legal_name' => 'GBALAN',
        'logo' => '/images/logo-email.svg',
        'phone' => '+224620000000',
        'email' => 'contact@eaulamaman.com',
        'address_locality' => 'Conakry',
        'address_country' => 'GN',
        'area_served' => ['Matoto', 'Dabompa', 'Kouria', 'Lambagny', 'Lansanaya'],
    ],

    // Pages publiques indexables. La vitrine (eau-la-maman.com, dépôt elm-vitrine)
    // porte désormais tout le contenu marketing (/, /contact, /help, /privacy-policy,
    // /delete-account) — seule /register/livreur reste ici tant qu'elle n'a pas
    // été migrée côté vitrine.
    'sitemap' => [
        ['path' => '/register/livreur', 'priority' => '0.7', 'changefreq' => 'monthly'],
    ],
];
