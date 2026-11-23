<script setup lang="ts">
// Fenêtres des absences partagées par la liste (RH → Absences) et la fiche salarié : détail,
// saisie / modification, annulation. Un chevauchement refusé propose d'ouvrir l'absence existante.
import type { AbsenceRow, Option } from '@/types/absence';
import { ref } from 'vue';
import AbsenceDetailDialog from './AbsenceDetailDialog.vue';
import AbsenceFormDialog from './AbsenceFormDialog.vue';
import AnnulerAbsenceDialog from './AnnulerAbsenceDialog.vue';

defineProps<{
    motifs: Option[];
    salaries?: Option[];
    salarieFixe?: { id: string; nom: string } | null;
}>();

const formVisible = ref(false);
const absenceModifiee = ref<AbsenceRow | null>(null);
const detailVisible = ref(false);
const detailId = ref<string | null>(null);
const annulationVisible = ref(false);
const absenceAnnulee = ref<AbsenceRow | null>(null);

function nouvelle() {
    absenceModifiee.value = null;
    formVisible.value = true;
}

function ouvrir(id: string) {
    detailId.value = id;
    detailVisible.value = true;
}

function modifier(absence: AbsenceRow) {
    detailVisible.value = false;
    absenceModifiee.value = absence;
    formVisible.value = true;
}

function annuler(absence: AbsenceRow) {
    detailVisible.value = false;
    absenceAnnulee.value = absence;
    annulationVisible.value = true;
}

// Le formulaire reste ouvert derrière : la saisie n'est pas perdue.
function voirExistante(id: string) {
    ouvrir(id);
}

defineExpose({ nouvelle, ouvrir, modifier, annuler });
</script>

<template>
    <AbsenceFormDialog
        v-model:visible="formVisible"
        :absence="absenceModifiee"
        :motifs="motifs"
        :salaries="salaries"
        :salarie-fixe="salarieFixe"
        @voir-absence="voirExistante"
    />
    <AbsenceDetailDialog
        v-model:visible="detailVisible"
        :absence-id="detailId"
        @modifier="modifier"
        @annuler="annuler"
    />
    <AnnulerAbsenceDialog
        v-model:visible="annulationVisible"
        :absence="absenceAnnulee"
    />
</template>
