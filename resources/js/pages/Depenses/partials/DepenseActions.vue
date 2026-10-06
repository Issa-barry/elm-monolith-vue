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
import type { DepenseRow } from '@/types/depense';
import { Link } from '@inertiajs/vue3';
import {
    Check,
    Eye,
    History,
    MoreHorizontal,
    Pencil,
    Send,
    Trash2,
    X,
} from 'lucide-vue-next';

defineProps<{ d: DepenseRow }>();
const emit = defineEmits<{
    audit: [id: string];
    soumettre: [id: string];
    valider: [id: string];
    rejeter: [id: string];
    supprimer: [id: string];
}>();
const { can } = usePermissions();
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="h-11 w-11 sm:h-8 sm:w-8"
                aria-label="Actions"
            >
                <MoreHorizontal class="h-4 w-4" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent
            align="end"
            class="w-56 sm:w-48 [&_[role=menuitem]]:min-h-11 sm:[&_[role=menuitem]]:min-h-0"
        >
            <!-- Voir -->
            <DropdownMenuItem as-child>
                <Link
                    :href="`/backoffice/depenses/${d.id}`"
                    class="flex w-full items-center gap-2"
                >
                    <Eye class="h-4 w-4" />
                    Voir le détail
                </Link>
            </DropdownMenuItem>

            <!-- Historique -->
            <DropdownMenuItem
                class="cursor-pointer"
                @click="emit('audit', d.id)"
            >
                <History class="h-4 w-4" />
                Historique
            </DropdownMenuItem>

            <!-- Modifier (brouillon, rejeté ou annulé) -->
            <DropdownMenuItem
                v-if="
                    ['brouillon', 'rejete', 'annule'].includes(d.statut) &&
                    can('depenses.update')
                "
                as-child
            >
                <Link
                    :href="`/backoffice/depenses/${d.id}/edit`"
                    class="flex w-full items-center gap-2"
                >
                    <Pencil class="h-4 w-4" />
                    Modifier
                </Link>
            </DropdownMenuItem>

            <DropdownMenuSeparator />

            <!-- Soumettre (brouillon) -->
            <DropdownMenuItem
                v-if="d.statut === 'brouillon'"
                class="cursor-pointer"
                @click="emit('soumettre', d.id)"
            >
                <Send class="h-4 w-4" />
                Soumettre
            </DropdownMenuItem>

            <!-- Valider -->
            <DropdownMenuItem
                v-if="d.can_valider"
                class="cursor-pointer text-emerald-700 focus:text-emerald-700"
                @click="emit('valider', d.id)"
            >
                <Check class="h-4 w-4" />
                Valider
            </DropdownMenuItem>

            <!-- Rejeter -->
            <DropdownMenuItem
                v-if="d.can_valider"
                class="cursor-pointer text-destructive focus:text-destructive"
                @click="emit('rejeter', d.id)"
            >
                <X class="h-4 w-4" />
                Rejeter
            </DropdownMenuItem>

            <DropdownMenuSeparator
                v-if="d.statut === 'brouillon' && can('depenses.delete')"
            />

            <!-- Supprimer (brouillon seulement) -->
            <DropdownMenuItem
                v-if="d.statut === 'brouillon' && can('depenses.delete')"
                class="cursor-pointer text-destructive focus:text-destructive"
                @click="emit('supprimer', d.id)"
            >
                <Trash2 class="h-4 w-4" />
                Supprimer
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
