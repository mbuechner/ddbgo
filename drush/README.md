# Drush unter Windows

Im Projektverzeichnis den lokalen Einstiegspunkt verwenden:

```powershell
.\drush.cmd updatedb:status
.\drush.cmd updb
```

`php` muss im PATH verfügbar sein. Der Launcher findet das Projekt unabhängig
vom Arbeitsverzeichnis. Der Alias `ddbgo.windows` verwendet standardmäßig
`http://localhost:8888`; eine andere lokale URI kann mit `--uri` angegeben werden.
Dieser Einstiegspunkt ist für die lokale Windows-Installation gedacht.
Auf Linux/Produktion bleibt der bisherige Drush-Aufruf bestehen.

`vendor\bin\drush.php.bat` startet zwar den Hauptprozess über PHP, Drush 13
wählt für lokale Unterprozesse aber erneut `vendor/bin/drush`. Dieser
Shell-Launcher kann unter Windows mit Exit-Code 127 scheitern. Das betrifft
unter anderem `updb` und die Batch-Neuindexierung.

`drush.cmd` setzt einen lokalen Alias mit `paths.drush-script` auf sich selbst.
Damit starten auch Unterprozesse über PHP, einschließlich weiterer verschachtelter
Aufrufe. Der Launcher verändert keine Paketdateien. Argumente und Exit-Codes werden
weitergegeben; die Projektpfade werden aus dem Speicherort des Launchers ermittelt.
Der PHP-Aufruf verwendet Composers öffentlichen Proxy `vendor/bin/drush.php`,
keine interne Klasse oder veränderte Paketdatei. Die Alias-Einstellung ist Teil
der dokumentierten Drush-Konfiguration (`paths.drush-script`).

## Nach Composer-/Drush-Upgrades

Den folgenden Test nach jedem Drush-Upgrade auf Windows ausführen. Er kontrolliert
den für `updb` verwendeten Unterprozess, Argumente mit Leerzeichen, eine weitere
Verschachtelung, Fehlercodes und den JSON-Decoder. Er muss mit fünf `PASS`-Meldungen
und Exit-Code 0 enden.
Bei einem Fehler keine Migration beginnen. Damit werden Änderungen an
der unterstützten Drush-Schnittstelle vor einem Deployment erkannt; vollständige
Kompatibilität mit noch unbekannten Hauptversionen lässt sich nicht garantieren.

Rein lesende Prüfung der Unterprozesse, ohne Updates oder Indexierung:

```powershell
.\drush.cmd php:script drush/tests/subprocess.php
```

Für die Statusmigration gilt weiterhin die Reihenfolge aus
[STATUS-MIGRATION.md](../web/modules/custom/ddbgo_gin/STATUS-MIGRATION.md).
Der Launcher führt keine Updates automatisch aus und umgeht keine Migrationstests.

## JSON-Ausgabe von Unterprozessen

`consolidation/site-process` 6.1.0 entfernt unter Windows Escape-Zeichen auch
aus bereits gültigem JSON. Ein Fehlertext mit Anführungszeichen kann dadurch
eine zweite Fehlermeldung beim Auswerten des Update-Batches verursachen.
Der Composer-Patch `patches/site-process-preserve-valid-json.patch` versucht
zuerst das unveränderte JSON zu lesen. Die bisherige Behandlung von älteren
Shell-Ausgaben bleibt als Fallback erhalten.

Der Patch ist in `composer.json` und `patches.lock.json` registriert. Bei
Neuinstallationen wird er durch Composer Patches angewendet. Bei bereits
installierten Abhängigkeiten `composer patches-repatch` ausführen; ein bloßer
Konfigurationsimport installiert keine Paketpatches. Bei einem Paketupgrade
Patch-Anwendbarkeit und den Decoder-Test prüfen; nach einem entsprechenden
Upstream-Fix den Patch entfernen und die Patch-Lockdatei neu erzeugen.
