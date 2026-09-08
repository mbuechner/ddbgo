# Migration des fachlichen Status

Betrifft Aggregator, Bestand und KWE: Das neue Listenfeld `field_record_status`
ersetzt die Farbauswahl durch „Abgelehnt“, „In Bearbeitung“ und „Ingestiert“.
Die Anzeige verwendet Farbe und Text. Das alte Feld `field_status` bleibt erhalten.

**Reihenfolge: `updb` → `status-check --complete` → `cim` → Indexierung → Freigabe.**
`updb` legt die fehlenden Statusfelder an und kopiert aktuelle Werte einschließlich
Revisionen. Erst `cim` stellt Formulare, Anzeigen, Views und Suchindizes um.

Unter Windows überall `drush` durch `.\drush.cmd` ersetzen.
Hinweise zu Launcher und Composer-Patch: [Drush-Dokumentation](../../../../drush/README.md).

## 1. Vorbereiten

- Den Ablauf zuerst auf einer aktuellen Produktionskopie testen.
- Code und Abhängigkeiten bereitstellen; **noch kein `cim` ausführen**.
- Schreibende Cronjobs, Queues, Importe und externe DB-Schreiber stoppen.
  Wartungsmodus allein stoppt diese nicht.
- Vollständiges Backup des ruhenden Datenbestands außerhalb des Webroots sichern;
  Wiederherstellung testen und bisherigen Code samt Konfiguration bereithalten.

```sh
drush state:set system.maintenance_mode 1 --input-format=integer
drush cr
drush updatedb:status
drush ddbgo:status-check
```

Auch andere anstehende Updates prüfen. **Bei jedem Fehler anhalten und die
Ursache klären; keine nachfolgenden Schritte ausführen.** Offene Werte (`pending`)
sind vor der Übertragung normal, Konflikte (`error_count`) müssen geklärt werden.

## 2. Felder anlegen und Werte übertragen

```sh
drush updb
drush ddbgo:status-check --complete
```

Update `ddbgo_gin_update_11002` kopiert in Batches, ohne Quelldaten oder bestehende
Zielwerte zu überschreiben. Unbekannte Farben und Konflikte blockieren den Lauf.
Die Abschlussprüfung verlangt vollständige Übertragung und unveränderte
Quellprüfsummen. Die Node-Schreibsperre bleibt bis zur Freigabe bestehen.

## 3. Konfiguration importieren und indexieren

**Erst nach erfolgreichem Schritt 2 `cim` ausführen:**

```sh
drush state:set system.maintenance_mode 1 --input-format=integer
drush cr
drush cim
drush cr
drush search-api:index aggregator
drush search-api:index bestand
drush search-api:index suche_kwe
drush search-api:status
```

Die Release-Konfiguration gemeinsam importieren. Der Import merkt alle Einträge
der drei Indizes zur Neuindexierung vor. Vor der Freigabe müssen diese vollständig
abgearbeitet sein.

Der Wartungsmodus muss bis zur Freigabe aktiv bleiben. `updb` stellt seinen
vorherigen Zustand wieder her; deshalb wird er vor `cim` nochmals eingeschaltet.
Fehlt nur der Wartungsmodus, ist kein erneuter Migrationslauf nötig.

## 4. Prüfen und freigeben

Formulare einschließlich Ablehnungsgrund, Detailseiten und betroffene Views prüfen:
Statuswerte, Farben, Tastaturbedienung und Screenreader-Ausgabe.

```sh
drush ddbgo:status-finish
```

Nur nach erfolgreichem Abschluss den Wartungsmodus beenden und Schreiber starten:

```sh
drush state:set system.maintenance_mode 0 --input-format=integer
drush cr
```

## Fehler und Wiederherstellung

Wartungsmodus und Schreibsperre bei Fehlern beibehalten. Nach Klärung kann `updb`
einen abgebrochenen Update-Hook fortsetzen. **Keine Sperren, Felddefinitionen oder
Migrationsmarker löschen, um Prüfungen zu umgehen.**

Nach Einspielen eines älteren Backups können neuere Zieltabellen zurückbleiben.
Nur für diesen bestätigten Fall zunächst den rein lesenden Archivierungsplan prüfen:

```sh
drush ddbgo:status-archive-orphans
```

Bei gestoppten Schreibern und aktivem Wartungsmodus die unzugeordneten Tabellen
archivieren und anschließend mit Schritt 2 fortfahren:

```sh
drush ddbgo:status-archive-orphans --execute
drush ddbgo:status-check
```

Die Archivierung verweigert aktive Feld-/Konfigurationsverweise und Migrationsmarker.
Sie benennt Tabellen um und prüft Zeilenzahlen und Prüfsummen; sie löscht keine Daten.
Archive behalten. Bei Teilabbrüchen zuerst Tabellen und Protokoll prüfen:
`drush state:get ddbgo_gin.status_archives`.

Backups künftig in eine separate, leere Datenbank zurückspielen, zusammen mit
passendem Code und Konfiguration. **Nach Freigabe wird das alte Farbfeld nicht
nachgeführt:** Neuere Änderungen vor einem Rückweg separat sichern und abgleichen.

## Entwicklung

Die Felddefinitionen in `config/status/` gehören zu Update 11002 und bleiben nach
Veröffentlichung unverändert. Isolierte SQL-/Konfigurations-/Twig-Prüfungen ohne
Änderung produktiver Inhalte:

```sh
drush php:script web/modules/custom/ddbgo_gin/tests/php/status-migration.test.php
```
