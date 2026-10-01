{{-- Aktivitätsübersicht der Startseite: eine Kachel je Tag der letzten 52 Wochen, rechts die Zahlen.
     Gezählt wird in aktivitaet/Aktivitaet.php; die Serie steht klein oben rechts im Kasten.
     Andere Personen sieht niemand. --}}
@php
    $a = \FundusAktivitaet\Aktivitaet::fuer(user()->id);
    ['beginn' => $beginn, 'heute' => $heute, 'proTag' => $proTag] = $a;

    $stufe = fn (int $n) => match (true) { $n === 0 => 0, $n <= 2 => 1, $n <= 5 => 2, $n <= 9 => 3, default => 4 };
    $monate = ['Jan', 'Feb', 'Mär', 'Apr', 'Mai', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dez'];
    $zahlen = [
        [$a['angelegt'], 'Artikel angelegt'],
        [$a['bearbeitet'], $a['bearbeitet'] === 1 ? 'Bearbeitung' : 'Bearbeitungen'],
        [$a['gelesen'], 'Artikel gelesen'],
    ];
@endphp

<section class="fundus-aktivitaet" aria-labelledby="fundus-aktivitaet-titel">
    <div class="fundus-aktivitaet-kopf">
        <h2 id="fundus-aktivitaet-titel">Deine Aktivität</h2>
        @if($a['serie'] > 0)
            {{-- Serie in Werktagen: wird mit jedem Tag violetter, ab Aktivitaet::VOLL leuchtet sie. --}}
            <span class="fundus-serie{{ $a['serie'] >= \FundusAktivitaet\Aktivitaet::VOLL ? ' voll' : '' }}" style="--glut: {{ $a['glut'] }}"
                  title="{{ $a['serie'] }} {{ $a['serie'] === 1 ? 'Werktag' : 'Werktage' }} in Folge aktiv (gelesen, angelegt oder bearbeitet). Wochenenden zählen nicht."
                  aria-label="{{ $a['serie'] }} {{ $a['serie'] === 1 ? 'Werktag' : 'Werktage' }} in Folge aktiv">
                <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.07-2.14-.22-4.05 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.15.43-2.29 1-3a2.5 2.5 0 0 0 2.5 2.5Z"/></svg>
                <b>{{ $a['serie'] }}</b>
            </span>
        @endif
    </div>
    <dl class="fundus-aktivitaet-zahlen">
        @foreach($zahlen as [$zahl, $wort])
            <div><dd>{{ $zahl }}</dd><dt>{{ $wort }}</dt></div>
        @endforeach
    </dl>
    {{-- Wochen als Spalten; auf schmalen Bildschirmen blendet wiki.css ältere Wochen aus, statt zu scrollen. --}}
    <div class="fundus-raster" role="img" aria-label="{{ count($proTag) }} aktive Tage in den letzten 12 Monaten">
        @for($w = 0; $w < 52; $w++)
            @php($wochenStart = $beginn->copy()->addWeeks($w))
            <div class="fundus-raster-woche">
                <span class="fundus-raster-monat" aria-hidden="true">{{ $wochenStart->day <= 7 ? $monate[$wochenStart->month - 1] : '' }}</span>
                @for($d = 0; $d < 7; $d++)
                    @php($tag = $wochenStart->copy()->addDays($d))
                    @if($tag->gt($heute))
                        <span class="fundus-kachel leer"></span>
                    @else
                        @php(['geschrieben' => $g, 'gelesen' => $l] = $proTag[$tag->toDateString()] ?? ['geschrieben' => 0, 'gelesen' => 0])
                        <span class="fundus-kachel s{{ $stufe($g + $l) }}"
                              title="{{ $tag->format('d.m.Y') }}: {{ $g + $l === 0 ? 'nichts' : collect([$g ? $g . ' ' . ($g === 1 ? 'Änderung' : 'Änderungen') : null, $l ? $l . ' gelesen' : null])->filter()->join(', ') }}"></span>
                    @endif
                @endfor
            </div>
        @endfor
    </div>
    <div class="fundus-legende" aria-hidden="true">
        Weniger
        @for($s = 0; $s <= 4; $s++)<span class="fundus-kachel s{{ $s }}"></span>@endfor
        Mehr
    </div>
</section>
