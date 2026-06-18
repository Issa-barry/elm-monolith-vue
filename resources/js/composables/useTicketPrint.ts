const PRINT_CSS = `
    @page { size: 80mm auto; margin: 0; }
    html, body { width: 80mm; margin: 0; padding: 0; }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body {
        font-family: monospace;
        font-size: 11px;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    #ticket-root { width: 72mm; margin: 0 auto; padding: 3mm 2mm; }
    .text-center { text-align: center; }
    .text-base { font-size: 14px; }
    .text-sm { font-size: 12px; }
    .text-xs { font-size: 10px; }
    .font-bold { font-weight: bold; }
    .font-semibold { font-weight: 600; }
    .font-medium { font-weight: 500; }
    .uppercase { text-transform: uppercase; }
    .tracking-wide { letter-spacing: 0.05em; }
    .truncate { overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
    .flex { display: flex; }
    .flex-col { flex-direction: column; }
    .justify-between { justify-content: space-between; }
    .items-center { align-items: center; }
    .space-y-1 > * + * { margin-top: 4px; }
    .my-2 { margin: 6px 0; }
    .my-3 { margin: 8px 0; }
    .mb-1 { margin-bottom: 4px; }
    .mb-2 { margin-bottom: 6px; }
    .mb-3 { margin-bottom: 8px; }
    .mt-0\\.5 { margin-top: 2px; }
    .mt-1 { margin-top: 4px; }
    .mt-2 { margin-top: 6px; }
    .border-t { border-top: 1px dashed #999; }
    .border-dashed { border-style: dashed; }
    .text-surface-400, .text-surface-500 { color: #888; }
    .text-surface-900 { color: #111; }
    .dark\\:text-surface-0 { color: #111; }
    img { display: block; width: 96px; height: 96px; }
    .h-24 { height: 96px; } .w-24 { width: 96px; }
    .text-\\[10px\\] { font-size: 10px; }
`;

export function useTicketPrint() {
    function printFromElement(elementId: string): void {
        const el = document.getElementById(elementId);
        if (!el) return;

        const win = window.open('', '_blank', 'width=320,height=600');
        if (!win) return;

        const html = `<!doctype html>
<html>
  <head>
    <meta charset="utf-8" />
    <title>Ticket</title>
    <style>${PRINT_CSS}</style>
  </head>
  <body>
    <div id="ticket-root">${el.innerHTML}</div>
  </body>
</html>`;

        win.document.open();
        win.document.write(html);
        win.document.close();

        win.onafterprint = () => {
            win.close();
        };

        const doPrint = () => {
            win.focus();
            win.print();
        };

        if (win.document.readyState === 'complete') {
            window.setTimeout(doPrint, 80);
        } else {
            win.addEventListener(
                'load',
                () => {
                    window.setTimeout(doPrint, 80);
                },
                { once: true },
            );
        }
    }

    return { printFromElement };
}
