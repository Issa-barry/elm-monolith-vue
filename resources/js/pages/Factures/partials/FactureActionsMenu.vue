<script setup lang="ts">
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { usePermissions } from '@/composables/usePermissions';
import { CreditCard, Download, History, MoreVertical } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    facture: {
        id: string | number;
        reference: string;
        is_annulee: boolean;
        is_payee: boolean;
        is_encaissable: boolean;
    };
}>();

defineEmits<{ historique: []; encaisser: [] }>();

const { can } = usePermissions();

const peutEncaisser = computed(
    () =>
        !props.facture.is_annulee &&
        !props.facture.is_payee &&
        can('ventes.update'),
);

// Indépendant de l'encaissement : une facture payée ou annulée reste téléchargeable.
const urlPdf = computed(() => `/backoffice/factures/${props.facture.id}/pdf`);
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="h-8 w-8"
                :aria-label="`Actions de la facture ${facture.reference}`"
            >
                <MoreVertical class="h-4 w-4" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-56">
            <DropdownMenuItem
                class="cursor-pointer"
                data-testid="action-historique"
                @click="$emit('historique')"
            >
                <History class="h-4 w-4" />
                Historique
            </DropdownMenuItem>
            <DropdownMenuSeparator v-if="peutEncaisser || can('ventes.read')" />
            <DropdownMenuItem
                v-if="peutEncaisser"
                class="cursor-pointer"
                data-testid="action-encaisser"
                :disabled="!facture.is_encaissable"
                :title="
                    !facture.is_encaissable
                        ? 'Encaissement possible uniquement après validation du chargement.'
                        : ''
                "
                @click="facture.is_encaissable && $emit('encaisser')"
            >
                <CreditCard class="h-4 w-4" />
                Encaisser
            </DropdownMenuItem>
            <DropdownMenuItem
                v-if="can('ventes.read')"
                as-child
                class="cursor-pointer"
                data-testid="action-telecharger"
            >
                <a :href="urlPdf" download>
                    <Download class="h-4 w-4" />
                    Télécharger la facture
                </a>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
