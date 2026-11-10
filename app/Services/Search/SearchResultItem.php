<?php

namespace App\Services\Search;

final class SearchResultItem
{
    /**
     * @param  ?string  $href  lien direct du résultat, quand le groupe ne le déduit pas de l'id
     * @param  list<array{libelle: string, href: ?string}>  $badges  fonctions du contact et lien vers
     *                                                               leur écran métier (ADR 0060, lot 8)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly ?string $subtitle = null,
        public readonly ?string $href = null,
        public readonly array $badges = [],
    ) {}

    public function toArray(): array
    {
        // `href` et `badges` seulement quand ils sont renseignés : la réponse des groupes historiques
        // (contrat de l'API v1/search/global) reste identique.
        return [
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            ...($this->href !== null ? ['href' => $this->href] : []),
            ...($this->badges !== [] ? ['badges' => $this->badges] : []),
        ];
    }
}
