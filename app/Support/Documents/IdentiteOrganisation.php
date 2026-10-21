<?php

namespace App\Support\Documents;

use App\Models\Organization;
use App\Models\Site;
use Illuminate\Support\Facades\Storage;

/**
 * Identité de l'organisation sur les factures et bons : nom, logo embarqué (data URI, DomPDF ne
 * charge aucune ressource distante) et pied de page officiel. Sans logo personnalisé lisible, le
 * logo par défaut reste affiché pour que l'en-tête garde sa mise en page.
 */
final class IdentiteOrganisation
{
    /** Bornes du pied de page officiel : la marge basse du modèle est calculée pour elles. */
    public const PIED_MAX_LIGNES = 6;

    public const PIED_MAX_CARACTERES_LIGNE = 150;

    /**
     * Caractères tenant sur une ligne rendue du pied (10,5 px sur la largeur de la page), estimation
     * prudente : une ligne saisie plus longue passe à la ligne dans le PDF.
     */
    private const PIED_CARACTERES_PAR_LIGNE_RENDUE = 95;

    /** @param  list<string>  $piedPage  lignes du pied de page officiel (vide : pas de bloc) */
    public function __construct(
        public readonly string $nom,
        public readonly string $logo,
        public readonly array $piedPage = [],
    ) {}

    public static function depuis(Organization $organisation): self
    {
        return new self((string) $organisation->name, self::logo($organisation), self::lignesPied($organisation->pied_page_documents));
    }

    /**
     * Pied de page officiel saisi dans Paramètres → Organisation, une ligne par ligne saisie ;
     * lignes vides ignorées.
     *
     * @return list<string>
     */
    public static function lignesPied(?string $texte): array
    {
        return array_values(array_filter(
            array_map('trim', preg_split('/\R/u', (string) $texte)),
            fn (string $ligne) => $ligne !== '',
        ));
    }

    /**
     * Nom en majuscules découpé pour ses deux tons (CharteDocument) : le dernier mot (« MAMAN »)
     * en bleu clair, le début (« EAU LA ») en bleu foncé. Un nom d'un seul mot reste en bleu foncé.
     *
     * @return array{0: string, 1: string|null}
     */
    public function nomEnDeuxTons(): array
    {
        $mots = preg_split('/\s+/u', mb_strtoupper(trim($this->nom)), -1, PREG_SPLIT_NO_EMPTY);
        $dernier = count($mots) > 1 ? array_pop($mots) : null;

        return [implode(' ', $mots), $dernier];
    }

    /** Lignes du pied telles que rendues dans le PDF (retours à la ligne compris) : base de la marge basse. */
    public function lignesPiedRendues(): int
    {
        return array_sum(array_map(
            fn (string $ligne) => (int) ceil(mb_strlen($ligne) / self::PIED_CARACTERES_PAR_LIGNE_RENDUE),
            $this->piedPage,
        ));
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
