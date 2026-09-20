# Erste Schritte

Diese Anleitung richtet Fundus auf einem Windows-Rechner ein, ohne dass du dich mit Servern oder
Programmierung auskennen musst, zum Ausprobieren auf dem eigenen Rechner. Am Ende läuft das Wiki
mit Beispielinhalten, Handbuch und KI-Chat.

Für den Betrieb in einer Firma mit eigener Adresse, https und Anmeldung über Firmenkonten ist die
IT zuständig; die Anleitung dafür steht in der [Entwicklerdoku](ENTWICKLUNG.md), Kapitel
„Betrieb“. Wie man das Wiki *benutzt*, erklärt das Handbuch „So funktioniert das Wiki“, das
Schritt 9 ins Wiki einspielt.

## Das brauchst du

| Was | Wo bekommst du es |
| --- | --- |
| Einen Rechner mit Windows 10 oder 11 (64 Bit) | – |
| Mindestens 16 GB Arbeitsspeicher und 30 GB freien Platz | Einstellungen → System → Info bzw. Speicher |
| Aktivierte Virtualisierung | ist bei den meisten Rechnern an; sonst im BIOS/UEFI, meist Aufgabe der IT |
| Docker Desktop | <https://www.docker.com/products/docker-desktop/> |
| Python 3.10 oder neuer | <https://www.python.org/downloads/> |
| Das Fundus-Projekt als ZIP | [GitHub-Seite](https://github.com/SergeyZakh/fundus) → grüner Knopf **Code** → **Download ZIP** |
| Eine Internetverbindung beim ersten Start | Docker lädt Programme und KI-Modelle; auf der Platte belegen sie danach rund 17 GB |

Docker Desktop ist für Privatleute, Ausbildung und kleine Firmen kostenlos; größere Firmen brauchen
eine Lizenz. Die genauen Grenzen stehen auf der Docker-Seite. Unter macOS und Linux geht es genauso;
dort statt PowerShell das Terminal nehmen.

## Schritt für Schritt

### 1. Docker Desktop installieren

Von der Docker-Seite *Download for Windows* laden und das Installationsprogramm ausführen. Die Vorgabe
„Use WSL 2“ angehakt lassen. Danach den Rechner neu starten, Docker Desktop aus dem Startmenü öffnen
und die Bedingungen bestätigen. Eine Anmeldung bei Docker ist nicht nötig, *Skip* genügt. Warten,
bis unten links „Engine running“ steht.

### 2. Python installieren

Von python.org die aktuelle Version für Windows laden und ausführen. **Wichtig:** Im ersten Fenster
unten den Haken bei **„Add python.exe to PATH“** setzen, dann *Install Now*.

### 3. Projekt entpacken

Die ZIP-Datei von GitHub mit Rechtsklick → *Alle extrahieren* entpacken, zum Beispiel nach
`C:\Fundus`. Darin liegt ein Ordner `fundus-main`.

### 4. PowerShell im Ordner öffnen

Den Ordner `fundus-main` im Explorer öffnen, oben in die Adressleiste klicken, `powershell` tippen
und Enter drücken. Es öffnet sich ein Fenster, das schon im richtigen Ordner steht. Alle weiteren
Befehle tippst oder kopierst du in dieses Fenster.

### 5. Einstellungen anlegen

```powershell
python skripte\env-anlegen.py --lokal
```

Das legt die Datei `.env` mit zufälligen Passwörtern und Schlüsseln an. Die Meldung nennt zwei
Werte, `APP_KEY` und `SICHERUNG_KOPIE_SCHLUESSEL`: Die Datei `.env` gut aufheben, ohne sie lässt
sich eine Sicherung nicht zurückspielen.

### 6. Starten

```powershell
docker compose -p fundus -f docker-compose.yml -f docker-compose.lokal.yml up -d --build
```

Beim ersten Mal lädt Docker mehrere Gigabyte; das dauert je nach Leitung 15 bis 45 Minuten. Die
Befehlszeile kommt zurück, sobald alles gestartet ist; die KI-Modelle laden danach im Hintergrund
weiter.

In Docker Desktop unter *Containers* steht nun `fundus` mit mehreren Diensten. Alle sind grün
(*Running*), nur `ollama-modelle` wechselt auf *Exited*, sobald die KI-Modelle geladen sind. Das ist
richtig so.

### 7. Anmelden und Passwort ändern

Im Browser <http://localhost:6875> öffnen. Beim ersten Start dauert es ein bis zwei Minuten, bis
die Anmeldeseite erscheint. Anmelden mit

- E-Mail: `admin@admin.com`
- Passwort: `password`

Sofort ändern: <http://localhost:6875/settings/users> öffnen, **Admin** anklicken, eigene E-Mail
und ein neues Passwort eintragen, **Speichern**.

### 8. Zugangsschlüssel für die Einrichtung

Auf derselben Seite (**Admin** unter <http://localhost:6875/settings/users>) weiter unten im
Abschnitt **API-Token** auf **Token erstellen**. Als Name „Einrichtung“ eintragen, speichern.
Angezeigt werden **Token ID** und **Token Kennwort**. Beide jetzt kopieren; das Kennwort wird
nur dieses eine Mal angezeigt.

### 9. Wiki einrichten

Im PowerShell-Fenster, die beiden Werte aus Schritt 8 zwischen die Anführungszeichen einsetzen:

```powershell
$env:BOOKSTACK_URL = "http://localhost:6875"
$env:BOOKSTACK_TOKEN_ID = "Token ID hier einsetzen"
$env:BOOKSTACK_TOKEN_SECRET = "Token Kennwort hier einsetzen"
python skripte\einrichten.py --beispiele
```

Das legt Rollen, Bereiche, Themen, Vorlagen, das Handbuch und drei Beispielartikel an. **Rechne
mit einer guten Viertelstunde**, in der nichts auf dem Bildschirm passiert: Das Skript legt jeden
Artikel einzeln an, und die KI nimmt ihn gleich in ihren Index auf. Solange kein Fehler kommt,
läuft es.

Am Ende bittet das Skript um einen Handgriff, weil sich das Vorlagen-Häkchen nicht automatisch
setzen lässt:

1. Im Wiki das Thema **Vorlagen** öffnen.
2. Jeden der genannten Artikel öffnen → **Bearbeiten** → rechts in der Seitenleiste **Vorlagen** →
   Haken bei **Artikel ist eine Vorlage** → **Artikel speichern**.
3. Den letzten Befehl noch einmal ausführen (Pfeil-nach-oben-Taste, Enter). Jetzt meldet es
   „Fertig.“

### 10. KI-Chat einschalten

In Docker Desktop prüfen, dass `ollama-modelle` auf *Exited* steht. Dann einmal den Suchindex der
KI über alle Artikel bauen:

```powershell
docker exec -u abc -w /app/www fundus-wiki-1 php artisan fundus:ki-index
```

Danach unten rechts im Wiki auf **Frag Fundus** klicken und etwas fragen, zum Beispiel „Wie richte
ich ein freigegebenes Postfach ein?“. Neue und geänderte Artikel nimmt die KI ab jetzt von selbst
auf.

### 11. Aufräumen

- Den API-Token aus Schritt 8 wieder löschen (gleiche Seite, Token öffnen → **Lösche Token**).
- Unter **Einstellungen → Personalisierung** den Namen des Wikis eintragen.
- Kollegen legst du unter <http://localhost:6875/settings/users> → **Benutzer hinzufügen** an und
  gibst ihnen eine Rolle (Mitarbeiter, Azubi, Redaktion …). Das Handbuch erklärt die Rollen im
  Kapitel „Rechte und Freigaben“.

Andere Rechner im Netz erreichen das Wiki in dieser Einrichtung **nicht**: Sie ist bewusst nur auf
diesem Rechner offen. Für ein Team mit eigener Adresse und https bitte die IT ansprechen
(Entwicklerdoku, Kapitel „Betrieb“).

## Später

| Aufgabe | So geht's |
| --- | --- |
| Anhalten | Docker Desktop → *Containers* → beim Eintrag `fundus` auf das Stopp-Symbol |
| Wieder starten | dort auf das Start-Symbol; startet Docker Desktop mit Windows, läuft alles von selbst |
| Sicherung | läuft jede Nacht um 02:30 von selbst, solange der Rechner an ist; 14 Tage werden aufgehoben |
| Neue Version | neue ZIP von GitHub laden, alles außer der Datei `.env` im Ordner ersetzen, dann Schritt 4 und 6 wiederholen. Die Inhalte des Wikis bleiben erhalten. |
| Alles entfernen | im Ordner: `docker compose -p fundus down -v`. **Achtung:** Löscht auch alle Inhalte des Wikis. |

## Wenn etwas nicht klappt

| Was du siehst | Was hilft |
| --- | --- |
| `python` wird nicht erkannt | Bei der Installation fehlte der Haken „Add python.exe to PATH“. Python-Installer erneut starten → *Modify* bzw. neu installieren mit Haken, PowerShell neu öffnen. |
| `docker` wird nicht erkannt | Docker Desktop ist nicht gestartet oder PowerShell war schon vor der Installation offen: Docker Desktop starten, PowerShell neu öffnen. |
| Docker Desktop meldet ein Problem mit WSL | PowerShell als Administrator: `wsl --update`, danach neu starten. |
| Docker Desktop meldet, Virtualisierung sei ausgeschaltet | Sie muss im BIOS/UEFI eingeschaltet werden. Das macht am besten die IT. |
| „.env gibt es schon“ | Schritt 5 wurde schon erledigt. Weiter mit Schritt 6. |
| <http://localhost:6875> lädt nicht | Nach dem ersten Start ein bis zwei Minuten warten. In Docker Desktop prüfen, ob `wiki` grün ist. |
| „port is already allocated“ | Ein anderes Programm nutzt Port 6875 oder 6876. Das Programm beenden oder die IT fragen. |
| `einrichten.py` meldet „API-Fehler 401“ oder „403“ | Token ID oder Kennwort falsch kopiert. Neuen Token anlegen (Schritt 8) und Schritt 9 wiederholen. |
| Der KI-Chat meldet einen Fehler | Die Modelle laden noch: warten, bis `ollama-modelle` auf *Exited* steht, dann Schritt 10. |
| Die KI antwortet sehr langsam | Ohne Grafikkarte rechnet sie auf dem Prozessor; eine Antwort kann eine halbe Minute dauern. |

Weitere Fragen: [Issue anlegen](https://github.com/SergeyZakh/fundus/issues).
