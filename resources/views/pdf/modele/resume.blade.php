<div class="resume">
    <table class="pied-resume"><tbody><tr>
        <td style="width: 50%; padding-right: 28px;">
            <div class="notes-titre">NOTES</div>
            @if($document->notes)<div class="coupe">{!! nl2br(e($document->notes)) !!}</div>@endif
        </td>
        <td style="width: 50%;">
            <table class="totaux">
                @foreach($document->totaux as $total)
                    <tr><td class="cle">{{ $total['cle'] }}</td><td class="val">{{ $total['valeur'] }}</td></tr>
                @endforeach
            </table>
        </td>
    </tr></tbody></table>
</div>

@if($document->encadre)
    <div class="encadre">
        <div class="encadre-titre ton-{{ $document->encadre['ton'] }}">{{ $document->encadre['titre'] }}</div>
        @foreach($document->encadre['lignes'] as $ligne)
            <p class="coupe">{{ $ligne }}</p>
        @endforeach
    </div>
@endif
