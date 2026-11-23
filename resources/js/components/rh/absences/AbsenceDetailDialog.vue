<script setup lang="ts">
// Détail d'une absence (docs/rh-absences.md), chargé à l'ouverture : motif, dates, durée, commentaire
// complet, justificatif, traçabilité. Les actions Modifier / Annuler ne sont affichées que si le
// serveur les accepterait (peut_modifier / peut_annuler).
import AuditDrawer from '@/components/AuditDrawer.vue';
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import {
    BASE_ABSENCES,
    libelleDuree,
    type AbsenceDetail,
} from '@/types/absence';
import {
    ExternalLink,
    History,
    Loader2,
    Paperclip,
    Pencil,
    XCircle,
} from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import { ref, watch } from 'vue';

const props = defineProps<{
    visible: boolean;
    absenceId: string | null;
}>();

const emit = defineEmits<{
    'update:visible': [value: boolean];
    modifier: [absence: AbsenceDetail];
    annuler: [absence: AbsenceDetail];
}>();

const absence = ref<AbsenceDetail | null>(null);
const chargement = ref(false);
const erreur = ref<string | null>(null);
const historiqueVisible = ref(false);

async function charger(id: string) {
    chargement.value = true;
    erreur.value = null;
    absence.value = null;
    try {
        const res = await fetch(`${BASE_ABSENCES}/${id}`, {
            headers: { Accept: 'application/json' },
        });
        if (res.status === 403)
            throw new Error('Vous n’avez pas accès à cette absence.');
        if (!res.ok) throw new Error('Impossible de charger l’absence.');
        absence.value = (await res.json()) as AbsenceDetail;
    } catch (e) {
        erreur.value =
            e instanceof Error ? e.message : 'Impossible de charger l’absence.';
    } finally {
        chargement.value = false;
    }
}

watch(
    () => [props.visible, props.absenceId] as const,
    ([visible, id]) => {
        if (visible && id) charger(id);
    },
    { immediate: true },
);
</script>

<template>
    <Dialog
        :visible="visible"
        modal
        header="Absence"
        :style="{ width: 'min(32rem, 95vw)' }"
        :draggable="false"
        data-testid="absence-detail"
        @update:visible="(v: boolean) => emit('update:visible', v)"
    >
        <div
            v-if="chargement"
            class="flex items-center justify-center py-10 text-muted-foreground"
        >
            <Loader2 class="h-5 w-5 animate-spin" />
        </div>
        <p v-else-if="erreur" class="py-6 text-sm text-destructive">
            {{ erreur }}
        </p>
        <div v-else-if="absence" class="space-y-4 text-sm">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p class="font-semibold">{{ absence.salarie }}</p>
                    <p class="text-xs text-muted-foreground">
                        <template v-if="absence.matricule"
                            >Matricule {{ absence.matricule }}</template
                        >
                        <template v-if="absence.matricule && absence.agence">
                            ·
                        </template>
                        <template v-if="absence.agence">{{
                            absence.agence
                        }}</template>
                    </p>
                </div>
                <StatusDot :status="absence.etat" :label="absence.etat_label" />
            </div>

            <dl class="grid grid-cols-2 gap-x-6 gap-y-3 border-t pt-4">
                <div class="col-span-2">
                    <dt class="text-xs text-muted-foreground">Motif</dt>
                    <dd class="font-medium">{{ absence.motif }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Du</dt>
                    <dd>{{ absence.date_debut_label }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-muted-foreground">Au</dt>
                    <dd>{{ absence.date_fin_label }}</dd>
                </div>
                <div class="col-span-2">
                    <dt class="text-xs text-muted-foreground">Durée</dt>
                    <dd class="font-medium">
                        {{ libelleDuree(absence.duree_jours) }}
                    </dd>
                </div>
                <div class="col-span-2">
                    <dt class="text-xs text-muted-foreground">Commentaire</dt>
                    <dd class="break-words whitespace-pre-line">
                        {{ absence.commentaire ?? '—' }}
                    </dd>
                </div>
                <div class="col-span-2">
                    <dt class="text-xs text-muted-foreground">Justificatif</dt>
                    <dd>
                        <a
                            v-if="absence.a_justificatif"
                            :href="`${BASE_ABSENCES}/${absence.id}/justificatif`"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex items-center gap-1.5 text-primary hover:underline"
                            data-testid="absence-justificatif-lien"
                        >
                            <Paperclip class="h-3.5 w-3.5" />
                            {{ absence.justificatif_nom ?? 'Consulter' }}
                            <ExternalLink class="h-3 w-3" />
                        </a>
                        <span v-else class="text-muted-foreground">Aucun</span>
                    </dd>
                </div>
            </dl>

            <div
                class="space-y-0.5 border-t pt-3 text-xs text-muted-foreground"
            >
                <p>
                    Saisie le {{ absence.saisie_le }}
                    <template v-if="absence.auteur">
                        par {{ absence.auteur }}</template
                    >
                </p>
                <p v-if="absence.modifie_le">
                    Modifiée le {{ absence.modifie_le }}
                    <template v-if="absence.modifie_par">
                        par {{ absence.modifie_par }}</template
                    >
                </p>
                <p v-if="absence.annule_le" class="text-foreground">
                    Annulée le {{ absence.annule_le }}
                    <template v-if="absence.annule_par">
                        par {{ absence.annule_par }}</template
                    >
                    <template v-if="absence.motif_annulation">
                        — {{ absence.motif_annulation }}</template
                    >
                </p>
            </div>

            <div class="flex flex-wrap justify-end gap-2 border-t pt-4">
                <Button
                    variant="ghost"
                    size="sm"
                    data-testid="absence-historique"
                    @click="historiqueVisible = true"
                >
                    <History class="mr-1.5 h-3.5 w-3.5" />
                    Historique
                </Button>
                <Button
                    v-if="absence.peut_annuler"
                    variant="outline"
                    size="sm"
                    class="text-destructive"
                    data-testid="absence-annuler"
                    @click="emit('annuler', absence)"
                >
                    <XCircle class="mr-1.5 h-3.5 w-3.5" />
                    Annuler l’absence
                </Button>
                <Button
                    v-if="absence.peut_modifier"
                    size="sm"
                    data-testid="absence-modifier"
                    @click="emit('modifier', absence)"
                >
                    <Pencil class="mr-1.5 h-3.5 w-3.5" />
                    Modifier
                </Button>
            </div>
        </div>
    </Dialog>

    <AuditDrawer
        v-model:visible="historiqueVisible"
        :title="`Historique — absence de ${absence?.salarie ?? ''}`"
        auditable-type="absence"
        :auditable-id="absence?.id ?? ''"
        :endpoint="`${BASE_ABSENCES}/${absence?.id ?? ''}/historique`"
    />
</template>
