<script setup lang="ts">
import { formatPourcentage } from '@/lib/pourcentages';
import type {
    AbsencesRh,
    ContratsRh,
    EffectifsRh,
    FinCddRh,
} from '@/types/dashboard-rh';
import { computed } from 'vue';
import { CARTE_APOLLO } from '../commissions/apollo';
import { nombreTexte, pluriel } from './nombres';

// Cartes de Stat-RH, mêmes cartes Apollo que les autres tableaux de bord : quatre par ligne sur
// grand écran, deux sur tablette, une sur téléphone. Une ligne par liste RH, affichée seulement avec
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

interface Carte {
    id: string;
    titre: string;
    definition: string;
    valeur: string;
    detail: string;
}

interface Ligne {
    id: string;
    titre: string;
    precision: string;
    cartes: Carte[];
}

function ligneEffectifs(e: EffectifsRh): Ligne {
    const sansDate = props.sansDateEmbauche ?? 0;

    return {
        id: 'effectifs',
        titre: 'Effectifs',
        precision: 'à ce jour ; entrées et sorties sur la période',
        cartes: [
            {
                id: 'actifs',
                titre: 'Salariés actifs',
                definition:
                    'Salariés au statut Actif aujourd’hui, dans les agences du filtre.',
                valeur: nombreTexte(e.actifs),
                detail: `${nombreTexte(e.actifs + e.suspendus)} présents avec les suspendus`,
            },
            {
                id: 'suspendus',
                titre: 'Salariés suspendus',
                definition:
                    'Salariés au statut Suspendu aujourd’hui : toujours dans l’effectif.',
                valeur: nombreTexte(e.suspendus),
                detail: 'Statut Suspendu',
            },
            {
                id: 'entrees',
                titre: 'Entrées',
                definition:
                    'Salariés dont la date d’embauche tombe dans la période. Un dossier sans date d’embauche n’est jamais compté.',
                valeur: nombreTexte(e.entrees),
                detail:
                    sansDate > 0
                        ? `${pluriel(sansDate, 'dossier')} sans date d’embauche`
                        : 'Date d’embauche dans la période',
            },
            {
                id: 'sorties',
                titre: 'Sorties',
                definition:
                    'Départs enregistrés dont la date de sortie effective tombe dans la période.',
                valeur: nombreTexte(e.sorties),
                detail: 'Date de sortie dans la période',
            },
        ],
    };
}

function ligneContrats(c: ContratsRh, fins: FinCddRh[]): Ligne {
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
                definition:
                    'Salariés actifs ou suspendus dont le contrat actif est un CDI.',
                valeur: nombreTexte(c.cdi),
                detail: 'Contrat actif à durée indéterminée',
            },
            {
                id: 'cdd',
                titre: 'CDD en cours',
                definition:
                    'Salariés actifs ou suspendus dont le contrat actif est un CDD.',
                valeur: nombreTexte(c.cdd),
                detail: 'Contrat actif à durée déterminée',
            },
            {
                id: 'sans-contrat',
                titre: 'Sans contrat en cours',
                definition:
                    'Salariés actifs sans contrat au statut Actif (aucun contrat, ou contrats terminés ou rompus).',
                valeur: nombreTexte(c.sans_contrat),
                detail: 'Salariés actifs',
            },
            {
                id: 'fins-cdd',
                titre: 'Fins de CDD à traiter',
                definition:
                    'CDD en cours dont la fin prévue est dépassée ou atteinte dans les 30 jours : renouvellement, nouveau contrat ou départ à décider.',
                valeur: nombreTexte(fins.length),
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
        return `${pluriel(a.salaries_taux, 'salarié')} pris en compte · ${pluriel(a.salaries_exclus, 'exclu')}`;
    }

    return a.taux_motif === 'aucun_salarie'
        ? 'Aucun salarié avec date d’embauche connue'
        : 'Salariés avec date d’embauche connue';
}

function ligneAbsences(a: AbsencesRh): Ligne {
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
                definition:
                    'Jours calendaires d’absence enregistrée (annulées exclues), dates incluses, sur les jours écoulés de la période.',
                valeur: nombreTexte(a.jours),
                detail: `${pluriel(a.absences, 'absence')} · ${pluriel(a.salaries, 'salarié')}`,
            },
            {
                id: 'taux',
                titre: 'Taux d’absentéisme',
                definition: definitionTaux(a),
                valeur: a.taux === null ? '—' : formatPourcentage(a.taux),
                detail: detailTaux(a),
            },
            {
                id: 'injustifiees',
                titre: 'Absences injustifiées',
                definition:
                    'Jours d’absence au seul motif « Absence injustifiée ».',
                valeur: nombreTexte(a.injustifiees_jours),
                detail: `${pluriel(a.injustifiees_jours, 'jour')} · ${pluriel(a.injustifiees_absences, 'absence')}`,
            },
            {
                id: 'absents',
                titre: 'Absents aujourd’hui',
                definition:
                    'Salariés dont une absence enregistrée couvre aujourd’hui.',
                valeur: nombreTexte(a.absents_aujourdhui),
                detail: `${pluriel(a.a_venir, 'absence')} à venir sur la période`,
            },
        ],
    };
}

const lignes = computed((): Ligne[] => {
    const resultat: Ligne[] = [];
    if (props.effectifs) resultat.push(ligneEffectifs(props.effectifs));
    if (props.contrats)
        resultat.push(ligneContrats(props.contrats, props.finsCdd ?? []));
    if (props.absences) resultat.push(ligneAbsences(props.absences));

    return resultat;
});
</script>

<template>
    <template v-for="ligne in lignes" :key="ligne.id">
        <h2
            class="col-span-12 -mb-4 text-sm font-semibold text-[var(--p-text-muted-color)]"
            :data-testid="`rh-ligne-${ligne.id}`"
        >
            {{ ligne.titre }}
            <span class="font-normal">· {{ ligne.precision }}</span>
        </h2>
        <div
            v-for="carte in ligne.cartes"
            :key="carte.id"
            class="col-span-12 md:col-span-6 xl:col-span-3"
            :data-testid="`kpi-${carte.id}`"
        >
            <div :class="CARTE_APOLLO">
                <span
                    class="text-[15.75px] leading-[24.5px] font-semibold"
                    :title="carte.definition"
                    >{{ carte.titre }}</span
                >
                <div
                    class="mt-3.5 text-[31.5px] leading-[35px] font-bold whitespace-nowrap text-[var(--p-surface-900)] dark:text-[var(--p-surface-0)]"
                    data-slot="valeur"
                >
                    {{ carte.valeur }}
                </div>
                <div
                    class="mt-1 font-medium text-[var(--p-text-muted-color)]"
                    data-slot="detail"
                >
                    {{ carte.detail }}
                </div>
            </div>
        </div>
    </template>
</template>
