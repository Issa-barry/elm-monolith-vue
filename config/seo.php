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

    // Pages publiques indexables. Ajouter une page ici suffit pour qu'elle
    // apparaisse dans le sitemap — pas besoin de toucher SitemapController.
    'sitemap' => [
        ['path' => '/', 'priority' => '1.0', 'changefreq' => 'weekly'],
        ['path' => '/contact', 'priority' => '0.6', 'changefreq' => 'monthly'],
        ['path' => '/register/livreur', 'priority' => '0.7', 'changefreq' => 'monthly'],
        ['path' => '/privacy-policy', 'priority' => '0.2', 'changefreq' => 'yearly'],
        // /help volontairement absent : noindex tant que la page n'a pas de vrai contenu (cf. Help.vue).
    ],
];
