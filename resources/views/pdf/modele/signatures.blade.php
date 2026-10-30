@if($document->signatures)
<table class="signatures">
    <tbody><tr>
        @foreach($document->signatures as $signature)
            <td class="signature{{ $loop->first ? '' : ' suivante' }}" style="width: {{ round(100 / count($document->signatures), 2) }}%;">
                <div class="signature-titre">{{ $signature['titre'] }}</div>
                <div class="signature-zone">
                    @foreach($signature['lignes'] as $ligne)
                        <p class="coupe">{{ $ligne }}</p>
                    @endforeach
                </div>
            </td>
        @endforeach
    </tr></tbody>
</table>
@endif
