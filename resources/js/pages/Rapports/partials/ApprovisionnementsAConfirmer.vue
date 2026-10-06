<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { formatGNF } from '@/lib/utils';
import { router } from '@inertiajs/vue3';
import { HandCoins } from 'lucide-vue-next';
import { ref } from 'vue';

// Approvisionnements de MA caisse (ADR 0018) : je suis le seul à pouvoir confirmer avoir reçu les
// espèces, ou contester. Le serveur revérifie la règle (MouvementFondsService::recevoir()).
export interface ApprovisionnementAConfirmer {
    id: string;
    reference: string;
    montant: number;
    statut: string;
    statut_label: string;
    agence: string | null;
    caisse_origine: string | null;
    caisse_destination: string | null;
    remis_par: string | null;
    envoye_le: string | null;
    motif: string | null;
    peut_contester: boolean;
}

defineProps<{ approvisionnements: ApprovisionnementAConfirmer[] }>();

const URL_MOUVEMENTS = '/backoffice/comptabilite/tresorerie/mouvements';

type Action = 'recevoir' | 'contester';

const ouvert = ref(false);
const action = ref<Action>('recevoir');
const cible = ref<ApprovisionnementAConfirmer | null>(null);
const motif = ref('');
const erreur = ref('');
// Posé dans le clic, avant tout rendu : un double clic n'envoie jamais deux fois.
const enCours = ref(false);

function dateHeure(iso: string | null): string {
    if (!iso) return '';
    const d = new Date(iso);
    return `${d.toLocaleDateString('fr-FR')} ${d.toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' })}`;
}

function ouvrir(a: ApprovisionnementAConfirmer, choix: Action) {
    cible.value = a;
    action.value = choix;
    motif.value = '';
    erreur.value = '';
    ouvert.value = true;
}

function valider() {
    if (!cible.value || enCours.value) return;
    if (action.value === 'contester' && motif.value.trim() === '') {
        erreur.value = 'Le motif est obligatoire.';
        return;
    }

    enCours.value = true;
    erreur.value = '';
    router.post(
        `${URL_MOUVEMENTS}/${cible.value.id}/${action.value}`,
        action.value === 'contester' ? { motif: motif.value.trim() } : {},
        {
            preserveScroll: true,
            onSuccess: () => {
                ouvert.value = false;
            },
            onError: (errors) => {
                erreur.value =
                    errors.mouvement ??
                    errors.motif ??
                    errors.compte_tresorerie_destination_id ??
                    'Une erreur est survenue.';
            },
            onFinish: () => {
                enCours.value = false;
            },
        },
    );
}
</script>

<template>
    <section
        class="space-y-3 rounded-xl border border-amber-300 bg-amber-50/60 p-4 dark:border-amber-800 dark:bg-amber-950/30"
        data-testid="approvisionnements-a-confirmer"
    >
        <div class="flex items-center gap-2">
            <HandCoins
                class="h-5 w-5 text-amber-600 dark:text-amber-400"
                aria-hidden="true"
            />
            <h2 class="text-sm font-semibold">
                Espèces à confirmer ({{ approvisionnements.length }})
            </h2>
        </div>
        <p class="text-xs text-muted-foreground">
            Ces montants vous ont été remis depuis la caisse de l'agence. Ils
            n'entrent dans votre caisse qu'après votre confirmation : ne
            confirmez que si vous avez réellement reçu les espèces.
        </p>

        <ul class="divide-y rounded-lg border bg-card">
            <li
                v-for="a in approvisionnements"
                :key="a.id"
                class="flex flex-wrap items-center justify-between gap-3 px-3 py-2.5"
                data-testid="approvisionnement-ligne"
            >
                <div class="min-w-0 space-y-0.5">
                    <div class="flex flex-wrap items-baseline gap-x-2">
                        <span class="text-base font-semibold tabular-nums">{{
                            formatGNF(a.montant)
                        }}</span>
                        <span class="text-xs text-muted-foreground">{{
                            a.reference
                        }}</span>
                        <StatusDot :status="a.statut" :label="a.statut_label" />
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Remis par
                        <span class="font-medium text-foreground">{{
                            a.remis_par ?? '—'
                        }}</span>
                        · {{ a.caisse_origine }} → {{ a.caisse_destination }}
                        <template v-if="a.envoye_le">
                            · {{ dateHeure(a.envoye_le) }}</template
                        >
                    </p>
                    <p v-if="a.motif" class="text-xs text-muted-foreground">
                        {{ a.motif }}
                    </p>
                </div>
                <div class="flex shrink-0 gap-2">
                    <Button
                        v-if="a.peut_contester"
                        type="button"
                        variant="outline"
                        size="sm"
                        data-testid="approvisionnement-contester"
                        @click="ouvrir(a, 'contester')"
                    >
                        Contester
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        data-testid="approvisionnement-recevoir"
                        @click="ouvrir(a, 'recevoir')"
                    >
                        Confirmer la réception
                    </Button>
                </div>
            </li>
        </ul>

        <Dialog
            :open="ouvert"
            @update:open="
                (valeur: boolean) => {
                    if (!enCours) ouvert = valeur;
                }
            "
        >
            <DialogContent class="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>
                        {{
                            action === 'recevoir'
                                ? 'Confirmer la réception'
                                : 'Contester la réception'
                        }}
                        {{ cible?.reference }}
                    </DialogTitle>
                    <DialogDescription v-if="cible && action === 'recevoir'">
                        Je confirme avoir reçu physiquement
                        <strong>{{ formatGNF(cible.montant) }}</strong> de
                        {{ cible.remis_par ?? "l'agence" }}. Ce montant entrera
                        dans ma caisse.
                    </DialogDescription>
                    <DialogDescription v-else-if="cible">
                        Je déclare ne pas avoir reçu les
                        {{ formatGNF(cible.montant) }}. L'agence devra constater
                        le retour des fonds.
                    </DialogDescription>
                </DialogHeader>
                <div v-if="action === 'contester'" class="space-y-1.5">
                    <Label for="appro-motif-contestation">Motif</Label>
                    <textarea
                        id="appro-motif-contestation"
                        v-model="motif"
                        rows="3"
                        placeholder="Expliquez la raison…"
                        class="flex min-h-[80px] w-full rounded-md border border-input bg-background px-3 py-2 text-sm placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    />
                </div>
                <p
                    v-if="erreur"
                    class="text-xs text-red-600 dark:text-red-400"
                    data-testid="approvisionnement-erreur"
                >
                    {{ erreur }}
                </p>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="enCours"
                        @click="ouvert = false"
                    >
                        Annuler
                    </Button>
                    <Button
                        type="button"
                        :variant="
                            action === 'contester' ? 'destructive' : 'default'
                        "
                        :disabled="enCours"
                        :aria-busy="enCours"
                        data-testid="approvisionnement-valider"
                        @click="valider"
                    >
                        <Spinner v-if="enCours" />
                        {{
                            enCours
                                ? 'Envoi…'
                                : action === 'recevoir'
                                  ? "J'ai reçu les espèces"
                                  : 'Contester'
                        }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </section>
</template>
