{{-- Unter jedem Artikel (vor der Vor/Zurück-Navigation): „War dieser Artikel hilfreich?“ und ein Formular,
     um Veraltetes oder Fehler zu melden. Logik: wiki.js (rueckmeldungen), Speicherung: rueckmeldung/Rueckmeldung.php.
     sibling-navigation gibt es auch bei Abschnitten; nur Artikel ($page mit $commentTree, das setzt nur die
     Artikelansicht) bekommen den Block. --}}
@if(isset($page, $commentTree) && $page instanceof \BookStack\Entities\Models\Page && !$page->draft && user()->hasAppAccess() && !user()->isGuest())
    @php
        $rueckmeldung = \FundusRueckmeldung\Rueckmeldung::fuerSeite($page);
        // Überschriften für „Wo im Artikel?“. Seit BookStack 26.09 berechnet sie nur noch der Seitenleisten-Block;
        // hier genauso aus dem schon gerenderten Inhalt.
        $abschnitte = $pageNav ?? (new \BookStack\Entities\Tools\PageContent($page))->getNavigation($page->html);
    @endphp
    <section class="fundus-rueckmeldung print-hidden" data-fundus-rueckmeldung data-seite="{{ $page->id }}"
             data-gelesen-adresse="{{ url('/fundus/gelesen') }}" data-lesezeit="{{ \FundusAktivitaet\Aktivitaet::LESEZEIT }}"
             data-adresse="{{ url('/fundus/rueckmeldung') }}" aria-label="Rückmeldung zu diesem Artikel">
        <div class="fundus-rueckmeldung-zeile">
            <span class="frage">War dieser Artikel hilfreich?</span>
            <div class="fundus-rueckmeldung-knoepfe" role="group" aria-label="War dieser Artikel hilfreich?">
                <button type="button" data-art="ja" aria-pressed="{{ $rueckmeldung['meine'] === 'ja' ? 'true' : 'false' }}">
                    <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M7 10v11"/><path d="M15 5.9 14 10h5.8a2 2 0 0 1 1.9 2.6l-2.3 7A2 2 0 0 1 17.5 21H4a1 1 0 0 1-1-1v-9a1 1 0 0 1 1-1h2.8a2 2 0 0 0 1.8-1.1L12 2a3.1 3.1 0 0 1 3 3.9z"/></svg>
                    Ja
                </button>
                <button type="button" data-art="nein" aria-pressed="{{ $rueckmeldung['meine'] === 'nein' ? 'true' : 'false' }}">
                    <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M17 14V3"/><path d="M9 18.1 10 14H4.2a2 2 0 0 1-1.9-2.6l2.3-7A2 2 0 0 1 6.5 3H20a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1h-2.8a2 2 0 0 0-1.8 1.1L12 22a3.1 3.1 0 0 1-3-3.9z"/></svg>
                    Nein
                </button>
            </div>
            <span class="danke" data-danke hidden>Danke für deine Rückmeldung.</span>
            <button type="button" class="fundus-rueckmeldung-melden" data-melden aria-expanded="false" aria-controls="fundus-rueckmeldung-formular">
                <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M4 22V4a1 1 0 0 1 .4-.8A6 6 0 0 1 8 2c3 0 5 2 8 2a6 6 0 0 0 3.6-1.2 1 1 0 0 1 1.4.9v10a1 1 0 0 1-.4.8A6 6 0 0 1 17 16c-3 0-5-2-8-2a8 8 0 0 0-5 2"/></svg>
                Veraltet oder falsch? Melden
            </button>
        </div>

        <form id="fundus-rueckmeldung-formular" class="fundus-rueckmeldung-formular" data-formular hidden novalidate>
            <div class="kopf">
                <div>
                    <h3>Hinweis zu diesem Artikel</h3>
                    <p>Die Person, die den Artikel pflegt, sieht deinen Hinweis und kann ihn abhaken.</p>
                </div>
                <button type="button" class="zu" data-abbrechen aria-label="Schließen">
                    <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
                </button>
            </div>

            <fieldset class="gruende">
                <legend>Was ist das Problem?</legend>
                @foreach(\FundusRueckmeldung\Rueckmeldung::GRUENDE as $wert => [$wort, $vorschlag])
                    <label>
                        <input type="radio" name="grund" value="{{ $wert }}" data-vorschlag="{{ $vorschlag }}" @checked($loop->first)>
                        <span>{{ $wort }}</span>
                    </label>
                @endforeach
            </fieldset>

            <label class="feld">
                <span>Wo im Artikel?</span>
                <select name="abschnitt" data-abschnitt>
                    <option value="">Ganzer Artikel</option>
                    @foreach($abschnitte as $eintrag)
                        @continue($eintrag['level'] > 3)
                        <option value="{{ $eintrag['text'] }}">{{ $eintrag['level'] > 2 ? '– ' : '' }}{{ $eintrag['text'] }}</option>
                    @endforeach
                </select>
            </label>

            <label class="feld">
                <span>Beschreibung</span>
                <textarea name="text" maxlength="1000" rows="4" required
                          placeholder="{{ array_values(\FundusRueckmeldung\Rueckmeldung::GRUENDE)[0][1] }}"></textarea>
                <span class="zaehler" data-zaehler aria-live="polite">0 / 1000</span>
            </label>

            <p class="fehler" data-fehler hidden role="alert"></p>

            <div class="fuss">
                <button type="button" class="button outline" data-abbrechen>Abbrechen</button>
                <button type="submit" class="button" data-senden>Hinweis senden</button>
            </div>
        </form>

        <div class="fundus-rueckmeldung-gesendet" data-gesendet hidden role="status">
            <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9.5"/><path d="m8 12.5 2.8 2.8L16.5 9.5"/></svg>
            <div>
                <strong>Danke, dein Hinweis ist angekommen.</strong>
                <span>Er steht jetzt beim Artikel, bis ihn jemand erledigt.</span>
            </div>
        </div>
    </section>
@endif
