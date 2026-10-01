{{-- Rechte Leiste eines Artikels, unter „Details“: Stand der regelmäßigen Prüfung (pruefung/Pruefung.php).
     Nur für Personen, die den Artikel bearbeiten dürfen; sie können ihn als geprüft markieren. --}}
@if(isset($page) && $page instanceof \BookStack\Entities\Models\Page && !$page->draft && !$page->template && userCan(\BookStack\Permissions\Permission::PageUpdate, $page))
    @php
        $pruefung = \FundusPruefung\Pruefung::stand($page);
        $zone = config('app.display_timezone');
        $quellen = ['artikel' => 'Schlagwort am Artikel', 'thema' => 'Schlagwort am Thema', 'vorgabe' => 'Vorgabe'];
        $besitzer = $page->ownedBy;
    @endphp
    <section class="fundus-pruefung {{ $pruefung['lage'] }}" data-fundus-pruefung aria-labelledby="fundus-pruefung-titel">
        <h5 id="fundus-pruefung-titel">Prüfung</h5>
        <dl>
            <div>
                <dt>Verantwortlich</dt>
                <dd>
                    @if($besitzer)<a href="{{ $besitzer->getProfileUrl() }}">{{ $besitzer->name }}</a>@else niemand @endif
                </dd>
            </div>
            <div>
                <dt>Intervall</dt>
                <dd title="{{ $quellen[$pruefung['quelle']] }} „{{ \FundusPruefung\Pruefung::SCHLAGWORT }}“">
                    {{ $pruefung['monate'] === 0 ? 'keine Prüfung' : $pruefung['monate'] . ' ' . ($pruefung['monate'] === 1 ? 'Monat' : 'Monate') }}
                    <span class="quelle">{{ $quellen[$pruefung['quelle']] }}</span>
                </dd>
            </div>
            <div>
                <dt>Zuletzt geprüft</dt>
                <dd data-zuletzt>
                    @if($pruefung['zuletzt'])
                        {{ \Carbon\Carbon::parse($pruefung['zuletzt']->created_at)->setTimezone($zone)->format('d.m.Y') }}
                        @if($pruefung['zuletzt']->slug) von <a href="{{ url('/user/' . $pruefung['zuletzt']->slug) }}">{{ $pruefung['zuletzt']->name }}</a>@endif
                    @else
                        noch nie
                    @endif
                </dd>
            </div>
            @if($pruefung['faellig_am'])
                <div>
                    <dt>{{ $pruefung['lage'] === 'faellig' ? 'Fällig seit' : 'Nächste Prüfung' }}</dt>
                    <dd data-faellig>{{ $pruefung['faellig_am']->setTimezone($zone)->format('d.m.Y') }}</dd>
                </div>
            @endif
        </dl>
        @if($pruefung['monate'] > 0)
            <button type="button" class="fundus-pruefung-knopf" data-pruefen="{{ url('/fundus/pruefung/' . $page->id) }}">
                <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.5 4.5 4.5L19 7.5"/></svg>
                Als geprüft markieren
            </button>
            <p class="hinweis" data-geprueft hidden role="status">Danke! Nächste Prüfung am <span></span>.</p>
        @endif
    </section>
@endif
