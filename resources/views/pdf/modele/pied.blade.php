<div class="pied-page">
    <table>
        <tbody><tr>
            <td class="coupe" style="width: 82%;">{{ $document->mention ?? $document->organisation->nom.' — '.$document->reference }}@if($document->infoPied) — {{ $document->infoPied }}@endif</td>
            <td class="pagination" style="width: 18%;">&nbsp;</td>
        </tr></tbody>
    </table>
</div>
