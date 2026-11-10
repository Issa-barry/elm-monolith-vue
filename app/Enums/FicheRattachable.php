<?php

namespace App\Enums;

use App\Models\EntrepriseInterlocuteur;
use App\Models\Livreur;
use App\Models\Parrain;
use App\Models\Prestataire;
use App\Models\Proprietaire;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** Fiches métier qu'un compte peut porter par `user_id` (ADR 0056). */
enum FicheRattachable: string
{
    case LIVREUR = 'livreur';
    case PROPRIETAIRE = 'proprietaire';
    case PARRAIN = 'parrain';
    case CONSULTANT = 'consultant';
    case INTERLOCUTEUR = 'interlocuteur';

    public function label(): string
    {
        return match ($this) {
            self::LIVREUR => 'Livreurs',
            self::PROPRIETAIRE => 'Propriétaires',
            self::PARRAIN => 'Parrains',
            self::CONSULTANT => 'Consultants',
            self::INTERLOCUTEUR => 'Interlocuteurs',
        };
    }

    public function libelleFiche(): string
    {
        return match ($this) {
            self::LIVREUR => 'livreur',
            self::PROPRIETAIRE => 'propriétaire',
            self::PARRAIN => 'parrain',
            self::CONSULTANT => 'consultant',
            self::INTERLOCUTEUR => "interlocuteur d'entreprise",
        };
    }

    /** @return class-string<Model> */
    public function modele(): string
    {
        return match ($this) {
            self::LIVREUR => Livreur::class,
            self::PROPRIETAIRE => Proprietaire::class,
            self::PARRAIN => Parrain::class,
            self::CONSULTANT => Prestataire::class,
            self::INTERLOCUTEUR => EntrepriseInterlocuteur::class,
        };
    }

    /**
     * Fiches de ce type qui peuvent être reliées à un compte. Seul un consultant personne physique
     * se rattache : une entreprise passe par ses interlocuteurs, les autres prestataires jamais.
     */
    public function requete(): Builder
    {
        $query = $this->modele()::query();

        if ($this === self::CONSULTANT) {
            // Lot 6b (ADR 0060) : critère unique de la qualification Consultant.
            $query->qualifiesConsultant()->whereNull('entreprise_tierce_id');
        }

        return $query;
    }

    public static function pour(Model $fiche): self
    {
        foreach (self::cases() as $type) {
            if ($fiche instanceof ($type->modele())) {
                return $type;
            }
        }

        throw new InvalidArgumentException('Fiche non rattachable : '.$fiche::class);
    }
}
