<?php

namespace App\Services\Search;

final class SearchResultItem
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly ?string $subtitle = null,
    ) {}

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
        ];
    }
}
