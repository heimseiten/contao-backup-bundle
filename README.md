<p align="center"><img src="logo.svg" alt="Contao Backup Bundle" width="112" height="112"></p>

# Contao Backup Bundle

Fügt im Backend unter **System** den Punkt **„Sicherung"** hinzu, mit drei Downloads
(unter jedem Button steht, was genau enthalten ist):

- **Datenbank und Dateien herunterladen** – ein ZIP mit dem Datenbank-Backup
  (unter `database/`) **und** allen relevanten Dateien/Ordnern.
- **Nur Dateien herunterladen** – die Dateien/Ordner als ZIP.
- **Nur Datenbank herunterladen** – das Datenbank-Backup (Contaos eigenes Backup,
  gzip-komprimiertes SQL; wird zusätzlich in `var/backups` abgelegt).

## Enthaltene Pfade (Dateien- und Voll-Backup)

| Pfad | Inhalt |
| --- | --- |
| `composer.json`, `composer.lock` | Abhängigkeiten / exakte Versionen |
| `config/` | App-Konfiguration (Symfony/Bundles) |
| `contao/` | DCA, Templates, Sprachen … |
| `src/` | Eigener Anwendungscode (App-Namespace) |
| `templates/` | Globale Templates |
| `translations/` | Eigene Symfony-Übersetzungen |
| `migrations/` | Eigene Doctrine-Migrationen |
| `system/config/localconfig.php` | Systemeinstellungen (Legacy) |
| `files/` | Medien / Dateiverwaltung – inkl. **aller** Erweiterungs-Uploads (Isotope-Produktbilder, MetaModels-Dateifelder …) |

Nicht vorhandene Pfade werden übersprungen.

**Warum reicht das?** Erweiterungen wie **Isotope** oder **MetaModels** legen ihre
Daten in der **Datenbank** ab (im DB-Backup enthalten) und ihre hochgeladenen Dateien
unter `files/` (ebenfalls enthalten). Ihr **Code** steckt in `vendor/` und wird über
`composer.json`/`composer.lock` jederzeit per `composer install` wiederhergestellt –
ein Backup von `vendor/` ist daher unnötig. Genauso bewusst ausgelassen: `var/`
(Cache/Logs, regeneriert sich) und `public/` (Einstiegspunkt + per
`contao:install`/`assets:install` neu erzeugte Assets).

## Sicherheit

Ein Backup ist per Definition eine vollständige Kopie aller sensiblen Daten –
behandle die heruntergeladene Datei entsprechend.

- **Nur Administratoren.** Serverseitig per `isAdmin` geprüft; Nicht-Admins erhalten 403.
- **Hinter der Backend-Firewall** – nur authentifizierte Backend-Sitzungen.
- **CSRF-geschützt:** Die Downloads laufen über POST-Formulare mit Contao-Request-Token;
  ein ungültiges/fehlendes Token wird vom Core mit 403 abgewiesen.
- **Feste Pfade**, keine Benutzereingabe → kein Path-Traversal. **Symlinks werden nicht
  gefolgt** (kein Ausbrechen aus dem Projektverzeichnis).
- **Kein Temp-Artefakt:** Das ZIP wird direkt zum Browser gestreamt und nie auf die Platte
  geschrieben. Das DB-Backup landet (durch Contao) zusätzlich in `var/backups` – außerhalb
  des Web-Roots, per URL nicht erreichbar, von Contaos Retention bereinigt.
- **Aber:** Die heruntergeladene Datei enthält u. a. Passwort-Hashes, 2FA-Geheimnisse,
  Mitglieder-/Personendaten und `localconfig.php`. Sie ist **unverschlüsselt** – sicher
  (idealerweise verschlüsselt) aufbewahren, nur über HTTPS laden, nicht ungeschützt in
  Cloud-Ordnern ablegen.

## Große Installationen & PHP-Limits

Das ZIP wird mit [ZipStream](https://github.com/maennchen/ZipStream-PHP) **direkt zum
Browser gestreamt** – kein Temp-ZIP auf der Platte, kein Warten auf einen kompletten Build.
Damit sind die üblichen Stolperfallen bei großen `files/`-Ordnern entschärft:

- **Arbeitsspeicher (`memory_limit`): unkritisch.** Dateien werden von der Platte direkt in
  den Stream geschoben – es liegt nie eine ganze Datei oder das ganze ZIP im PHP-Speicher.
  Auch Multi-GB-Backups laufen mit kleinem `memory_limit`.
- **Kein temporärer Speicherplatz nötig.** Es wird nichts zwischengespeichert; der Download
  beginnt sofort.
- **Read-/Request-Timeouts:** Weil ab der ersten Sekunde Bytes fließen, greift der
  Webserver-Read-Timeout (nginx `fastcgi_read_timeout`, Apache `FcgidIOTimeout`) nicht. Das
  Bundle hebt zusätzlich `set_time_limit(0)` auf und packt **ohne Re-Kompression** (Medien
  sind ohnehin komprimiert). Ein hartes Gesamtlimit wie PHP-FPM `request_terminate_timeout`
  bleibt ein serverseitiges Thema – ist aber selten so knapp gesetzt.
- **ZIP > 4 GB:** automatisch über ZIP64.

**Fortschrittsbalken direkt auf der Seite:** In **Chrome/Edge** wird der Download per
JavaScript über die [File System Access API](https://developer.mozilla.org/docs/Web/API/Window/showSaveFilePicker)
direkt auf die Platte gestreamt – mit einem Fortschrittsbalken samt Prozent/MB **im
Backup-Modul selbst**. Die Prozentangabe ist exakt, weil die ZIP-Größe vorab in einem
schnellen Durchlauf ermittelt (nur ein `stat` pro Datei, kein Inhalt – bei z. B. ~1.750
Dateien unter einer halben Sekunde) und als `Content-Length` gesendet wird. **Firefox/Safari**
fallen automatisch auf den normalen Browser-Download zurück (Fortschritt dann im
Download-Manager des Browsers). Einzige Konsequenz: Ändert eine Datei *während* des (evtl.
langen) Downloads ihre Größe, kann das ZIP unvollständig sein – dann einfach erneut laden.

**Abgestufte Fallbacks (Progressive Enhancement):** Die Buttons sind echte
`<form method="post">` – **ohne JavaScript** lädt ein Klick das Backup ganz normal herunter
(der Server streamt, der Browser zeigt seinen eigenen Fortschritt). Bricht der
JavaScript-Download ab (Speicherdialog nicht verfügbar, Platte voll, Netzfehler), wird die
halbe Datei verworfen und **automatisch auf den normalen Browser-Download umgeschaltet**.
Nur ein echter Serverfehler (z. B. abgelaufenes Request-Token) wird als Fehler gemeldet.

## Installation

```bash
composer require heimseiten/contao-backup-bundle
```

Anschließend erscheint im Backend unter **System** der Punkt **„Sicherung"**
(für Administratoren).
