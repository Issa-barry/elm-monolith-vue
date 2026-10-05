<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useFlashToast } from '@/composables/useFlashToast';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatGNF } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Loader2 } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import { computed, ref } from 'vue';

interface Remise {
    id: string;
    reference: string;
    nature_label: string;
    montant: number;
    date_envoi: string | null;
    date_reception: string | null;
    support_origine: string | null;
    support_destination: string | null;
    envoye_par: string | null;
    recu_par: string | null;
    statut: string;
    statut_label: string;
    /** Policy `recevoir` côté serveur : permission tresorerie.recevoir, état et affectation au site qui reçoit. */
    peut_recevoir: boolean;
}

const props = defineProps<{
    central: { id: string; nom: string };
    agence: { id: string; nom: string };
    ligne: {
        attendu: number | null;
        deja_remis: number;
        en_transit: number;
        reste_a_recevoir: number | null;
        remise_obligatoire: number | null;
        excedent_a_remettre: number | null;
        statut: string;
        statut_label: string;
    } | null;
    remises: Remise[];
    supports_reception: { id: string; libelle: string }[];
    filters: { annee: string; mois: string };
}>();

useFlashToast('top');

const URL_REMISES = '/backoffice/comptabilite/tresorerie/remises';

const breadcrumbs = computed((): BreadcrumbItem[] => [
    { title: 'Tableau de bord', href: '/backoffice/dashboard' },
    { title: 'Comptabilité' },
    {
        title: 'Remises des agences',
        href: `${URL_REMISES}?annee=${props.filters.annee}&mois=${props.filters.mois}`,
    },
    { title: props.agence.nom, href: '#' },
]);

function dateFr(iso: string | null): string {
    return iso ? new Date(`${iso}T00:00:00`).toLocaleDateString('fr-FR') : '—';
}

function montant(valeur: number | null | undefined): string {
    return valeur === null || valeur === undefined ? '—' : formatGNF(valeur);
}

// ── Confirmation de réception (route existante mouvements.recevoir) ───────────

const aRecevoir = ref<Remise | null>(null);
const supportId = ref('');
const erreur = ref('');
const enCours = ref(false);

function ouvrirReception(remise: Remise) {
    aRecevoir.value = remise;
    supportId.value =
        props.supports_reception.length === 1
            ? props.supports_reception[0].id
            : '';
    erreur.value = '';
}

function fermer(visible: boolean) {
    if (!visible && !enCours.value) aRecevoir.value = null;
}

function confirmerReception() {
    if (!aRecevoir.value || !supportId.value || enCours.value) return;
    enCours.value = true;
    router.post(
        `/backoffice/comptabilite/tresorerie/mouvements/${aRecevoir.value.id}/recevoir`,
        { compte_tresorerie_destination_id: supportId.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                aRecevoir.value = null;
            },
            onError: (errors) => {
                erreur.value =
                    Object.values(errors)[0] ?? 'Réception impossible.';
            },
            onFinish: () => {
                enCours.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="`Remises — ${agence.nom}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="w-full min-w-0 space-y-6 p-4 sm:p-6">
            <div class="flex flex-col gap-1">
                <Link
                    :href="`${URL_REMISES}?annee=${filters.annee}&mois=${filters.mois}`"
                    class="inline-flex w-fit items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                >
                    <ArrowLeft class="h-4 w-4" aria-hidden="true" />
                    Remises des agences
                </Link>
                <h1 class="text-xl font-semibold">
                    Remises de {{ agence.nom }} à {{ central.nom }}
                </h1>
            </div>

            <div v-if="ligne" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-sm text-muted-foreground">
                        Reste à recevoir
                    </p>
                    <p
                        class="mt-1 text-2xl font-bold text-amber-600 tabular-nums dark:text-amber-400"
                    >
                        {{ montant(ligne.reste_a_recevoir) }}
                    </p>
                    <p
                        v-if="ligne.reste_a_recevoir"
                        class="mt-0.5 text-xs text-muted-foreground"
                    >
                        dont {{ montant(ligne.remise_obligatoire) }} d'autres
                        agences
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-sm text-muted-foreground">Déjà remis</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums">
                        {{ formatGNF(ligne.deja_remis) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-sm text-muted-foreground">En transit</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums">
                        {{ formatGNF(ligne.en_transit) }}
                    </p>
                </div>
                <div class="rounded-xl border bg-card p-4">
                    <p class="text-sm text-muted-foreground">Montant attendu</p>
                    <p class="mt-1 text-2xl font-bold tabular-nums">
                        {{ montant(ligne.attendu) }}
                    </p>
                    <div class="mt-1">
                        <StatusDot
                            :status="ligne.statut"
                            :label="ligne.statut_label"
                        />
                    </div>
                </div>
            </div>

            <div class="overflow-x-auto rounded-xl border bg-card">
                <table class="w-max min-w-full text-sm whitespace-nowrap">
                    <thead>
                        <tr class="border-b bg-muted/40 text-left">
                            <th class="px-4 py-3 font-medium">Référence</th>
                            <th class="px-4 py-3 font-medium">Envoyée le</th>
                            <th class="px-4 py-3 font-medium">Depuis</th>
                            <th class="px-4 py-3 text-right font-medium">
                                Montant
                            </th>
                            <th class="px-4 py-3 font-medium">Reçue</th>
                            <th class="px-4 py-3 font-medium">Statut</th>
                            <th class="px-4 py-3">
                                <span class="sr-only">Actions</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="remise in remises"
                            :key="remise.id"
                            data-testid="remise"
                        >
                            <td class="px-4 py-3">
                                <div class="font-medium">
                                    {{ remise.reference }}
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    {{ remise.nature_label }}
                                </div>
                            </td>
                            <td class="px-4 py-3 tabular-nums">
                                {{ dateFr(remise.date_envoi) }}
                                <div
                                    v-if="remise.envoye_par"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ remise.envoye_par }}
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                {{ remise.support_origine ?? '—' }}
                            </td>
                            <td
                                class="px-4 py-3 text-right font-semibold tabular-nums"
                            >
                                {{ formatGNF(remise.montant) }}
                            </td>
                            <td class="px-4 py-3">
                                <template v-if="remise.date_reception">
                                    {{ dateFr(remise.date_reception) }}
                                    <div class="text-xs text-muted-foreground">
                                        {{ remise.support_destination }}
                                        <template v-if="remise.recu_par">
                                            · {{ remise.recu_par }}
                                        </template>
                                    </div>
                                </template>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                            <td class="px-4 py-3">
                                <StatusDot
                                    :status="remise.statut"
                                    :label="remise.statut_label"
                                />
                            </td>
                            <td class="px-4 py-3 text-right">
                                <Button
                                    v-if="remise.peut_recevoir"
                                    size="sm"
                                    data-testid="remise-recevoir"
                                    @click="ouvrirReception(remise)"
                                >
                                    Confirmer la réception
                                </Button>
                            </td>
                        </tr>
                        <tr v-if="remises.length === 0">
                            <td
                                colspan="7"
                                class="px-4 py-10 text-center text-muted-foreground"
                            >
                                Aucune remise sur la période ni en transit.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>

    <Dialog
        :visible="aRecevoir !== null"
        modal
        header="Confirmer la réception"
        :closable="!enCours"
        :style="{ width: 'min(460px, 95vw)' }"
        @update:visible="fermer"
    >
        <form
            v-if="aRecevoir"
            class="space-y-4 pb-1"
            data-testid="reception-form"
            @submit.prevent="confirmerReception"
        >
            <p class="text-sm">
                {{ aRecevoir.reference }} ·
                <span class="font-semibold tabular-nums">{{
                    formatGNF(aRecevoir.montant)
                }}</span>
                envoyé par {{ agence.nom }}.
            </p>
            <div>
                <Label
                    for="reception-support"
                    class="mb-1.5 block text-xs font-medium"
                >
                    Compte qui a reçu les fonds
                    <span class="text-destructive">*</span>
                </Label>
                <select
                    id="reception-support"
                    v-model="supportId"
                    :disabled="enCours"
                    class="h-9 w-full rounded-md border border-input bg-background px-3 text-sm"
                >
                    <option value="" disabled>Choisir un compte…</option>
                    <option
                        v-for="s in supports_reception"
                        :key="s.id"
                        :value="s.id"
                    >
                        {{ s.libelle }}
                    </option>
                </select>
                <p v-if="erreur" class="mt-1 text-xs text-destructive">
                    {{ erreur }}
                </p>
            </div>
            <div class="flex justify-between pt-2">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="enCours"
                    @click="aRecevoir = null"
                    >Annuler</Button
                >
                <Button
                    type="submit"
                    size="sm"
                    :disabled="enCours || !supportId"
                >
                    <Loader2
                        v-if="enCours"
                        class="mr-1.5 h-3.5 w-3.5 animate-spin"
                        aria-hidden="true"
                    />
                    Confirmer
                </Button>
            </div>
        </form>
    </Dialog>
</template>
