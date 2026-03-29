<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>{{ $commande->reference }}</title>
<style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; background: #fff; }

    /* ── Layout ── */
    .page { padding: 32px 36px; }

    /* ── Header ── */
    .header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 28px; border-bottom: 2px solid #2563eb; padding-bottom: 18px; }
    .org-name { font-size: 20px; font-weight: 700; color: #1e40af; letter-spacing: -0.3px; }
    .org-slug  { font-size: 10px; color: #6b7280; margin-top: 2px; text-transform: uppercase; letter-spacing: 1px; }
    .doc-title { text-align: right; }
    .doc-type  { font-size: 16px; font-weight: 700; color: #1e40af; text-transform: uppercase; letter-spacing: 0.5px; }
    .doc-ref   { font-size: 13px; font-weight: 600; color: #374151; margin-top: 3px; font-family: monospace; }
    .doc-date  { font-size: 10px; color: #6b7280; margin-top: 4px; }

    /* ── Statut badge ── */
    .badge { display: inline-block; padding: 2px 10px; border-radius: 99px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; margin-top: 6px; }
    .badge-blue   { background: #dbeafe; color: #1d4ed8; }
    .badge-green  { background: #d1fae5; color: #065f46; }
    .badge-zinc   { background: #f4f4f5; color: #52525b; }

    /* ── Info blocks ── */
    .info-row { display: flex; gap: 16px; margin-bottom: 22px; }
    .info-block { flex: 1; border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 14px; }
    .info-block-title { font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #9ca3af; margin-bottom: 6px; }
    .info-block p { font-size: 11px; color: #374151; margin-top: 2px; }
    .info-block .value { font-weight: 600; color: #111827; }

    /* ── Table ── */
    .table-wrap { margin-bottom: 20px; }
    table { width: 100%; border-collapse: collapse; }
    thead tr { background: #1e40af; }
    thead th { padding: 8px 10px; text-align: left; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.6px; color: #fff; }
    thead th.right { text-align: right; }
    thead th.center { text-align: center; }
    tbody tr:nth-child(even) { background: #f8fafc; }
    tbody tr { border-bottom: 1px solid #f1f5f9; }
    tbody td { padding: 8px 10px; font-size: 11px; color: #374151; }
    tbody td.center { text-align: center; }
    tbody td.right  { text-align: right; }
    tbody td.bold   { font-weight: 600; color: #111827; }
    tfoot tr { background: #eff6ff; border-top: 2px solid #bfdbfe; }
    tfoot td { padding: 10px 10px; font-size: 12px; font-weight: 700; }
    tfoot td.right { text-align: right; color: #1e40af; }

    /* ── Reception bloc ── */
    .reception-block { border: 1px solid #d1fae5; background: #f0fdf4; border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; }
    .reception-block-title { font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #059669; margin-bottom: 6px; }
    .reception-block p { font-size: 11px; color: #065f46; }

    /* ── Annulation ── */
    .annulation-block { border: 1px solid #fecaca; background: #fef2f2; border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; }
    .annulation-block-title { font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #dc2626; margin-bottom: 4px; }
    .annulation-block p { font-size: 11px; color: #991b1b; }

    /* ── Note ── */
    .note-block { border: 1px solid #e5e7eb; border-radius: 8px; padding: 10px 14px; margin-bottom: 16px; background: #fafafa; }
    .note-block-title { font-size: 8px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #9ca3af; margin-bottom: 4px; }
    .note-block p { font-size: 11px; color: #374151; }

    /* ── Footer ── */
    .footer { margin-top: 32px; border-top: 1px solid #e5e7eb; padding-top: 12px; display: flex; justify-content: space-between; align-items: center; }
    .footer-left { font-size: 9px; color: #9ca3af; }
    .footer-right { font-size: 9px; color: #9ca3af; text-align: right; }
</style>
</head>
<body>
<div class="page">

    <!-- ── En-tête ── -->
    <div class="header">
        <div>
            <div class="org-name">{{ $organisation->name }}</div>
            <div class="org-slug">{{ strtoupper($organisation->slug) }}</div>
        </div>
        <div class="doc-title">
            <div class="doc-type">Bon de Commande Achat</div>
            <div class="doc-ref">{{ $commande->reference }}</div>
            <div class="doc-date">Émis le {{ $commande->created_at->format('d/m/Y') }}</div>
            @php
                $statutClass = match($commande->statut->value) {
                    'receptionnee' => 'badge-green',
                    'annulee'      => 'badge-zinc',
                    default        => 'badge-blue',
                };
            @endphp
            <span class="badge {{ $statutClass }}">{{ $commande->statut_label }}</span>
        </div>
    </div>

    <!-- ── Infos ── -->
    <div class="info-row">
        <div class="info-block">
            <div class="info-block-title">Fournisseur</div>
            <p class="value">{{ $commande->prestataire?->nom ?? '—' }}</p>
            @if($commande->prestataire?->phone)
                <p>{{ $commande->prestataire->phone }}</p>
            @endif
        </div>
        <div class="info-block">
            <div class="info-block-title">Émetteur</div>
            <p class="value">{{ $createdBy }}</p>
            <p>{{ $organisation->name }}</p>
        </div>
        <div class="info-block">
            <div class="info-block-title">Montant total</div>
            <p class="value" style="font-size:15px; color:#1e40af;">{{ number_format((float)$commande->total_commande, 0, ',', ' ') }} GNF</p>
        </div>
    </div>

    <!-- ── Note ── -->
    @if($commande->note)
    <div class="note-block">
        <div class="note-block-title">Note / Référence fournisseur</div>
        <p>{{ $commande->note }}</p>
    </div>
    @endif

    <!-- ── Réception ── -->
    @if($commande->isReceptionnee())
    <div class="reception-block">
        <div class="reception-block-title">Commande réceptionnée</div>
        <p>Le stock a été mis à jour lors de la réception.</p>
    </div>
    @endif

    <!-- ── Annulation ── -->
    @if($commande->isAnnulee())
    <div class="annulation-block">
        <div class="annulation-block-title">Commande annulée</div>
        <p>{{ $commande->motif_annulation }}</p>
        @if($commande->annulee_at)
            <p style="margin-top:4px; font-size:10px;">Le {{ $commande->annulee_at->format('d/m/Y à H:i') }}</p>
        @endif
    </div>
    @endif

    <!-- ── Lignes ── -->
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:40%">Produit</th>
                    <th class="center" style="width:12%">Commandé</th>
                    <th class="center" style="width:12%">Reçu</th>
                    <th class="right"  style="width:18%">Prix unit.</th>
                    <th class="right"  style="width:18%">Total</th>
                </tr>
            </thead>
            <tbody>
                @foreach($commande->lignes as $ligne)
                <tr>
                    <td class="bold">{{ $ligne->produit?->nom ?? '—' }}</td>
                    <td class="center">{{ $ligne->qte }}</td>
                    <td class="center">{{ $commande->isReceptionnee() ? $ligne->qte_recue : 0 }}</td>
                    <td class="right">{{ number_format((float)$ligne->prix_achat_snapshot, 0, ',', ' ') }} GNF</td>
                    <td class="right bold">{{ number_format((float)$ligne->total_ligne, 0, ',', ' ') }} GNF</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" style="text-align:right; padding-right:10px;">Total commande</td>
                    <td class="right">{{ number_format((float)$commande->total_commande, 0, ',', ' ') }} GNF</td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- ── Pied de page ── -->
    <div class="footer">
        <div class="footer-left">
            {{ $organisation->name }} — Document généré le {{ now()->format('d/m/Y à H:i') }}
        </div>
        <div class="footer-right">
            {{ $commande->reference }}
        </div>
    </div>

</div>
</body>
</html>
