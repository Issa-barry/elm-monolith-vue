<?php

namespace App\Support\Rh\Contrats;

use App\Enums\TypeContrat;

/**
 * Trame de départ des modèles de contrat (ADR 0044) : structure fonctionnelle en douze articles et
 * leurs variables, PAS un texte juridique validé. Le modèle créé à partir de cette trame reste en
 * brouillon : aucun contrat ne peut être généré tant qu'un administrateur ne l'a pas relu, adapté
 * au droit applicable et activé.
 */
final class TrameModeleContrat
{
    public static function titre(TypeContrat $type): string
    {
        return match ($type) {
            TypeContrat::CDI => 'Contrat de travail à durée indéterminée',
            TypeContrat::CDD => 'Contrat de travail à durée déterminée',
        };
    }

    /** @return list<array{titre: string, texte: string}> */
    public static function articles(TypeContrat $type): array
    {
        $cdd = $type === TypeContrat::CDD;

        return [
            ['titre' => 'Objet du contrat', 'texte' => '{{employeur.nom}}, représentée par {{employeur.representant_nom}}, {{employeur.representant_qualite}}, engage {{salarie.civilite}} {{salarie.nom_complet}}, matricule {{salarie.matricule}}, aux conditions définies par le présent contrat.'],
            ['titre' => 'Nature du contrat', 'texte' => 'Le présent contrat est un {{contrat.type}}, conclu conformément aux dispositions du Code du travail en vigueur.'],
            ['titre' => 'Durée et date de prise d’effet', 'texte' => $cdd
                ? "Le contrat prend effet le {{contrat.date_debut}} et prend fin le {{contrat.date_fin}}.\n\nIl est conclu pour le motif suivant : {{contrat.motif_cdd}}."
                : 'Le contrat prend effet le {{contrat.date_debut}}. Il est conclu pour une durée indéterminée.'],
            ['titre' => 'Fonction et missions', 'texte' => 'Le salarié est engagé en qualité de {{poste.fonction}}. Il exécute les missions qui lui sont confiées par sa hiérarchie dans le cadre de cette fonction.'],
            ['titre' => 'Lieu de travail', 'texte' => 'Le salarié exerce ses fonctions à {{poste.lieu_travail}}. Ce lieu peut être modifié selon les nécessités du service, dans les conditions prévues par la loi.'],
            ['titre' => 'Période d’essai', 'texte' => 'Période d’essai : {{contrat.periode_essai}}. Pendant cette période, chacune des parties peut mettre fin au contrat dans les conditions prévues par la loi.'],
            ['titre' => 'Rémunération', 'texte' => "En contrepartie de son travail, le salarié perçoit un salaire de base mensuel de {{contrat.salaire_base}}.\n\nPrimes et avantages : {{contrat.avantages}}."],
            ['titre' => 'Durée du travail, congés et absences', 'texte' => "Durée et horaires de travail : {{contrat.horaires}}.\n\nLe salarié bénéficie des congés payés et des autorisations d’absence prévus par la législation en vigueur. Toute absence doit être justifiée."],
            ['titre' => 'Obligations professionnelles', 'texte' => 'Le salarié s’engage à exécuter son travail avec diligence, à respecter le règlement intérieur, les consignes de sa hiérarchie et les règles d’hygiène et de sécurité, et à garder la plus stricte discrétion sur les informations dont il a connaissance dans l’exercice de ses fonctions.'],
            ['titre' => 'Rupture du contrat', 'texte' => $cdd
                ? 'Le contrat prend fin de plein droit à son terme. Il ne peut être rompu avant son terme que dans les cas prévus par la loi.'
                : 'Le contrat peut être rompu par l’une ou l’autre des parties dans les conditions prévues par la loi, notamment en matière de préavis.'],
            ['titre' => 'Dispositions finales', 'texte' => 'Pour tout ce qui n’est pas prévu au présent contrat, les parties se réfèrent au Code du travail, aux textes pris pour son application et, le cas échéant, à la convention collective applicable.'],
            ['titre' => 'Signatures', 'texte' => 'Fait à {{document.lieu_signature}}, en deux exemplaires originaux, dont un remis à chacune des parties.'],
        ];
    }
}
