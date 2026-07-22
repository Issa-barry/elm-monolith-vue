<script setup lang="ts">
import StatusDot from '@/components/StatusDot.vue';
import { Button } from '@/components/ui/button';
import { useForm } from '@inertiajs/vue3';
import { IdCard, Pencil, Plus, ShieldCheck, ShieldX, Trash2 } from 'lucide-vue-next';
import Dialog from 'primevue/dialog';
import Select from 'primevue/select';
import { ref } from 'vue';

interface TypeOption {
    value: string;
    label: string;
}

interface PieceIdentiteRow {
    id: string;
    type_piece: string;
    type_piece_label: string;
    numero_masque: string | null;
    pays_delivrance: string | null;
    date_delivrance: string | null;
    date_expiration: string | null;
    statut_verification: string;
    statut_verification_label: string;
    statut_affichage: string;
    est_active: boolean;
    motif_rejet: string | null;
    verifiee_le: string | null;
    has_recto: boolean;
    has_verso: boolean;
    created_at: string | null;
}

interface Permissions {
    can_create: boolean;
    can_update: boolean;
    can_delete: boolean;
    can_download: boolean;
    can_valider: boolean;
    can_rejeter: boolean;
}

const props = defineProps<{
    proprietaireId: string;
    pieces: PieceIdentiteRow[];
    permissions: Permissions;
    typePieceOptions: TypeOption[];
}>();

const STATUT_AFFICHAGE_LABEL: Record<string, string> = {
    en_attente: 'En attente',
    validee: 'Validée',
    rejetee: 'Rejetée',
    expiree: 'Expirée',
    expire_bientot: 'Expire bientôt',
};

function fichierUrl(pieceId: string, face: 'recto' | 'verso'): string {
    return `/backoffice/pieces-identite/${pieceId}/fichiers/${face}`;
}

// ── Formulaire ajout / modification ─────────────────────────────────────────

const formDialogVisible = ref(false);
const editingPiece = ref<PieceIdentiteRow | null>(null);

const form = useForm<{
    type_piece: string | null;
    numero: string;
    pays_delivrance: string;
    date_delivrance: string;
    date_expiration: string;
    recto: File | null;
    verso: File | null;
}>({
    type_piece: null,
    numero: '',
    pays_delivrance: '',
    date_delivrance: '',
    date_expiration: '',
    recto: null,
    verso: null,
});

function openCreateDialog() {
    editingPiece.value = null;
    form.reset();
    form.clearErrors();
    formDialogVisible.value = true;
}

function openEditDialog(piece: PieceIdentiteRow) {
    editingPiece.value = piece;
    form.type_piece = piece.type_piece;
    form.numero = '';
    form.pays_delivrance = piece.pays_delivrance ?? '';
    form.date_delivrance = '';
    form.date_expiration = '';
    form.recto = null;
    form.verso = null;
    form.clearErrors();
    formDialogVisible.value = true;
}

function onRectoChange(e: Event) {
    form.recto = (e.target as HTMLInputElement).files?.[0] ?? null;
}

function onVersoChange(e: Event) {
    form.verso = (e.target as HTMLInputElement).files?.[0] ?? null;
}

function submitForm() {
    if (editingPiece.value) {
        // PHP ne peuple jamais $_FILES sur une requête PUT multipart : on poste en
        // spoofant la méthode via un champ _method, pattern standard Laravel/Inertia.
        form.transform((data) => ({ ...data, _method: 'put' })).post(
            `/backoffice/pieces-identite/${editingPiece.value.id}`,
            {
                forceFormData: true,
                onSuccess: () => {
                    formDialogVisible.value = false;
                },
            },
        );
    } else {
        form.post(
            `/backoffice/proprietaires/${props.proprietaireId}/pieces-identite`,
            {
                forceFormData: true,
                onSuccess: () => {
                    formDialogVisible.value = false;
                },
            },
        );
    }
}

// ── Validation / rejet ───────────────────────────────────────────────────────

function valider(piece: PieceIdentiteRow) {
    useForm({}).post(`/backoffice/pieces-identite/${piece.id}/valider`);
}

const rejetDialogVisible = ref(false);
const rejetTargetId = ref<string | null>(null);
const rejetForm = useForm({ motif_rejet: '' });

function openRejetDialog(piece: PieceIdentiteRow) {
    rejetTargetId.value = piece.id;
    rejetForm.reset();
    rejetForm.clearErrors();
    rejetDialogVisible.value = true;
}

function submitRejet() {
    if (!rejetTargetId.value) return;
    rejetForm.post(`/backoffice/pieces-identite/${rejetTargetId.value}/rejeter`, {
        onSuccess: () => {
            rejetDialogVisible.value = false;
        },
    });
}

// ── Suppression ───────────────────────────────────────────────────────────────

function supprimer(piece: PieceIdentiteRow) {
    if (
        !confirm(
            `Supprimer la pièce ${piece.type_piece_label} ? Cette action est réversible (suppression logique).`,
        )
    ) {
        return;
    }
    useForm({}).delete(`/backoffice/pieces-identite/${piece.id}`);
}
</script>

<template>
    <div class="rounded-xl border bg-card p-5 sm:p-6">
        <div
            class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        >
            <div>
                <h2
                    class="text-sm font-semibold tracking-wider text-muted-foreground uppercase"
                >
                    Pièce d'identité
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ pieces.length }} pièce{{ pieces.length > 1 ? 's' : '' }}
                    enregistrée{{ pieces.length > 1 ? 's' : '' }}
                </p>
            </div>
            <Button
                v-if="permissions.can_create"
                size="sm"
                @click="openCreateDialog"
            >
                <Plus class="mr-1.5 h-4 w-4" />
                Ajouter une pièce
            </Button>
        </div>

        <div
            v-if="pieces.length === 0"
            class="rounded-lg border border-dashed py-10 text-center"
        >
            <IdCard class="mx-auto h-10 w-10 text-muted-foreground/30" />
            <p class="mt-3 text-sm text-muted-foreground">
                Aucune pièce d'identité enregistrée pour ce propriétaire.
            </p>
        </div>

        <div v-else class="space-y-3">
            <div
                v-for="piece in pieces"
                :key="piece.id"
                class="rounded-lg border p-4"
                :class="!piece.est_active ? 'opacity-60' : ''"
            >
                <div
                    class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
                >
                    <div class="min-w-0 space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-semibold">{{
                                piece.type_piece_label
                            }}</span>
                            <StatusDot
                                :status="piece.statut_affichage"
                                :label="
                                    STATUT_AFFICHAGE_LABEL[
                                        piece.statut_affichage
                                    ] ?? piece.statut_verification_label
                                "
                            />
                            <span
                                v-if="!piece.est_active"
                                class="text-xs text-muted-foreground"
                                >(historique)</span
                            >
                        </div>
                        <p class="text-xs text-muted-foreground">
                            N° {{ piece.numero_masque ?? '—' }}
                            <span v-if="piece.date_expiration">
                                · Expire le {{ piece.date_expiration }}</span
                            >
                        </p>
                        <p
                            v-if="piece.motif_rejet"
                            class="text-xs text-red-600 dark:text-red-400"
                        >
                            Motif du rejet : {{ piece.motif_rejet }}
                        </p>
                        <div class="flex gap-3 pt-1">
                            <a
                                v-if="piece.has_recto && permissions.can_download"
                                :href="fichierUrl(piece.id, 'recto')"
                                target="_blank"
                                rel="noopener"
                                class="text-xs text-primary hover:underline"
                                >Voir le recto</a
                            >
                            <a
                                v-if="piece.has_verso && permissions.can_download"
                                :href="fichierUrl(piece.id, 'verso')"
                                target="_blank"
                                rel="noopener"
                                class="text-xs text-primary hover:underline"
                                >Voir le verso</a
                            >
                        </div>
                    </div>

                    <div class="flex shrink-0 flex-wrap gap-2">
                        <Button
                            v-if="permissions.can_update"
                            size="sm"
                            variant="outline"
                            @click="openEditDialog(piece)"
                        >
                            <Pencil class="mr-1.5 h-3.5 w-3.5" />
                            Modifier
                        </Button>
                        <Button
                            v-if="
                                permissions.can_valider &&
                                piece.statut_verification !== 'validee'
                            "
                            size="sm"
                            variant="outline"
                            class="text-emerald-600 hover:text-emerald-700"
                            @click="valider(piece)"
                        >
                            <ShieldCheck class="mr-1.5 h-3.5 w-3.5" />
                            Valider
                        </Button>
                        <Button
                            v-if="
                                permissions.can_rejeter &&
                                piece.statut_verification !== 'rejetee'
                            "
                            size="sm"
                            variant="outline"
                            class="text-red-600 hover:text-red-700"
                            @click="openRejetDialog(piece)"
                        >
                            <ShieldX class="mr-1.5 h-3.5 w-3.5" />
                            Rejeter
                        </Button>
                        <Button
                            v-if="permissions.can_delete"
                            size="sm"
                            variant="outline"
                            class="text-red-600 hover:text-red-700"
                            @click="supprimer(piece)"
                        >
                            <Trash2 class="mr-1.5 h-3.5 w-3.5" />
                            Supprimer
                        </Button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Dialog ajout / modification -->
        <Dialog
            v-model:visible="formDialogVisible"
            modal
            :header="
                editingPiece ? 'Modifier la pièce' : 'Ajouter une pièce'
            "
            :style="{ width: '32rem' }"
        >
            <form class="space-y-4" @submit.prevent="submitForm">
                <div>
                    <label class="mb-1.5 block text-sm font-medium"
                        >Type de pièce
                        <span class="text-destructive">*</span></label
                    >
                    <Select
                        v-model="form.type_piece"
                        :options="typePieceOptions"
                        option-label="label"
                        option-value="value"
                        class="w-full"
                    />
                    <p
                        v-if="form.errors.type_piece"
                        class="mt-1 text-xs text-destructive"
                    >
                        {{ form.errors.type_piece }}
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium"
                            >Numéro</label
                        >
                        <input
                            v-model="form.numero"
                            type="text"
                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium"
                            >Pays de délivrance (code ISO2)</label
                        >
                        <input
                            v-model="form.pays_delivrance"
                            type="text"
                            maxlength="2"
                            placeholder="GN"
                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm uppercase focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        />
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium"
                            >Date de délivrance</label
                        >
                        <input
                            v-model="form.date_delivrance"
                            type="date"
                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        />
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium"
                            >Date d'expiration</label
                        >
                        <input
                            v-model="form.date_expiration"
                            type="date"
                            class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            :class="{
                                'border-destructive':
                                    form.errors.date_expiration,
                            }"
                        />
                        <p
                            v-if="form.errors.date_expiration"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ form.errors.date_expiration }}
                        </p>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="mb-1.5 block text-sm font-medium"
                            >Recto
                            <span v-if="!editingPiece" class="text-destructive"
                                >*</span
                            ></label
                        >
                        <input
                            type="file"
                            accept="application/pdf,image/jpeg,image/png"
                            class="block w-full text-xs"
                            @change="onRectoChange"
                        />
                        <p
                            v-if="form.errors.recto"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ form.errors.recto }}
                        </p>
                    </div>
                    <div>
                        <label class="mb-1.5 block text-sm font-medium"
                            >Verso</label
                        >
                        <input
                            type="file"
                            accept="application/pdf,image/jpeg,image/png"
                            class="block w-full text-xs"
                            @change="onVersoChange"
                        />
                        <p
                            v-if="form.errors.verso"
                            class="mt-1 text-xs text-destructive"
                        >
                            {{ form.errors.verso }}
                        </p>
                    </div>
                </div>
                <p class="text-xs text-muted-foreground">
                    Formats acceptés : PDF, JPG, JPEG, PNG — 5 Mo max par
                    fichier.
                    <span v-if="editingPiece"
                        >Laisser vide pour conserver le fichier actuel.</span
                    >
                </p>
            </form>
            <template #footer>
                <Button
                    variant="outline"
                    size="sm"
                    @click="formDialogVisible = false"
                    >Annuler</Button
                >
                <Button size="sm" :disabled="form.processing" @click="submitForm">
                    Enregistrer
                </Button>
            </template>
        </Dialog>

        <!-- Dialog rejet -->
        <Dialog
            v-model:visible="rejetDialogVisible"
            modal
            header="Rejeter la pièce"
            :style="{ width: '28rem' }"
        >
            <form class="space-y-3" @submit.prevent="submitRejet">
                <label class="block text-sm font-medium"
                    >Motif du rejet <span class="text-destructive">*</span></label
                >
                <textarea
                    v-model="rejetForm.motif_rejet"
                    rows="3"
                    class="flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="{
                        'border-destructive': rejetForm.errors.motif_rejet,
                    }"
                />
                <p
                    v-if="rejetForm.errors.motif_rejet"
                    class="text-xs text-destructive"
                >
                    {{ rejetForm.errors.motif_rejet }}
                </p>
            </form>
            <template #footer>
                <Button
                    variant="outline"
                    size="sm"
                    @click="rejetDialogVisible = false"
                    >Annuler</Button
                >
                <Button
                    size="sm"
                    :disabled="rejetForm.processing"
                    class="bg-red-600 text-white hover:bg-red-700"
                    @click="submitRejet"
                >
                    Rejeter
                </Button>
            </template>
        </Dialog>
    </div>
</template>
