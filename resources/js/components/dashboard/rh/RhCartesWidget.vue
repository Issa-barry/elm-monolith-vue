<script setup lang="ts">
import { formatPourcentage } from '@/lib/pourcentages';
import type {
    AbsencesRh,
    ContratsRh,
    EffectifsRh,
    FinCddRh,
} from '@/types/dashboard-rh';
import { computed } from 'vue';
import type { LigneIndicateurs } from '../shared/indicateurs';
import LignesIndicateursApollo from '../shared/LignesIndicateursApollo.vue';
import { nombreTexte, pluriel } from './nombres';

// Cartes de Stat-RH, cartes Apollo partagées avec Stat-Stock (LignesIndicateursApollo) : quatre par
// ligne sur grand écran, deux sur tablette, une sur téléphone. Une ligne par liste RH, affichée seulement avec
// sa permission (le serveur n'envoie pas le bloc sinon) :
// - Effectifs (à ce jour ; entrées et sorties de la période) ;
// - Contrats (à ce jour) ;
// - Absences (jours écoulés de la période).
const props = defineProps<{
    effectifs: EffectifsRh | null;
    sansDateEmbauche: number | null;
    contrats: ContratsRh | null;
    finsCdd: FinCddRh[] | null;
    absences: AbsencesRh | null;
}>();

function ligneEffectifs(e: EffectifsRh): LigneIndicateurs {
    const sansDate = props.sansDateEmbauche ?? 0;

    return {
        id: 'effectifs',
        titre: 'Effectifs',
        precision: 'à ce jour ; entrées et sorties sur la période',
        cartes: [
            {
                id: 'actifs',
                titre: 'Salariés actifs',
                icone: 'pi-users',
                definition:
                    'Salariés au statut Actif aujourd’hui, dans les agences du filtre.',
                valeur: nombreTexte(e.actifs),
                detail: `${nombreTexte(e.actifs + e.suspendus)} présents avec les suspendus`,
            },
            {
                id: 'suspendus',
                titre: 'Salariés suspendus',
                icone: 'pi-pause-circle',
                definition:
                    'Salariés au statut Suspendu aujourd’hui : toujours dans l’effectif.',
                valeur: nombreTexte(e.suspendus),
                detail: 'Statut Suspendu',
            },
            {
                id: 'entrees',
                titre: 'Entrées',
                icone: 'pi-sign-in',
                definition:
                    'Salariés dont la date d’embauche tombe dans la période. Un dossier sans date d’embauche n’est jamais compté.',
                valeur: nombreTexte(e.entrees),
                detail:
                    sansDate > 0
                        ? `${nombreTexte(sansDate)} sans date d’embauche`
                        : 'Date d’embauche dans la période',
            },
            {
                id: 'sorties',
                titre: 'Sorties',
                icone: 'pi-sign-out',
                definition:
                    'Départs enregistrés dont la date de sortie effective tombe dans la période.',
                valeur: nombreTexte(e.sorties),
                detail: 'Départs de la période',
            },
        ],
    };
}

function ligneContrats(c: ContratsRh, fins: FinCddRh[]): LigneIndicateurs {
    const depassees = fins.filter(
        (f) => f.statut === 'echeance_depassee',
    ).length;

    return {
        id: 'contrats',
        titre: 'Contrats',
        precision: 'à ce jour',
        cartes: [
            {
                id: 'cdi',
                titre: 'CDI en cours',
                icone: 'pi-briefcase',
                definition:
                    'Salariés actifs ou suspendus dont le contrat actif est un CDI.',
                valeur: nombreTexte(c.cdi),
                detail: 'Durée indéterminée',
            },
            {
                id: 'cdd',
                titre: 'CDD en cours',
                icone: 'pi-calendar',
                definition:
                    'Salariés actifs ou suspendus dont le contrat actif est un CDD.',
                valeur: nombreTexte(c.cdd),
                detail: 'Durée déterminée',
            },
            {
                id: 'sans-contrat',
                titre: 'Sans contrat en cours',
                icone: 'pi-file',
                definition:
                    'Salariés actifs sans contrat au statut Actif (aucun contrat, ou contrats terminés ou rompus).',
                valeur: nombreTexte(c.sans_contrat),
                detail: 'Salariés actifs',
            },
            {
                id: 'fins-cdd',
                titre: 'Fins de CDD à traiter',
                icone: 'pi-calendar-times',
                definition:
                    'CDD en cours dont la fin prévue est dépassée ou atteinte dans les 30 jours : renouvellement, nouveau contrat ou départ à décider.',
                valeur: nombreTexte(fins.length),
                ton: fins.length > 0 ? 'avertissement' : 'neutre',
                detail: `${depassees} ${depassees > 1 ? 'dépassées' : 'dépassée'} · ${fins.length - depassees} sous 30 jours`,
            },
        ],
    };
}

// Taux calculé sur les seuls salariés dont la période d'emploi est connue (date d'embauche, et date
// de sortie s'ils sont sortis). Le nombre de salariés retenus et exclus et les jours attendus ne
// sont envoyés qu'avec l'accès aux fiches salariés : sinon, seul le taux est affiché.
function definitionTaux(a: AbsencesRh): string {
    const base =
        'Jours d’absence ÷ jours de présence attendus, en jours calendaires écoulés de la période, sur les seuls salariés dont la date d’embauche (et la date de sortie s’ils sont sortis) est connue.';

    return a.jours_attendus === null || a.jours_attendus === 0
        ? base
        : `${base} ${nombreTexte(a.jours_attendus)} jours attendus.`;
}

function detailTaux(a: AbsencesRh): string {
    if (a.taux_motif === 'periode')
        return 'Période bornée et commencée requise';
    if (a.salaries_taux !== null && a.salaries_exclus !== null) {
        return `${nombreTexte(a.salaries_taux)} pris en compte · ${pluriel(a.salaries_exclus, 'exclu')}`;
    }

    return a.taux_motif === 'aucun_salarie'
        ? 'Aucune date d’embauche connue'
        : 'Salariés à date d’embauche connue';
}

function ligneAbsences(a: AbsencesRh): LigneIndicateurs {
    return {
        id: 'absences',
        titre: 'Absences',
        precision: a.jusqu_au
            ? `jours écoulés de la période, jusqu’au ${new Date(`${a.jusqu_au}T00:00:00`).toLocaleDateString('fr-FR')}`
            : 'période pas encore commencée',
        cartes: [
            {
                id: 'jours-absence',
                titre: 'Jours d’absence',
                icone: 'pi-calendar-minus',
                definition:
                    'Jours calendaires d’absence enregistrée (annulées exclues), dates incluses, sur les jours écoulés de la période.',
                valeur: nombreTexte(a.jours),
                detail: `${pluriel(a.absences, 'absence')} · ${pluriel(a.salaries, 'salarié')}`,
            },
            {
                id: 'taux',
                titre: 'Taux d’absentéisme',
                icone: 'pi-percentage',
                definition: definitionTaux(a),
                valeur: a.taux === null ? '—' : formatPourcentage(a.taux),
                detail: detailTaux(a),
            },
            {
                id: 'injustifiees',
                titre: 'Absences injustifiées',
                icone: 'pi-ban',
                definition:
                    'Jours d’absence au seul motif « Absence injustifiée ».',
                valeur: nombreTexte(a.injustifiees_jours),
                detail: `${pluriel(a.injustifiees_jours, 'jour')} · ${pluriel(a.injustifiees_absences, 'absence')}`,
            },
            {
                id: 'absents',
                titre: 'Absents aujourd’hui',
                icone: 'pi-user-minus',
                definition:
                    'Salariés dont une absence enregistrée couvre aujourd’hui.',
                valeur: nombreTexte(a.absents_aujourdhui),
                detail: `${pluriel(a.a_venir, 'absence')} à venir`,
            },
        ],
    };
}

const lignes = computed((): LigneIndicateurs[] => {
    const resultat: LigneIndicateurs[] = [];
    if (props.effectifs) resultat.push(ligneEffectifs(props.effectifs));
    if (props.contrats)
        resultat.push(ligneContrats(props.contrats, props.finsCdd ?? []));
    if (props.absences) resultat.push(ligneAbsences(props.absences));

    return resultat;
});
</script>

<template>
    <LignesIndicateursApollo :lignes="lignes" prefixe="rh" />
</template>
