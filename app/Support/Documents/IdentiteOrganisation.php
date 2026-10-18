<?php

namespace App\Support\Documents;

use App\Models\Organization;
use App\Models\Site;
use Illuminate\Support\Facades\Storage;

/**
 * Identité de l'organisation sur les factures et bons : nom et logo embarqué (data URI, DomPDF ne
 * charge aucune ressource distante). Sans logo personnalisé lisible, le logo par défaut reste
 * affiché pour que l'en-tête garde sa mise en page.
 */
final class IdentiteOrganisation
{
    public function __construct(
        public readonly string $nom,
        public readonly string $logo,
    ) {}

    public static function depuis(Organization $organisation): self
    {
        return new self((string) $organisation->name, self::logo($organisation));
    }

    /** @return list<string> */
    public static function lignesAgence(?Site $site, string $libelle = 'Agence'): array
    {
        if (! $site) {
            return [];
        }

        $adresse = $site->localisation ?: collect([$site->ville, $site->quartier])->filter()->implode(', ');

        return array_values(array_filter([$libelle.' : '.$site->nom, $adresse ?: null]));
    }

    private static function logo(Organization $organisation): string
    {
        if ($organisation->logo_path && Storage::disk('public')->exists($organisation->logo_path)) {
            $chemin = Storage::disk('public')->path($organisation->logo_path);

            return 'data:'.mime_content_type($chemin).';base64,'.base64_encode((string) file_get_contents($chemin));
        }

        $svg = str_replace(['#fafafa', '#1a1a1a'], ['#2563eb', '#ffffff'], (string) file_get_contents(public_path('favicon-dark.svg')));

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
