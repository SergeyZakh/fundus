{{-- Nach der Kopfleiste: Suchdialog für Strg+K (Mac: Cmd+K). Die Treffer kommen von
     BookStacks Suchergebnisseite (/search) und beachten dadurch die Rechte des Lesers. --}}
@if(user()->hasAppAccess())
    <dialog class="fundus-suche" data-fundus-suche aria-label="Wiki durchsuchen">
        <form method="GET" action="{{ url('/search') }}" class="fundus-suche-feld" role="search">
            <svg viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" name="term" autocomplete="off" placeholder="Artikel, Themen und Abschnitte durchsuchen"
                   aria-controls="fundus-suche-treffer" aria-autocomplete="list">
            <kbd>Esc</kbd>
        </form>
        <div id="fundus-suche-treffer" class="fundus-suche-treffer" role="listbox" aria-live="polite"></div>
        <div class="fundus-suche-fuss">
            <span><kbd>↑</kbd><kbd>↓</kbd> auswählen</span>
            <span><kbd>Enter</kbd> öffnen</span>
            <span><kbd>Strg</kbd><kbd>K</kbd> öffnen und schließen</span>
        </div>
    </dialog>
@endif
