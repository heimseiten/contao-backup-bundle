<p align="center"><img src="logo.svg" alt="Contao Backup Bundle" width="112" height="112"></p>

# Contao Backup Bundle

Fügt im Backend unter **System** den Punkt **„Sicherung"** hinzu, mit drei Downloads
(unter jedem Button steht, was genau enthalten ist):

- **Datenbank und Dateien herunterladen** – ein ZIP mit dem Datenbank-Backup
  (unter `database/`) **und** allen relevanten Dateien/Ordnern.
- **Nur Dateien herunterladen** – die Dateien/Ordner als ZIP.
- **Nur Datenbank herunterladen** – das Datenbank-Backup (Contaos eigenes Backup,
  gzip-komprimiertes SQL; wird zusätzlich in `var/backups` abgelegt).

Jede der drei Sicherungen lässt sich wahlweise herunterladen **oder direkt auf dem
Server ablegen** (siehe [Auf dem Server speichern](#auf-dem-server-speichern-statt-herunterladen)) –
auf Wunsch auch [automatisch per Cron](#automatische-voll-backups).

Außerdem können Backups direkt im Backend **wiederhergestellt** werden – aus
`var/backups`, per Upload eines heruntergeladenen Backup-ZIPs oder aus einem Archiv, das
bereits auf dem Server liegt (siehe [Wiederherstellung](#wiederherstellung-restore)).

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

## Auf dem Server speichern statt herunterladen

Unter jedem der drei Buttons liegt ein zweiter: **„Auf dem Server speichern"**. Die
Sicherung wandert dann nach `var/backups`, statt in den Browser zu fließen. Das ist der
Gegenpart zur Auswahl beim Wiederherstellen und lohnt sich vor allem bei großen
Installationen:

- Die Datei lässt sich anschließend in Ruhe **per FTP holen** – der FTP-Client kann eine
  abgebrochene Übertragung fortsetzen, ein Browser-Download nicht immer.
- Sie steht sofort in der Auswahlliste der **Wiederherstellung** (kein Upload nötig).
- Für einen Umzug genügt damit ein FTP-Transfer von Server zu Server.

> **Kein Ersatz für eine Sicherung außer Haus.** Bei einem Ausfall oder Verlust des
> Servers ist eine dort liegende Datei genauso verloren wie die Website. Die Kopie auf
> dem Server ist eine Zwischenstation, kein Backup-Konzept.

Technisch gilt dasselbe wie beim Download: Das Archiv entsteht im selben Streaming-Verfahren
(konstanter Speicherbedarf, kein Temp-ZIP) – nur geht es in eine Datei. Geschrieben wird
zuerst nach `<name>.zip.partial` und erst nach dem letzten Byte umbenannt, sodass ein
halbfertiges Archiv nie als Wiederherstellungs-Quelle auftauchen kann; scheitert das
Schreiben (volle Platte, fehlende Rechte), bleibt nichts zurück. Der freie Plattenplatz
wird vorab geprüft.

Weil das Bauen eines mehrere GB großen Archivs Minuten dauert und eine stumme Anfrage so
lange in den Read-Timeout des Webservers läuft, meldet der Server den Fortschritt
**zeilenweise zurück** – das hält die Verbindung in Bewegung und speist zugleich den
Fortschrittsbalken im Modul. Ohne JavaScript bleibt das Formular ein gewöhnlicher POST,
der am Ende zur Bestätigungsmeldung weiterleitet.

Über den Karten steht außerdem, wie viele Archive derzeit auf dem Server liegen und wie
viel Platz sie belegen – gelöscht werden sie unten im Abschnitt „Wiederherstellung".

## Automatische Voll-Backups

Neben Contaos automatischen **Datenbank**-Backups kann das Bundle regelmäßig ein
**komplettes** Archiv (Datenbank + Dateien) in `var/backups` ablegen, angestoßen vom
Contao-Cron. Einstellbar sind Takt (täglich/wöchentlich/monatlich), Anzahl der
aufbewahrten Archive und ob nur bei Änderungen gesichert wird; ein Knopf
**„Jetzt einmal ausführen"** prüft die Einrichtung ohne Warten auf den Cron.

> **Warum eigene Einstellungen und nicht der Rhythmus der Datenbank-Backups?** Weil
> die beiden nichts miteinander zu tun haben: Ein Dump ist ein Bruchteil eines
> Megabytes, ein Voll-Archiv schnell mehrere hundert MB bis GB. Täglich mit fünf
> Aufbewahrungen – die Datenbank-Voreinstellung – füllt auf geteiltem Hosting die
> Platte mit Kopien von Dateien, die sich nie geändert haben. **Und eine volle Platte
> legt die Website lahm.** Die Voreinstellung hier ist deshalb: wöchentlich, zwei
> Archive, nur bei Änderungen.

**Nur bei Änderungen (empfohlen).** Vor jedem Lauf wird geprüft, ob sich seit dem
letzten Archiv überhaupt etwas geändert hat. Dafür werden nur Datei-*Angaben* gelesen
(keine Inhalte), was auch bei zehntausenden Dateien schnell bleibt, und zwar mehrere
zugleich:

- **Anzahl und Gesamtgröße** – erkennt hinzugefügte und gelöschte Dateien, selbst wenn
  deren Zeitstempel nichts verrät.
- **Summe aller Änderungszeiten (mtime)** – erkennt bearbeitete Inhalte.
- **Summe aller Inode-Zeiten (ctime)** – erkennt Dateien, deren mtime in der
  Vergangenheit liegt. Genau das passiert bei einem **FTP-Upload, der den
  ursprünglichen Zeitstempel beibehält**: Das Foto sieht Jahre alt aus, sein Inode
  wurde aber gerade geschrieben. Eine reine mtime-Prüfung würde solche Uploads
  übersehen.
- **Zusätzlich** gilt alles als geändert, was einen Zeitstempel aus der Sekunde des
  letzten Laufs oder später trägt – im Zweifel also lieber ein Archiv zu viel.

Dass Contao noch keine **Dateisynchronisierung** gemacht hat, spielt dabei keine Rolle:
Gepackt wird direkt vom Dateisystem, nicht über `tl_files`. Per FTP hochgeladene Dateien
sind also in jedem Fall Teil des Backups, synchronisiert oder nicht.

**Aufräumen betrifft ausschließlich die eigenen Archive.** Sie tragen den Namensteil
`auto-backup`; von Hand gespeicherte oder per FTP hochgeladene Archive werden nie
gelöscht.

**Ein echter System-Cron ist nötig.** Der Web-Cron führt Jobs im Seitenaufruf aus, wo
ein minutenlanger Packvorgang am PHP-Zeitlimit scheitert – solche Läufe werden deshalb
übersprungen und im Modul als „dafür ist ein echter System-Cron nötig" angezeigt. Der
Eintrag dafür lautet:

```cron
* * * * * /usr/bin/php /pfad/zur/installation/vendor/bin/contao-console contao:cron
```

Wer keinen System-Cron einrichten kann, aktiviert ersatzweise „Auch beim Web-Cron
versuchen" – mit dem Risiko abgebrochener Läufe (ein abgebrochener Lauf hinterlässt
nichts, siehe das `.partial`-Verfahren oben).

Reicht der freie Plattenplatz nicht, wird der Lauf übersprungen und der Grund im
System-Log vermerkt, statt die Platte vollzuschreiben. Und auch hier gilt: **kein
Ersatz für eine Sicherung außer Haus.**

## Wiederherstellung (Restore)

> ⚠️ **NUTZUNG AUF EIGENE GEFAHR!** Die Wiederherstellung **ersetzt den aktuellen
> Stand unwiderruflich**. Schlägt sie mittendrin fehl (z. B. durch ein hartes
> Server-Timeout), kann eine **nicht mehr funktionierende Installation** zurückbleiben,
> die sich nur noch von Hand retten lässt. Eine Wiederherstellung deshalb **nur**
> durchführen, wenn man im Notfall auch ohne das Backend an den Server kommt – also
> **Zugangsdaten zum Hosting** (FTP/SSH und Datenbank) besitzt **und weiß, wie ein
> Backup manuell eingespielt wird**. Dieselbe Warnung steht unübersehbar im Modul.

Unter den Download-Buttons bietet die Seite zwei Wege (nur für Administratoren,
jeweils mit Optionen und einer Bestätigung durch Eintippen von **WIEDERHERSTELLEN**):

- **Datenbank-Sicherung vom Server wiederherstellen** – listet die Contao-Backups
  aus `var/backups` (dort legt auch der „Nur Datenbank"-Download eine Kopie ab).
- **Backup-Archiv (ZIP) hochladen oder vom Server auswählen** – ein mit diesem Bundle
  erstelltes Voll- oder Dateien-ZIP. Es gibt dafür zwei Wege, die zum selben Ergebnis
  führen; danach zeigt die Seite, was das Archiv enthält (Datenbank-Dump,
  Dateien-Pfade), und was eingespielt werden soll, ist per Checkbox wählbar:
  - **Hochladen** über den Browser. Der Upload läuft in **kleinen Teilstücken**
    (Chunks unterhalb von `upload_max_filesize`/`post_max_size`), dadurch funktionieren
    auch mehrere GB große Archive trotz PHP-Upload-Limits.
  - **Auswählen**, wenn das Archiv bereits auf dem Server liegt (siehe unten).

### Archiv vom Server auswählen (ohne Upload)

Ein Archiv, das per **FTP/SSH** oder über die Dateiverwaltung des Hostings nach
`var/backups` (oder `var/backup_restore`) übertragen wurde, erscheint im Modul als
Auswahlliste und lässt sich dort direkt übernehmen – ganz ohne Browser-Upload. Für
sehr große Archive und auf wackeligen Leitungen ist das der zuverlässigste Weg: Der
FTP-Client kann eine abgebrochene Übertragung fortsetzen, und weder Browser noch
PHP-Upload-Limits sind beteiligt.

Beide Verzeichnisse liegen **außerhalb des Web-Roots** – ein dort abgelegtes Backup
ist also nie über HTTP abrufbar. Aus genau diesem Grund wird `files/` bewusst **nicht**
durchsucht: dessen Ordner können veröffentlicht sein.

Die ausgewählte Datei wird dabei **weder kopiert noch verschoben**, sondern an Ort und
Stelle gelesen. Das hat zwei Konsequenzen:

- Ein mehrere GB großes Archiv braucht den Platz **nicht doppelt**.
- Die Datei **bleibt liegen** – auch nach einer erfolgreichen Wiederherstellung und
  auch, wenn die Auswahl wieder aufgehoben wird. Ein zweiter Versuch ist damit ohne
  erneute Übertragung möglich. Wird sie nicht mehr gebraucht, entfernt sie der Knopf
  **„Ausgewähltes Archiv vom Server löschen"** (oder eben der FTP-Client).

Alles Weitere – Analyse, Kompatibilitätsprüfung, Optionen, Ablauf und Sicherheitsnetz –
ist identisch mit dem hochgeladenen Archiv.

### Ablauf und Sicherheitsnetz

1. **Analyse und Entpacken passieren zuerst** – in ein Staging-Verzeichnis unter
   `var/backup_restore`. Ein defektes oder manipuliertes Archiv bricht ab, **bevor**
   irgendetwas verändert wurde.
2. **Sicherheits-Backup** (Option, standardmäßig an): Vor dem Einspielen wird die
   aktuelle Datenbank als normales Contao-Backup gesichert – der Rettungsanker,
   falls das falsche Backup gewählt war.
3. **Datenbank:** Es werden zuerst **alle Tabellen gelöscht** (außer den in Contaos
   `backup.ignore_tables` bewusst ausgenommenen), danach wird der Dump über Contaos
   eigenen Restore eingespielt. So bleibt keine Tabelle übrig, die nicht zum Backup
   gehört – der Stand entspricht exakt der Sicherung.
4. **Dateien:** Jeder im Archiv enthaltene Pfad (z. B. `files`, `templates`) wird
   **atomar getauscht** (der alte Ordner wird beiseite geschoben, der neue an seine
   Stelle) – bei einem Fehler mittendrin wird zurückgerollt, gelöscht wird das Alte
   erst nach dem letzten erfolgreichen Tausch. Seit dem Backup hinzugekommene Dateien
   entfallen dadurch vollständig.
5. **Danach:** Caches werden geleert (HTTP-Cache, Pools; nach Datei-Restore auch
   Twig/Contao/Übersetzungen), optional laufen die **Datenbank-Migrationen**
   (`contao:migrate`, ohne Löschungen – empfohlen, gleicht das Schema an den
   installierten Code an) und die Dateisynchronisation (`contao:filesync`).
   Die Ergebnisseite listet jeden Schritt auf, prüft per Kurztest, ob die
   Website antwortet, und zeigt – falls composer.json/lock eingespielt wurden –
   wie weit die installierten Pakete vom eingespielten `composer.lock` abweichen
   (mit direktem Link zum Contao Manager für das nötige `composer install`).

### Versionsunterschiede zwischen Backup und Installation

Jedes Backup enthält eine **`backup-manifest.json`** (Contao-/PHP-/DB-Version und
die komplette installierte Paketliste des Quellsystems). Beim Hochladen prüft die
Wiederherstellung damit die Kompatibilität, bevor irgendetwas verändert wird:

- **Backup und Installation passen** (gleiche Contao-Feature-Version) → grüner Hinweis.
- **Backup ist älter** (z. B. 5.3-Backup in einer 5.7-Installation) → gelber
  Hinweis; die Migrations-Option schließt die Schema-Lücke direkt nach dem
  Einspielen.
- **Backup ist neuer** (z. B. 5.7-Backup in einer 5.3-Installation) → die
  Wiederherstellung wird **blockiert** (die Datenbank wäre neuer als der Code,
  ein Downgrade-Pfad existiert nicht) und lässt sich nur über eine zusätzliche
  Risiko-Checkbox erzwingen – empfohlen ist stattdessen, die Ziel-Installation
  zuerst zu aktualisieren.
- Weicht die **PHP-Version** der Quelle nach oben ab, erscheint beim Einspielen
  von composer.json/lock ein Warnhinweis (das Lock ist auf älterem PHP eventuell
  nicht installierbar).

Archive ohne Manifest (mit einer älteren Bundle-Version erstellt) funktionieren
weiterhin – nur ohne diese Prüfung.

Alle Optionen sind **standardmäßig aktiviert** – auch `composer.json`/`composer.lock`
(eigene Checkbox). Zu beachten ist dabei: `vendor/` ist nie Teil des Backups. Weicht das
eingespielte `composer.lock` vom installierten Stand ab, ist danach ein
`composer install` bzw. der Contao Manager nötig – die Ergebnisseite zeigt genau das an
(inklusive Link zum Manager) und meldet ausdrücklich, wenn die Pakete bereits passen.
Beim Wiederherstellen in dieselbe Installation ist das der Regelfall. Zum Auslassen
genügt es, die Checkbox abzuwählen. Gleiches gilt generell: Haben sich die installierten
Erweiterungen seit dem Backup geändert, anschließend `composer install`/`contao:migrate`
ausführen.

### Backup in eine andere/frische Installation einspielen

Der Restore prüft nur, dass der Dump ein Contao-Backup ist – nicht, von welcher
Installation er stammt. Ein Backup lässt sich damit auch **umziehen**: In der
Ziel-Installation (gleiche bzw. neuere Contao-Version) dieses Bundle installieren,
das Voll-ZIP hochladen, einspielen – fertig. `.env`-Werte (`DATABASE_URL`,
`APP_SECRET`) sind bewusst nicht Teil des Backups und bleiben die der
Ziel-Installation. Benutzer/Passwörter entsprechen danach dem Stand des Backups –
gegebenenfalls neu anmelden (mit den Zugangsdaten aus dem Backup).

**Contao Manager:** Der Restore fasst den Manager nie an – nach einem Restore in
derselben Installation funktioniert er unverändert. Er ist aber auch **nicht Teil
des Backups** (`public/` und der Datenordner `contao-manager/` werden bewusst nicht
gesichert). Hat die **Ziel-Installation** eines Umzugs noch keinen Manager, ihn dort
einmalig installieren: [`contao-manager.phar`](https://download.contao.org/contao-manager/stable/contao-manager.phar)
herunterladen und als `public/contao-manager.phar.php` ablegen; beim ersten Öffnen
ein Manager-Konto anlegen (Manager-Konten sind unabhängig von den
Backend-Benutzern und stecken nicht im Backup).

### Weitere Hinweise

- Während des Einspielens ist die Website kurz inkonsistent (Tabellen werden gerade
  ersetzt) – Besucher können in diesem Moment Fehler sehen. Zeitfenster: je nach
  Größe Sekunden bis wenige Minuten.
- Es kann immer nur **eine** Wiederherstellung gleichzeitig laufen (Lock).
- Für das Entpacken wird temporär zusätzlicher Plattenplatz benötigt (ZIP +
  entpackter Stand); der freie Platz wird vorher geprüft.
- Jede Wiederherstellung wird im System-Log protokolliert (Start, Schritte im
  Ergebnis, Fehler).

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
- **Wiederherstellung gehärtet:** ebenfalls nur für Administratoren und CSRF-geschützt,
  zusätzlich serverseitig geprüftes Bestätigungswort. Hochgeladene Archive werden vor
  dem Entpacken validiert: Einträge mit Pfad-Ausbruch (`../`, absolute Pfade,
  Backslashes) lehnen das **gesamte** Archiv ab, Symlink-Einträge werden nie entpackt,
  und entpackt wird ausschließlich in die bekannten Backup-Pfade – alles andere im
  Archiv wird ignoriert. Der Upload liegt unter `var/` (nicht per URL erreichbar).

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

**Download-Art wählbar:** Standard ist der **klassische Browser-Download** – er läuft im
Download-Manager des Browsers und damit auch weiter, wenn die Seite verlassen wird. In
**Chrome/Edge** lässt sich oben alternativ **„Mit Fortschrittsbalken auf dieser Seite"**
wählen (Wahl wird im Browser gemerkt): Dann wird der Download per JavaScript über die
[File System Access API](https://developer.mozilla.org/docs/Web/API/Window/showSaveFilePicker)
direkt auf die Platte gestreamt – mit einem Fortschrittsbalken samt Prozent/MB **im
Backup-Modul selbst**. Die Prozentangabe ist exakt, weil die ZIP-Größe vorab in einem
schnellen Durchlauf ermittelt (nur ein `stat` pro Datei, kein Inhalt – bei z. B. ~1.750
Dateien unter einer halben Sekunde) und als `Content-Length` gesendet wird. **Firefox/Safari**
fallen automatisch auf den normalen Browser-Download zurück (Fortschritt dann im
Download-Manager des Browsers). Einzige Konsequenz: Ändert eine Datei *während* des (evtl.
langen) Downloads ihre Größe, kann das ZIP unvollständig sein – dann einfach erneut laden.

**Wiederherstellung sehr großer Archive:** Für den umgekehrten Weg – ein GB-großes ZIP
zurück auf den Server – ist der Browser-Upload nicht die einzige Möglichkeit: Das Archiv
lässt sich per FTP/SSH nach `var/backups` legen und im Modul einfach auswählen (siehe
„Archiv vom Server auswählen"). Damit entfallen Upload-Limits und Browser-Timeouts
vollständig.

**Abgestufte Fallbacks (Progressive Enhancement):** Die Buttons sind echte
`<form method="post">` – **ohne JavaScript** lädt ein Klick das Backup ganz normal herunter
(der Server streamt, der Browser zeigt seinen eigenen Fortschritt). Bricht der
JavaScript-Download ab (Speicherdialog nicht verfügbar, Platte voll, Netzfehler), wird die
halbe Datei verworfen und **automatisch auf den normalen Browser-Download umgeschaltet**.
Nur ein echter Serverfehler (z. B. abgelaufenes Request-Token) wird als Fehler gemeldet.

## Fehlersuche

Schlägt ein Download fehl, wird der Grund protokolliert – im Backend unter
**System › System-Log** und in `var/logs/`. Häufigste Ursache auf produktiven Servern:
`var/backups/` ist für den Webserver-Benutzer **nicht beschreibbar** (Contao legt dort
das Datenbank-Backup ab) → Meldung „Unable to write to backups/…". Abhilfe: Schreibrechte
auf `var/` bzw. `var/backups/` setzen. (Der reine **Dateien**-Download funktioniert davon
unabhängig, weil er kein Datenbank-Backup erzeugt.)

**Fortschrittsbalken bleibt leer / nur MB statt Prozent:** Der Server komprimiert die
Antwort (gzip, z. B. Apache `mod_deflate`). Beim Gzippen einer *gestreamten* Antwort wirft
der Server die `Content-Length` weg, sodass der Browser die Gesamtgröße nicht kennt. Das
Bundle erkennt das automatisch und blendet auf der Sicherung-Seite einen Hinweis ein. Für
den Prozent-Balken die Kompression für den Download abschalten – in `public/.htaccess`:

```apache
<IfModule mod_setenvif.c>
    SetEnvIf Query_String "do=backup" no-gzip dont-vary
</IfModule>
```

Der Download selbst ist davon nie betroffen, nur die Prozentanzeige.

**Kein Prozent-Balken bei sehr vielen Dateien:** Der Vorab-Durchlauf, der die exakte
ZIP-Größe ermittelt, hält für jede Datei einen Eintrag im Speicher (gemessen ~4 KB). Bei
Installationen mit Zehntausenden Dateien – etwa wenn ein komplettes Icon-Paket unter
`files/` liegt – überschreitet das ein typisches `memory_limit` von 128 MB (bei 34.000
Dateien rund 150 MB). Das Bundle zählt die Dateien deshalb vorher und **überspringt die
Größenberechnung**, wenn sie nicht sicher in den verfügbaren Speicher passt: der Download
läuft dann ohne Prozentanzeige, statt den Request abzubrechen. Der Grund steht im Log
(„progress bar disabled – simulating the archive for N files would need …"). Wer den Balken
möchte, hebt entweder das `memory_limit` an oder verkleinert den Dateibestand. Das
eigentliche Packen ist davon unabhängig und bleibt sparsam (bei denselben 34.000 Dateien
rund 42 MB), weil es jeden Eintrag sofort abschließt.

## Installation

```bash
composer require heimseiten/contao-backup-bundle
```

Anschließend erscheint im Backend unter **System** der Punkt **„Sicherung"**
(für Administratoren).
