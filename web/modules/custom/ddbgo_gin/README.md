# Darstellung und Interaktion

Statisches HTML wird in Twig ausgegeben. PHP stellt die notwendigen Daten,
Berechtigungen und Cache-Abhängigkeiten bereit. JavaScript ist auf Interaktionen
und die unten beschriebenen Gin-Korrekturen begrenzt.

## Templates

- `form-element--ddbgo-gin`, `fieldset--ddbgo-gin`, `details--ddbgo-gin` und
  `datetime-wrapper--ddbgo-gin` übernehmen die Struktur der Gin-Formularwrapper.
  Das gemeinsame `ddbgo-help-toggle.html.twig` liefert Button-Typ, zugänglichen
  Namen und die Zuordnung zur Beschreibung über `aria-describedby`. Die Beschreibung
  erhält `role="tooltip"` und folgt unmittelbar dem Button (bei Details
  direkt nach dem Summary). Bestehende Beschreibungs-IDs bleiben erhalten.
  Die Namen stehen damit auch vor dem Start von JavaScript und in AJAX-Antworten
  im HTML. Suchhilfen bleiben in der Views-Konfiguration, Feldhilfen in ihrer bisherigen Konfiguration.
- `ddbgo-workspace-navigation.html.twig` und `menu--ddbgo-gin.html.twig` rendern
  Schaltflächen und Linklisten ohne zusätzliche Gin-Toolbar-Elemente.
  Der Block ermittelt den aktiven Bereich und den aktuellen Link serverseitig.
  Seine Cache-Kontexte berücksichtigen Route, URL-Pfad und Benutzerrechte.
- `ddbgo-page-actions.html.twig` platziert Reiter und Lesezeichen nebeneinander.
  Page-Preprocessing verwendet den vorhandenen Flag-Link-Builder. Das
  `flag--ddbgo-gin.html.twig` blendet die ursprüngliche Full-View-Ausgabe nur auf
  der zugehörigen Inhaltsseite mit sichtbaren primären Reitern im Header aus.
  Andere Flag-Ausgaben verwenden weiterhin das Originaltemplate von Flag.
  Sichtbarkeit und Cache-Abhängigkeiten der Reiter werden mit berücksichtigt.
- Pager und Blöcke für verknüpfte Inhalte verwenden bereits eigene Twig-Templates.

Die Formularwrapper sind gezielte Kopien, da Gin dort keine überschreibbaren
Twig-Blöcke für die Hilfeschaltflächen anbietet. Bei Gin-Updates diese vier Dateien
mit den Originalen unter `web/themes/contrib/gin/templates/form/` vergleichen.
Die Overrides gelten nur für Gin und davon abgeleitete Themes.

## Verbleibendes JavaScript

- `ddbgo_gin.unique-field-submit.js`: Verhindert fehlende Feldwerte beim Speichern
  während einer Dublettenprüfung. Das Script wird über die Library von
  `unique_field_ajax` geladen, unabhängig vom Theme. Es wartet auf laufende
  Formularanfragen einschließlich ihrer DOM-Aktualisierungen und setzt den
  Speichervorgang genau einmal mit dem ursprünglichen Submit-Button fort.
  Ein zusätzlicher Eventloop-Schritt berücksichtigt die beim Verlassen eines
  Feldes erst zeitversetzt gestartete Prüfung. Pflichtfeldvalidierung und
  Drupals Schutz gegen doppeltes Absenden bleiben aktiv. Bei AJAX-Fehlern oder
  einem Formular-Reset wird der vorgemerkte Speichervorgang abgebrochen.

- `ddbgo_gin.workspace-navigation.js`: Öffnen/Schließen, Escape und Fokus,
  mobile Menübedienung sowie Positionierung am Bildschirmrand. Markierungen
  und HTML-Struktur werden hier nicht mehr nachträglich ergänzt.
- `ddbgo_gin.form-help.js`: Öffnet die in Twig gerenderten Hilfetexte bei Hover
  und Tastaturfokus. Escape schließt ohne Fokuswechsel, Klick/Touch hält die Hilfe
  bis zum nächsten Klick, Fokuswechsel oder Klick außerhalb offen. Der Mauszeiger
  bleibt ein normaler Pfeil. Der Tooltip bleibt beim überfahren seines Textes
  sichtbar. Die Popover API verhindert Abschneiden durch Container; die Position
  passt sich Fenstergröße und Scrollen an. Hilfen innerhalb von Details werden
  während der Anzeige vorübergehend an `body` angehängt, weil geschlossene Details
  auch ihre Popovers verbergen. Danach kehren sie an die ursprüngliche Stelle
  zurück; ihre ID und Screenreader-Zuordnung ändern sich nicht. Ohne JavaScript
  bleibt der Text im Formular lesbar. Gin verwendet für diese Buttons einen
  anderen Selektor und überschreibt ihre Attribute daher nicht.
- `ddbgo_gin.exposed-filters.js`: Automatisches Absenden nach Tagify-Änderungen.
- `ddbgo_gin.toolbar-navigation.js`: Kompatibilitätskorrektur für Gins
  Verwaltungsnavigation. Ein Klick auf einen Verwaltungslink bzw. dessen
  Beschriftung folgt dem Ziel; der Aufklapp-Auslöser bleibt bedienbar. Das greift
  in Gins Ereignisbehandlung ein und lässt sich nicht allein durch statisches
  Markup ersetzen. Bei Änderungen an Gins Toolbar erneut prüfen.
- Flags AJAX-Verhalten wird weiterverwendet. Es gibt kein eigenes JavaScript
  zur Benennung der Hilfeschaltflächen oder zum Verschieben des Lesezeichens.

Formularvalidierung, Normalisierung und Filteraufbau bleiben in PHP/Form API.
CSS übernimmt Gestaltung, Umbrüche und responsive Anordnung.

Nach Änderungen an Templates, Theme-Hooks oder Libraries: `drush cr`.
Zur Prüfung Anlegeformulare aller vier Inhaltstypen, alle sieben Suchseiten,
Inhaltsseiten mit Lesezeichen und die mobile Navigation öffnen. Hilfenamen
bereits im Seitenquelltext kontrollieren; Klick, Tastatur, AJAX und einen
zweiten Seitenaufruf mit gefülltem Cache ebenfalls prüfen.

## Regressionstest für das Speichern bei laufender Dublettenprüfung

Vom Projektverzeichnis aus ausführen:

```sh
node web/modules/custom/ddbgo_gin/tests/js/unique-field-submit.test.cjs
```

Die ausgegebene temporäre HTML-Datei im Browser öffnen. Am Ende steht `PASS`
oder `FAIL`. Die Testseite verwendet die installierten Drupal-Callbacks und den
Change-Handler von Unique Field AJAX, kontrolliert aber die Antwortzeiten lokal.
Es werden keine Formulardaten an den Server gesendet. Geprüft werden direkte
Speicherklicks, parallele Prüfungen, Austausch von Eingabefeldern und Buttons,
Mehrfachklicks, native Validierung, Fehler, Reset und unbeteiligte Formulare.

## Regressionstest für Formularhilfen

```sh
node web/modules/custom/ddbgo_gin/tests/js/form-help.test.cjs
```

Die ausgegebene HTML-Datei im Browser öffnen. Der Test verwendet die tatsächliche
Tooltip-Library und prüft Hover, Fokus, Escape, Klick, Verlassen, wiederholtes
Attach, AJAX, geschlossene Details und die Position am Bildschirmrand. Er sendet
keine Formulare ab. Zusätzlich mit schmalem Browserfenster prüfen. Die technischen
Tests ersetzen keinen manuellen Test mit NVDA oder VoiceOver.

Die Tooltip-Gestaltung nutzt Gins Variablen `--gin-tooltip-bg`, `--gin-border-s`
und `--gin-shadow-l2`, mit einem kleinen Richtungspfeil. Gins installierte
`gin/tooltip`-Library erzeugt das Markup per JavaScript und bietet selbst keine
ARIA-Zuordnung, Escape-Behandlung oder Hover-Persistenz auf dem Hilfetext. Deshalb
bleiben das Twig-Markup und die gezielte Interaktions-Library hier erforderlich.
