<script setup lang="ts">
// Bloc « Absences » de la fiche salarié (docs/rh-absences.md) : absences passées, en cours et à
// venir, annulées comprises. Transmis par le serveur seulement avec `rh-absences.read`.
import GestionAbsences from '@/components/rh/absences/GestionAbsences.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { useFlashToast } from '@/composables/useFlashToast';
import { libelleDuree, type AbsenceRow, type Option } from '@/types/absence';
import { CalendarOff, ChevronDown, Paperclip, Plus } from 'lucide-vue-next';
import { computed, ref } from 'vue';
import {
    CARTE_FICHE,
    ENTETE_CARTE_FICHE,
    TITRE_CARTE_FICHE,
} from './carteFiche';

export interface AbsencesOptions {
    peut_saisir: boolean;
    motifs: Option[];
}

const props = defineProps<{
    employeId: string;
    employeNom: string;
    absences: AbsenceRow[];
    options: AbsencesOptions;
    /**
     * Affiche les messages de succès du serveur. Faux quand un autre bloc de la fiche s'en charge
     * déjà (Primes et avantages), pour ne pas afficher deux fois le même message.
     */
    afficherFlash?: boolean;
}>();

if (props.afficherFlash) useFlashToast();

/** Fiche compacte : les plus récentes d'abord, le reste sur demande. */
const LIMITE = 5;
const toutAfficher = ref(false);
const visibles = computed(() =>
    toutAfficher.value ? props.absences : props.absences.slice(0, LIMITE),
);
const masquees = computed(() => props.absences.length - LIMITE);

const gestion = ref<InstanceType<typeof GestionAbsences> | null>(null);
</script>

<template>
    <section :class="CARTE_FICHE" data-testid="employe-absences">
        <div :class="ENTETE_CARTE_FICHE">
            <h2 :class="TITRE_CARTE_FICHE">
                <CalendarOff class="h-4 w-4 text-muted-foreground" />
                Absences
                <span
                    v-if="absences.length > 0"
                    class="text-xs font-normal text-muted-foreground"
                    >({{ absences.length }})</span
                >
            </h2>
            <Button
                v-if="options.peut_saisir"
                size="sm"
                variant="outline"
                data-testid="employe-absence-nouvelle"
                @click="gestion?.nouvelle()"
            >
                <Plus class="mr-1.5 h-3.5 w-3.5" />
                Nouvelle absence
            </Button>
        </div>

        <p
            v-if="absences.length === 0"
            class="px-5 py-6 text-sm text-muted-foreground sm:px-6"
        >
            Aucune absence enregistrée.
        </p>
        <ul v-else class="divide-y">
            <li v-for="a in visibles" :key="a.id">
                <button
                    type="button"
                    class="flex w-full flex-wrap items-start justify-between gap-x-4 gap-y-1 px-5 py-3 text-left text-sm hover:bg-muted/30 sm:px-6"
                    :class="{ 'opacity-60': a.statut === 'annulee' }"
                    data-testid="employe-absence"
                    @click="gestion?.ouvrir(a.id)"
                >
                    <div class="min-w-0 flex-1">
                        <p class="flex items-center gap-1.5 font-medium">
                            {{ a.motif }}
                            <Paperclip
                                v-if="a.a_justificatif"
                                class="h-3.5 w-3.5 text-muted-foreground"
                                aria-label="Justificatif joint"
                            />
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Du {{ a.date_debut_label }} au
                            {{ a.date_fin_label }} ·
                            {{ libelleDuree(a.duree_jours) }}
                        </p>
                        <p
                            v-if="a.commentaire"
                            class="mt-0.5 truncate text-xs text-muted-foreground"
                            :title="a.commentaire"
                        >
                            {{ a.commentaire }}
                        </p>
                    </div>
                    <StatusDot
                        :status="a.etat"
                        :label="a.etat_label"
                        size="sm"
                    />
                </button>
            </li>
        </ul>
        <div
            v-if="!toutAfficher && masquees > 0"
            class="border-t px-5 py-2 sm:px-6"
        >
            <Button
                variant="ghost"
                size="sm"
                class="h-8 px-2"
                @click="toutAfficher = true"
            >
                <ChevronDown class="mr-1.5 h-3.5 w-3.5" />
                Afficher {{ masquees }} absence{{ masquees > 1 ? 's' : '' }} de
                plus
            </Button>
        </div>

        <GestionAbsences
            ref="gestion"
            :motifs="options.motifs"
            :salarie-fixe="{ id: employeId, nom: employeNom }"
        />
    </section>
</template>
