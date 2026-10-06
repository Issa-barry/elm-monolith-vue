/** Date ISO (Y-m-d ou Y-m-d H:i) → jj/mm/aaaa. */
export function dateFr(iso: string | null | undefined): string {
    return iso ? iso.slice(0, 10).split('-').reverse().join('/') : '—';
}

/** « Y-m-d H:i » → « jj/mm/aaaa HH:MM ». */
export function heureFr(dateHeure: string | null | undefined): string {
    return dateHeure ? `${dateFr(dateHeure)} ${dateHeure.slice(11, 16)}` : '—';
}

export function pluriel(n: number, singulier: string, pluriel?: string) {
    return `${n} ${n > 1 ? (pluriel ?? `${singulier}s`) : singulier}`;
}
