<script setup lang="ts">
/**
 * Rattachement manuel d'un compte existant à un parrain (ADR 0050, COMPTE-001 et COMPTE-002). Un
 * numéro identique ne prouve rien : le responsable contrôle le compte proposé et confirme avoir
 * vérifié l'identité de la personne. Le serveur revérifie tout et refuse avec le même motif.
 */
import PhoneCountryInput from '@/components/PhoneCountryInput.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { formatPhoneDisplay } from '@/lib/utils';
import { router } from '@inertiajs/vue3';
import { Loader2 } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import { computed, ref, watch } from 'vue';
import { libelleRole, type CompteCandidat } from './types';

const props = defineProps<{
    visible: boolean;
    parrainId: string;
    telephoneParrain: string | null;
}>();

const emit = defineEmits<{
    'update:visible': [boolean];
}>();

const telephone = ref('');
const recherche = ref(false);
const rechercheFaite = ref(false);
const candidat = ref<CompteCandidat | null>(null);
const identiteVerifiee = ref(false);
const enCours = ref(false);
const erreur = ref<string | null>(null);

const peutConfirmer = computed(
    () =>
        !enCours.value &&
        candidat.value?.id != null &&
        candidat.value.motif_refus === null &&
        identiteVerifiee.value,
);

const inscritLe = computed(() =>
    candidat.value?.inscrit_le
        ? new Date(candidat.value.inscrit_le).toLocaleDateString('fr-FR')
        : '—',
);

function reinitialiserResultat() {
    rechercheFaite.value = false;
    candidat.value = null;
    identiteVerifiee.value = false;
    erreur.value = null;
}

async function rechercher() {
    if (!telephone.value || recherche.value) return;

    recherche.value = true;
    reinitialiserResultat();

    try {
        const params = new URLSearchParams({ telephone: telephone.value });
        const response = await fetch(
            `/backoffice/parrains/${props.parrainId}/compte/candidat?${params.toString()}`,
            { headers: { Accept: 'application/json' } },
        );

        if (!response.ok) {
            erreur.value = 'Le numéro de téléphone est invalide.';
            return;
        }

        const body: { compte: CompteCandidat | null } = await response.json();
        candidat.value = body.compte;
        rechercheFaite.value = true;
    } catch {
        erreur.value = 'Impossible de vérifier ce numéro pour le moment.';
    } finally {
        recherche.value = false;
    }
}

watch(
    () => props.visible,
    (visible) => {
        if (!visible) return;

        telephone.value = props.telephoneParrain ?? '';
        enCours.value = false;
        reinitialiserResultat();
        void rechercher();
    },
    { immediate: true },
);

// Le compte affiché doit toujours correspondre au numéro saisi : un autre numéro efface le résultat.
watch(telephone, () => {
    if (!recherche.value) reinitialiserResultat();
});

function fermer(visible: boolean) {
    if (!enCours.value) emit('update:visible', visible);
}

function confirmer() {
    if (!peutConfirmer.value || !candidat.value?.id) return;

    enCours.value = true;
    erreur.value = null;

    router.post(
        `/backoffice/parrains/${props.parrainId}/compte`,
        { compte_id: candidat.value.id, identite_verifiee: true },
        {
            preserveScroll: true,
            onSuccess: () => emit('update:visible', false),
            onError: (errors) => {
                erreur.value =
                    errors.compte ??
                    errors.identite_verifiee ??
                    errors.compte_id ??
                    'Le rattachement a échoué.';
            },
            onFinish: () => {
                enCours.value = false;
            },
        },
    );
}
</script>

<template>
    <Dialog
        :visible="visible"
        modal
        header="Rattacher un compte"
        :closable="!enCours"
        :close-on-escape="!enCours"
        :draggable="false"
        :style="{ width: 'min(34rem, 94vw)' }"
        @update:visible="fermer"
    >
        <div class="space-y-4">
            <p class="text-sm text-muted-foreground">
                Le numéro n'est pas vérifié à l'inscription : le compte proposé
                peut appartenir à quelqu'un d'autre. Contrôlez ses informations
                et confirmez l'identité auprès de la personne.
            </p>

            <div class="space-y-1.5">
                <Label for="rattachement-telephone">Numéro du compte</Label>
                <div class="flex items-start gap-2">
                    <div class="min-w-0 flex-1">
                        <PhoneCountryInput
                            id="rattachement-telephone"
                            v-model="telephone"
                            :remember-choice="false"
                            :required="false"
                        />
                    </div>
                    <Button
                        variant="outline"
                        data-testid="rattachement-rechercher"
                        :disabled="!telephone || recherche || enCours"
                        @click="rechercher"
                    >
                        {{ recherche ? 'Recherche…' : 'Rechercher' }}
                    </Button>
                </div>
            </div>

            <p
                v-if="rechercheFaite && !candidat"
                class="rounded-lg border border-dashed p-4 text-center text-sm text-muted-foreground"
                data-testid="rattachement-aucun-compte"
            >
                Aucun compte n'utilise ce numéro. La personne doit d'abord
                s'inscrire, ou vous pouvez chercher un autre numéro.
            </p>

            <div
                v-if="candidat"
                class="space-y-3 rounded-lg border p-4"
                data-testid="rattachement-candidat"
            >
                <dl
                    v-if="candidat.id"
                    class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm"
                >
                    <dt class="text-muted-foreground">Nom saisi</dt>
                    <dd class="font-medium">
                        {{ candidat.nom ?? 'Non renseigné' }}
                    </dd>
                    <dt class="text-muted-foreground">Téléphone</dt>
                    <dd>
                        {{ formatPhoneDisplay(candidat.telephone, null) }}
                    </dd>
                    <dt class="text-muted-foreground">E-mail</dt>
                    <dd>{{ candidat.email ?? '—' }}</dd>
                    <dt class="text-muted-foreground">Inscrit le</dt>
                    <dd>{{ inscritLe }}</dd>
                    <dt class="text-muted-foreground">Rôles</dt>
                    <dd>
                        {{
                            candidat.roles.length
                                ? candidat.roles.map(libelleRole).join(', ')
                                : '—'
                        }}
                    </dd>
                </dl>

                <p
                    v-if="candidat.motif_refus"
                    class="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive"
                    data-testid="rattachement-motif-refus"
                >
                    {{ candidat.motif_refus }}
                </p>

                <div v-else class="flex items-start gap-2 border-t pt-3">
                    <Checkbox
                        id="rattachement-identite"
                        :model-value="identiteVerifiee"
                        :disabled="enCours"
                        class="mt-0.5"
                        data-testid="rattachement-identite"
                        @update:model-value="identiteVerifiee = $event === true"
                    />
                    <Label
                        for="rattachement-identite"
                        class="text-sm leading-snug font-normal"
                    >
                        J'ai vérifié que ce compte appartient bien à ce parrain.
                        Il aura accès à ses commissions et à ses paiements.
                    </Label>
                </div>
            </div>

            <p
                v-if="erreur"
                class="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive"
                data-testid="rattachement-erreur"
            >
                {{ erreur }}
            </p>
        </div>

        <template #footer>
            <Button
                variant="outline"
                :disabled="enCours"
                @click="fermer(false)"
            >
                Annuler
            </Button>
            <Button
                data-testid="rattachement-confirmer"
                :disabled="!peutConfirmer"
                :aria-busy="enCours"
                @click="confirmer"
            >
                <Loader2 v-if="enCours" class="mr-1.5 h-4 w-4 animate-spin" />
                {{ enCours ? 'Confirmation…' : 'Rattacher ce compte' }}
            </Button>
        </template>
    </Dialog>
</template>
