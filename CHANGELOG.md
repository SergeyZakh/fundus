# Änderungen

Format nach [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), Versionen nach [Semantic Versioning](https://semver.org/lang/de/).

## Unveröffentlicht

## [0.1.0] – 2026-10-01

Erste öffentliche Version, als Vorabversion gekennzeichnet.

### Neu

- **Theme `fundus` für BookStack 26.05.5.** Die Startseite zeigt die Bereiche mit ihren Themen,
  die eigene Aktivität und Links zu den Werkzeugen der Firma. Artikel bekommen einen Kopf mit Stand
  und Lesezeit, dazu gibt es einen Zen-Modus und die Schnellsuche mit Strg + K. Die Oberfläche ist
  deutsch und spricht mit Du, und sie nennt Bereich, Thema, Abschnitt und Artikel statt Regal, Buch,
  Kapitel und Seite.

- **KI-Chat „Frag Fundus“** über Ollama. Er antwortet nur aus Artikeln, die die fragende Person
  lesen darf, nennt die Quellen und springt auf Klick zur Fundstelle im Artikel. Ollama läuft als
  Dienst im Stapel und lädt seine Modelle beim ersten Start selbst, ein vorhandener Ollama-Server
  geht ebenso.

- **Texterkennung für PDFs und Bilder** (Dienst `ocr`). Ihr Text wird durchsuchbar und steht der
  KI zur Verfügung. Nimmt man ein Bild aus dem Artikel, verschwindet auch sein Text.

- **Rückmeldungen, Prüffristen und Hinweise.** Unter jedem Artikel lässt sich melden, was veraltet
  oder falsch ist. Wer den Artikel verantwortet, sieht das am Fundus-Knopf, ebenso fällige Prüfungen.

- **Nächtliche Sicherung** mit verschlüsselter Kopie außer Haus per rclone und Wiederherstellung per
  Skript. Scheitert ein Schritt, wird die Sicherung verworfen und den Admins gemeldet.

- **Anmeldung über OIDC** mit mitgeliefertem Keycloak-Realm. Die Rollen folgen den Gruppen des
  Anmeldedienstes, Berufstitel pflegen Admins im Wiki.

- **Einrichtung per Skript.** `einrichten.py` legt Rollen, Bereiche, Vorlagen und das Handbuch für
  Mitarbeitende an, mit `--beispiele` auch drei Beispielartikel und zwei Beispielkonten.
  `env-anlegen.py` erzeugt die `.env` mit Zufallswerten.

- **Betrieb hinter einem Reverse Proxy.** Nach außen offen sind nur Wiki, draw.io und auf Wunsch
  Keycloak, als Vorgabe nur auf `127.0.0.1`. E-Mails gehen über den SMTP-Server der Firma
  (`MAIL_*`). Alle Dienste laufen mit `no-new-privileges`.

- **Anleitungen.** `docs/START.md` führt ohne technische Vorkenntnisse bis zum eingerichteten Wiki
  unter Windows, `docs/ENTWICKLUNG.md` beschreibt Aufbau, Betrieb, Update und Tests.

- **Prüfungen** in GitHub Actions, Dependabot für Images und Actions, dazu Theme-Tests, ein
  Rauchtest für die Oberfläche und ein Test der Anmeldung mit echtem Keycloak.

[0.1.0]: https://github.com/SergeyZakh/fundus/releases/tag/v0.1.0
