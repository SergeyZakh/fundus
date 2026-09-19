{{-- Stand und Lesezeit unter dem Seitentitel, wie bei Microsoft Learn.
     page-display wird auch für Revisionen, Exporte und die Startseite genutzt;
     nur die normale Seitenansicht setzt $pageNav, daran erkennen wir sie. --}}
@if(isset($pageNav) && isset($page) && $page instanceof \BookStack\Entities\Models\Page)
    @php
        $geaendert = $page->updated_at->clone()->setTimezone(config('app.display_timezone'));
        // Rund 180 Wörter pro Minute: Fachtexte liest man langsamer als Romane.
        $woerter = count(preg_split('/\s+/u', trim($page->text), -1, PREG_SPLIT_NO_EMPTY));
        $minuten = max(1, (int) round($woerter / 180));
    @endphp
    <div class="fundus-artikelkopf" data-fundus-artikelkopf>
        <span>
            Zuletzt aktualisiert am
            <time datetime="{{ $geaendert->toIso8601String() }}">{{ $geaendert->format('d.m.Y') }}</time>
            @if($page->updatedBy)
                von <a href="{{ $page->updatedBy->getProfileUrl() }}">{{ $page->updatedBy->name }}</a>
            @endif
        </span>
        <span class="fundus-punkt" aria-hidden="true"></span>
        <span>{{ $minuten }} {{ $minuten === 1 ? 'Minute' : 'Minuten' }} Lesezeit</span>
        {{-- Regelmäßige Prüfung (pruefung/Pruefung.php): Leser sehen, ob die Angaben noch geprüft sind. --}}
        @if(!$page->template)
            @php($pruefung = \FundusPruefung\Pruefung::stand($page))
            @if($pruefung['lage'] === 'faellig')
                <span class="fundus-alt" title="Die regelmäßige Prüfung war am {{ $pruefung['faellig_am']->setTimezone(config('app.display_timezone'))->format('d.m.Y') }} fällig">
                    <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                    Prüfung überfällig, Angaben mit Vorsicht nutzen
                </span>
            @elseif($pruefung['zuletzt'])
                <span class="fundus-punkt" aria-hidden="true"></span>
                <span class="fundus-geprueft">
                    <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 4.5 6v5.5c0 4.4 3.1 8.4 7.5 9.5 4.4-1.1 7.5-5.1 7.5-9.5V6z"/><path d="m8.8 12 2.2 2.2 4.2-4.4"/></svg>
                    Geprüft am {{ \Carbon\Carbon::parse($pruefung['zuletzt']->created_at)->setTimezone(config('app.display_timezone'))->format('d.m.Y') }}
                </span>
            @endif
        @endif
    </div>
@endif
