export function stripHtml(value: string): string {
    if (!value) return '';
    const doc = new DOMParser().parseFromString(value, 'text/html');
    return (doc.body.textContent ?? '').trim().replace(/\s+/g, ' ');
}
