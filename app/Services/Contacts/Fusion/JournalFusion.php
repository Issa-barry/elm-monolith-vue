<?php

namespace App\Services\Contacts\Fusion;

use App\Models\ContactsFusion;
use App\Models\ContactsFusionOperation;

/** Journal d'une fusion (lot 9b) : chaque valeur écrite, dans l'ordre d'exécution. */
final class JournalFusion
{
    private int $ordre = 0;

    public function __construct(private readonly ContactsFusion $fusion) {}

    public function ecrire(string $nature, string $table, string $cibleId, string $champ, mixed $avant, mixed $apres, string $libelle): void
    {
        ContactsFusionOperation::create([
            'contacts_fusion_id' => $this->fusion->id,
            'ordre' => ++$this->ordre,
            'nature' => $nature,
            'table_cible' => $table,
            'cible_id' => $cibleId,
            'champ' => $champ,
            'valeur_avant' => self::texte($avant),
            'valeur_apres' => self::texte($apres),
            'libelle' => mb_substr($libelle, 0, 255),
        ]);
    }

    private static function texte(mixed $valeur): ?string
    {
        return match (true) {
            $valeur === null => null,
            is_bool($valeur) => $valeur ? '1' : '0',
            default => (string) $valeur,
        };
    }
}
