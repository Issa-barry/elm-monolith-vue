<?php

namespace App\Support\Documents;

use App\Enums\StatutContrat;
use App\Enums\TypeContrat;
use App\Models\Contrat;
use App\Models\Employe;
use App\Models\Organization;
use App\Models\Personne;

/**
 * Récapitulatif PDF d'un contrat de travail (docs/rh-contrat-pdf.md), dans le modèle officiel
 * partagé. Ce n'est PAS un contrat prêt à signer : l'application ne définit aucune clause
 * contractuelle (période d'essai, durée et lieu du travail, motif du CDD, représentant de
 * l'employeur…). Le document le dit dans son encadré et en pied de chaque page.
 *
 * Données reprises du dossier RH, sans aucune donnée de paie hors salaire de base : le salaire
 * n'est affiché qu'avec `rh-remunerations.read` ; ni primes, ni coordonnées de paiement, ni CNSS.
 * Une information nécessaire absente rend le document « INCOMPLET » (filigrane et encadré).
 */
final class ContratDocument
{
    public const MENTION = 'Récapitulatif du contrat de travail établi à partir du dossier RH — ne remplace pas le contrat de travail signé.';

    /** Clauses qu'un contrat de travail complet doit contenir et que l'application ne gère pas. */
    public const CLAUSES_NON_GEREES = [
        'période d’essai',
        'durée et horaires de travail',
        'lieu de travail',
        'motif du recours au CDD',
        'représentant légal de l’employeur',
        'convention collective',
    ];

    public static function depuis(Contrat $contrat, Organization $organisation, bool $voitSalaire): DocumentPdf
    {
        $employe = self::employe($contrat);
        $personne = $employe?->personne;
        $manquantes = self::informationsManquantes($contrat, $employe);

        return new DocumentPdf(
            type: 'contrat_travail',
            vue: 'pdf.contrat_travail',
            titre: 'RÉCAPITULATIF DU CONTRAT',
            reference: self::reference($contrat, $employe),
            organisation: IdentiteOrganisation::depuis($organisation),
            lignesAgence: IdentiteOrganisation::lignesAgence($employe?->site),
            metadonnees: [
                ['cle' => 'TYPE', 'valeur' => $contrat->type_contrat->label()],
                ['cle' => 'MATRICULE', 'valeur' => $employe?->matricule ?: '—'],
                ['cle' => 'STATUT', 'valeur' => $contrat->statut_contrat->label()],
            ],
            parties: [
                ['titre' => 'EMPLOYEUR', 'lignes' => [
                    (string) $organisation->name,
                    'Représentant légal : non renseigné dans l’application',
                ]],
                ['titre' => 'SALARIÉ', 'lignes' => self::lignesSalarie($employe, $personne)],
            ],
            colonnes: [],
            lignes: [],
            totaux: [],
            encadre: self::encadre($manquantes),
            filigrane: self::filigrane($contrat, $manquantes),
            mention: self::MENTION,
            infoPied: 'Généré le '.now()->format('d/m/Y à H:i'),
            sections: [
                ['titre' => 'Poste', 'lignes' => [
                    ['cle' => 'Fonction', 'valeur' => $employe?->fonction?->libelle ?? 'Non renseignée'],
                    ['cle' => 'Service', 'valeur' => $employe?->service?->libelle ?? 'Non renseigné'],
                    ['cle' => 'Agence', 'valeur' => $employe?->site?->nom ?? 'Non renseignée'],
                    ['cle' => 'Date d’embauche', 'valeur' => $employe?->date_embauche?->format('d/m/Y') ?? 'Non renseignée'],
                ]],
                ['titre' => 'Contrat et rémunération', 'lignes' => [
                    ['cle' => 'Type de contrat', 'valeur' => self::libelleType($contrat->type_contrat)],
                    ['cle' => 'Date de début', 'valeur' => $contrat->date_debut?->format('d/m/Y') ?? 'Non renseignée'],
                    ['cle' => 'Date de fin', 'valeur' => self::dateFin($contrat)],
                    ['cle' => 'Salaire de base', 'valeur' => self::salaire($contrat, $voitSalaire)],
                ]],
            ],
            signatures: [
                ['titre' => 'L’employeur', 'lignes' => ['Nom, qualité, date et cachet']],
                ['titre' => 'Le salarié', 'lignes' => ['Date, précédée de « Lu et approuvé »']],
            ],
        );
    }

    /**
     * Informations nécessaires au récapitulatif et absentes du dossier : leur absence est signalée
     * dans le document, jamais comblée par une valeur inventée.
     *
     * @return list<string>
     */
    public static function informationsManquantes(Contrat $contrat, ?Employe $employe): array
    {
        $manquantes = [];
        if ($employe === null) {
            return ['salarié introuvable'];
        }
        if (blank($employe->personne?->nom) && blank($employe->personne?->prenom)) {
            $manquantes[] = 'nom du salarié';
        }
        if ($contrat->date_debut === null) {
            $manquantes[] = 'date de début';
        }
        if ($contrat->type_contrat->requiresDateFin() && $contrat->date_fin === null) {
            $manquantes[] = 'date de fin du CDD';
        }
        if ($contrat->salaire_base === null) {
            $manquantes[] = 'salaire de base';
        }
        if ($employe->fonction_rh_id === null) {
            $manquantes[] = 'fonction';
        }

        return $manquantes;
    }

    /**
     * Employé du contrat, même supprimé depuis (relation posée par chargerEmploye()) : le contrat
     * reste consultable.
     */
    private static function employe(Contrat $contrat): ?Employe
    {
        $employe = $contrat->relationLoaded('employe') ? $contrat->employe : self::chargerEmploye($contrat)->employe;
        $employe?->loadMissing(['personne', 'fonction', 'service', 'site']);

        return $employe;
    }

    /** Pose la relation `employe`, salarié supprimé (soft delete) compris, dans la même organisation. */
    public static function chargerEmploye(Contrat $contrat): Contrat
    {
        return $contrat->setRelation('employe', Employe::withTrashed()
            ->where('organization_id', $contrat->organization_id)
            ->find($contrat->employe_id));
    }

    /** @return list<string> */
    private static function lignesSalarie(?Employe $employe, ?Personne $personne): array
    {
        if ($employe === null) {
            return ['Salarié introuvable'];
        }

        $nom = trim(implode(' ', array_filter([
            $personne?->civilite?->label(),
            $personne?->prenom,
            $personne?->nom,
        ])));

        $naissance = null;
        if ($personne?->date_naissance) {
            $naissance = 'Né(e) le '.$personne->date_naissance->format('d/m/Y')
                .($personne->lieu_naissance ? ' à '.$personne->lieu_naissance : '');
        }

        $adresse = collect([$personne?->adresse, $personne?->ville])->filter()->implode(', ');

        return array_values(array_filter([
            $nom !== '' ? $nom : 'Nom non renseigné',
            'Matricule : '.($employe->matricule ?: '—'),
            $naissance,
            $personne?->nationalite ? 'Nationalité : '.$personne->nationalite : null,
            $adresse !== '' ? 'Adresse : '.$adresse : null,
        ]));
    }

    private static function libelleType(TypeContrat $type): string
    {
        return match ($type) {
            TypeContrat::CDI => 'CDI — contrat à durée indéterminée',
            TypeContrat::CDD => 'CDD — contrat à durée déterminée',
        };
    }

    private static function dateFin(Contrat $contrat): string
    {
        if ($contrat->date_fin) {
            return $contrat->date_fin->format('d/m/Y');
        }

        return $contrat->type_contrat->requiresDateFin() ? 'Non renseignée' : 'Sans date de fin';
    }

    private static function salaire(Contrat $contrat, bool $voitSalaire): string
    {
        if (! $voitSalaire) {
            return 'Réservé aux profils autorisés';
        }

        return $contrat->salaire_base === null ? 'Non renseigné' : DocumentPdf::montant($contrat->salaire_base).' / mois';
    }

    /**
     * @param  list<string>  $manquantes
     * @return array{ton: 'ok'|'attention'|'danger', titre: string, lignes: list<string>}
     */
    private static function encadre(array $manquantes): array
    {
        $clauses = 'À prévoir dans le contrat signé (non gérés par l’application) : '.implode(', ', self::CLAUSES_NON_GEREES).'.';

        if ($manquantes !== []) {
            return [
                'ton' => 'danger',
                'titre' => 'Document incomplet',
                'lignes' => [
                    'Informations absentes du dossier RH : '.implode(', ', $manquantes).'. Complétez le dossier avant toute utilisation.',
                    $clauses,
                ],
            ];
        }

        return [
            'ton' => 'attention',
            'titre' => 'Récapitulatif — pas un contrat de travail complet',
            'lignes' => [
                'Établi à partir du dossier RH, ce document ne remplace pas le contrat de travail signé.',
                $clauses,
            ],
        ];
    }

    /** @param  list<string>  $manquantes */
    private static function filigrane(Contrat $contrat, array $manquantes): ?string
    {
        if ($manquantes !== []) {
            return 'INCOMPLET';
        }

        return match ($contrat->statut_contrat) {
            StatutContrat::TERMINE => 'TERMINÉ',
            StatutContrat::ROMPU => 'ROMPU',
            default => null,
        };
    }

    /** « CTR-548817-20261030 » : matricule et date de début, seuls caractères sûrs pour un nom de fichier. */
    private static function reference(Contrat $contrat, ?Employe $employe): string
    {
        $matricule = preg_replace('/[^A-Za-z0-9]/', '', (string) $employe?->matricule) ?: strtoupper(substr((string) $contrat->id, -8));

        return 'CTR-'.$matricule.($contrat->date_debut ? '-'.$contrat->date_debut->format('Ymd') : '');
    }
}
