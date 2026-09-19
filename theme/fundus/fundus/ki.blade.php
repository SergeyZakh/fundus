{{-- Chat der KI „Fundus“ (Logik in public/wiki.js, Antworten von theme/fundus/ki/Ki.php).
     Nur sichtbar, wenn OLLAMA_URL gesetzt ist und die Person das Wiki nutzen darf.
     Als Seitenleiste rechts (Breite am linken Rand ziehbar), im Vollbild: links frühere Gespräche,
     Mitte der Chat, rechts die Quellen des Gesprächs. --}}
@if(env('OLLAMA_URL') && user()->hasAppAccess())
    {{-- Runder Knopf mit dem „F“ aus dem App-Icon; der Name fährt beim Überfahren aus. --}}
    <button type="button" class="fundus-ki-knopf" data-fundus-ki-knopf aria-expanded="false" aria-controls="fundus-ki" aria-label="Frag Fundus">
        <span class="fundus-monogramm" aria-hidden="true">F</span>
        <span class="text">Frag Fundus</span>
    </button>

    <section id="fundus-ki" class="fundus-ki" data-fundus-ki hidden aria-label="Fundus"
             data-nutzer="{{ user()->id }}"
             data-bibliotheken="{{ url('/fundus/ki/bibliotheken.js') }}?v={{ \FundusKi\Ki::bibliothekenVersion() }}">
        <div class="fundus-ki-griff" data-fundus-ki-griff role="separator" aria-orientation="vertical"
             aria-label="Breite des Chats ändern" tabindex="0"></div>
        <div class="fundus-ki-kopf">
            <div class="fundus-ki-titel">
                <span class="fundus-monogramm" aria-hidden="true">F</span>
                <div>
                    <strong>Fundus</strong>
                    <small>Antworten aus dem Wiki</small>
                </div>
            </div>
            <button type="button" class="fundus-ki-kopfknopf" data-fundus-ki-neu title="Neues Gespräch" aria-label="Neues Gespräch">
                <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.4 3.6a2.1 2.1 0 1 1 3 3L7 19l-4 1 1-4z"/></svg>
            </button>
            <button type="button" class="fundus-ki-kopfknopf" data-fundus-ki-gross title="Vollbild" aria-label="Vollbild" aria-pressed="false">
                <svg class="auf" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/></svg>
                <svg class="zu" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M4 14h6v6M20 10h-6V4M14 10l7-7M3 21l7-7"/></svg>
            </button>
            <button type="button" class="fundus-ki-kopfknopf" data-fundus-ki-zu title="Schließen" aria-label="Schließen">
                <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>

        <div class="fundus-ki-koerper">
            <aside class="fundus-ki-seite fundus-ki-archiv" aria-label="Frühere Gespräche">
                <button type="button" class="fundus-ki-neu-gross" data-fundus-ki-neu>
                    <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
                    Neues Gespräch
                </button>
                <h3>Frühere Gespräche</h3>
                <ul class="fundus-ki-archiv-liste" data-fundus-ki-archiv></ul>
                <p class="fundus-ki-seite-hinweis">Gespräche bleiben nur in diesem Browser gespeichert.</p>
            </aside>

            <div class="fundus-ki-mitte">
                <div class="fundus-ki-verlauf" data-fundus-ki-verlauf aria-live="polite">
                    <div class="fundus-ki-leer">
                        <span class="fundus-monogramm" aria-hidden="true">F</span>
                        <h2>Hallo {{ explode(' ', trim(user()->name))[0] }}, was möchtest du wissen?</h2>
                        <p>Ich kenne alles, was im Wiki steht, und zeige dir, wo ich es gefunden habe.</p>
                        <div class="fundus-ki-vorschlaege">
                            <button type="button" data-fundus-ki-beispiel>Wie richte ich ein freigegebenes Postfach ein?</button>
                            <button type="button" data-fundus-ki-beispiel>Was tun, wenn beim Kunden das Internet ausfällt?</button>
                            <button type="button" data-fundus-ki-beispiel>Wie gebe ich einem Azubi Zugriff auf ein Kundenthema?</button>
                        </div>
                    </div>
                </div>

                <form class="fundus-ki-eingabe" data-fundus-ki-form>
                    <div class="fundus-ki-feld">
                        <textarea rows="1" maxlength="1000" placeholder="Frag Fundus …" aria-label="Deine Frage" data-fundus-ki-feld></textarea>
                        <button type="submit" aria-label="Senden" data-fundus-ki-senden>
                            <svg class="senden" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M6 11l6-6 6 6"/></svg>
                            <svg class="stopp" viewBox="0 0 24 24" aria-hidden="true"><rect x="7" y="7" width="10" height="10" rx="2" fill="currentColor"/></svg>
                        </button>
                    </div>
                    <p class="fundus-ki-fuss">Läuft auf unserem Server. Antworten können Fehler enthalten, prüf die Quelle.</p>
                </form>
            </div>

            <aside class="fundus-ki-seite fundus-ki-fundus" aria-label="Quellen in diesem Gespräch">
                <h3>Quellen in diesem Gespräch</h3>
                <ul class="fundus-ki-fundus-liste" data-fundus-ki-fundus></ul>
                <p class="fundus-ki-seite-hinweis" data-fundus-ki-fundus-leer>Hier sammeln sich die Artikel, aus denen Fundus vorliest.</p>
                <h3>Kürzel</h3>
                <dl class="fundus-ki-kuerzel">
                    <div><dt><kbd>Enter</kbd></dt><dd>Frage senden</dd></div>
                    <div><dt><kbd>Umschalt</kbd> <kbd>Enter</kbd></dt><dd>Neue Zeile</dd></div>
                    <div><dt><kbd>Esc</kbd></dt><dd>Fenster schließen</dd></div>
                </dl>
            </aside>
        </div>
    </section>
@endif
