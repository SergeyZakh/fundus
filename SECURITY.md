# Sicherheit

## Lücke melden

Bitte **kein öffentliches Issue** für Sicherheitslücken. Melde sie über
[GitHub Security Advisories](../../security/advisories/new) – das ist ein
privater Kanal zwischen dir und mir.

Ich schaue in der Regel innerhalb einer Woche hinein und melde mich, auch
wenn ich noch keine Lösung habe. Das hier ist ein Freizeitprojekt, keine
Firma mit Bereitschaft – plane bitte mit ein paar Tagen.

Hilfreich in der Meldung: BookStack-Version, welcher Teil betroffen ist und
wie sich die Lücke nachstellen lässt.

## Was betroffen sein kann

| Teil | Betroffen |
| --- | --- |
| `theme/fundus/` | ja – läuft im Wiki mit allen Rechten von BookStack |
| `docker-compose*.yml`, `ocr/`, `sicherung/` | ja – Dienste, Netz, Sicherung |
| `keycloak/` | ja, soweit die Realm-Vorlage betroffen ist |
| `skripte/`, `docs/`, `handbuch/`, `vorlagen/` | nur, wenn daraus ein Fehler im Betrieb folgt |

Lücken in BookStack selbst bitte direkt dort melden:
<https://github.com/BookStackApp/BookStack/security>.

Unterstützt wird jeweils die neueste Version auf `main`.

## Was Fundus selbst schützt

- **Rechte von BookStack gelten überall.** Eigene Abfragen im Theme
  (Startseite, Listen, KI-Suche, Hinweise) laufen über BookStacks
  Sichtbarkeitsprüfung. Die KI antwortet nur aus Artikeln, die die fragende
  Person selbst öffnen darf. Geprüft in `skripte/tests/theme-tests.php`.
- **Eigene Routen** (`/fundus/…`) verlangen eine Anmeldung und nutzen
  BookStacks CSRF-Schutz; Gäste werden abgewiesen, auch wenn in BookStack
  der öffentliche Zugriff eingeschaltet ist. Der KI-Chat nimmt je Person höchstens
  10 Fragen in der Minute an, damit niemand Ollama für alle anderen blockiert.
- **Keine Daten nach außen.** Schrift im Theme, eigenes draw.io, keine
  Gravatar-Bilder, die KI läuft über Ollama im eigenen Stapel. Nur der Dienst
  `ollama-modelle` lädt beim Start Modelle von ollama.com; Fragen und Inhalte
  gehen nicht hinaus.
- **Nur zwei Ports, nur lokal.** Wiki und draw.io sind als Vorgabe nur auf
  `127.0.0.1` erreichbar, also nur für den Reverse Proxy auf demselben Server.
  Datenbank, OCR und Ollama haben keinen Port nach außen. Alle Dienste laufen mit `no-new-privileges`,
  der OCR-Dienst zusätzlich schreibgeschützt, ohne Linux-Capabilities und in einem internen
  Docker-Netz ohne Verbindung nach draußen.
- **Sicherung außer Haus** wird mit `SICHERUNG_KOPIE_SCHLUESSEL` vor dem
  Hochladen verschlüsselt (rclone crypt). Das Protokoll schwärzt
  Zugangsdaten, bevor Admins im Wiki einen Fehler angezeigt bekommen.

## Bekannte Grenzen

- Ohne `SICHERUNG_KOPIE_SCHLUESSEL` liegt die Kopie außer Haus
  unverschlüsselt beim Ziel. Fundus weist Admins darauf hin, erzwingt es
  aber nicht.
- Text aus Anhängen und Bildern (OCR) landet im Suchindex und bei der KI.
  Wer Zugangsdaten als Screenshot hochlädt, macht sie damit durchsuchbar –
  deshalb gilt im Handbuch: keine Zugangsdaten ins Wiki. Nimmt man das Bild
  aus dem Artikel, verschwindet sein Text beim Speichern aus Artikel, Suche
  und KI; die Datei selbst bleibt in der Bildverwaltung, bis jemand sie dort
  löscht.
- Gespräche mit der KI liegen im Browser (`localStorage`) und werden beim
  Abmelden gelöscht, nicht aber, wenn man den Browser einfach schließt.
