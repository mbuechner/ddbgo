# Darstellung und Interaktion

Statisches HTML wird in Twig ausgegeben. PHP stellt die notwendigen Daten,
Berechtigungen und Cache-Abhängigkeiten bereit. JavaScript ist auf Interaktionen
und die unten beschriebenen Gin-Korrekturen begrenzt.

## Templates

- `form-element--ddbgo-gin`, `fieldset--ddbgo-gin`, `details--ddbgo-gin` und
  `datetime-wrapper--ddbgo-gin` übernehmen die Struktur der Gin-Formularwrapper.
  `ddbgo-help.html.twig` setzt Button und Tooltip gemeinsam zusammen. Details
  bindet beide Teile getrennt ein, damit sein Hilfetext außerhalb von Summary bleibt.
  `ddbgo-help-toggle.html.twig` liefert Button-Typ, zugänglichen
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

Die Tooltip-Gestaltung nutzt Gins Variablen `--gin-tooltip-bg`, `--gin-border-s`
und `--gin-shadow-l2`, mit einem kleinen Richtungspfeil. Gins installierte
`gin/tooltip`-Library erzeugt das Markup per JavaScript und bietet selbst keine
ARIA-Zuordnung, Escape-Behandlung oder Hover-Persistenz auf dem Hilfetext. Deshalb
bleiben das Twig-Markup und die gezielte Interaktions-Library hier erforderlich.

## Leere verknüpfte Einträge

Das Inline-Paragraphs-Widget (`entity_reference_paragraphs`) zeigt bei leeren
Feldern keinen generischen Hinweis „Noch kein Seitenabschnitt hinzugefügt.“
mehr. Feldüberschrift, Pflichtfeldmarkierung, Hilfetext und Hinzufügen-Aktionen
bleiben erhalten. Ein gezielter Widget-Alter-Hook entfernt nur das Textelement
aus dem Render-Array, auch bei AJAX-Neuaufbau und künftig ergänzten Feldern
dieses Widget-Typs. Das Contrib-Widget erzeugt den Hinweis direkt in PHP und
bietet dafür weder eine eigene Vorlage noch eine Einstellung zum Ausblenden.

## Abstände in Detailansichten und Formularen

Der äußere Node-Formularcontainer in Gin Frontend hat keinen eigenen Hintergrund,
Rahmen, Schatten oder Innenabstand. Die Tab-/Abschnittsflächen übernehmen die
Gliederung; so entfällt die doppelte Einrückung beim Anlegen und Bearbeiten.

`ddbgo_gin.section-spacing.css` verwendet für vollständige Node-Anzeigen,
Node-Anlage-/Bearbeitungsformulare und aufklappbare Verknüpfungsblöcke gemeinsame
Innenabstände: 24 Pixel ab 48em,
darunter 16 Pixel (über Gins rem-basierte Abstandsvariablen). Tab-Inhalte
beginnen mit diesem Abstand unter der Trennlinie; Gins überlappende Tab-Abstände
werden dafür in Anzeige und Formular zurückgesetzt. Native aufklappbare Abschnitte
behalten einen seitlichen Innenabstand, während Tabs die Einrückung ihrer
äußeren Karte verwenden. Auf kleinen Bildschirmen ist diese äußere Karte
kompakter, damit sich die Einrückungen nicht unnötig addieren.

Tab-Reiter erhalten bei Mausbedienung einen dezenten Hover-Schatten; inaktive
Reiter heben sich um 1 Pixel an. Die aktive Unterstreichung bleibt an ihrem Platz.
Der Tastaturfokus ist separat umrandet; bei reduzierter Bewegung entfällt die
Anhebung samt Übergang. Diese Regeln gelten in Detailansichten und Formularen.
Die primären Seitenaktionen „Ansicht“, „Bearbeiten“, „Löschen“ und „Revisionen“
verwenden dieselben Effekt- und Fokusregeln; ihre aktive Gin-Markierung bleibt bestehen.
Die Regeln greifen am Aktionsblock selbst, auch beim Bearbeiten ohne Lesezeichen-Container.
Das Lesezeichen daneben verwendet eine gleich hohe, abgerundete Schaltfläche mit
Icon und sichtbarem Text in dezenter Schrift. Auf kleinen Bildschirmen steht es
unter den Reitern. Gesetzte Lesezeichen sind gefüllt und farbig hinterlegt; der
Aktionsname und Mouseover-Hinweis bleiben auch nach Flag-AJAX erhalten.

Feldzeilen erhalten 16 bzw. 12 Pixel vertikalen Innenabstand. Paragraph-Karten
(Personen, Kontakt, DDB-/Europeana-Objektangaben) stehen linksbündig mit 16 Pixel
Abstand nebeneinander und brechen bei Platzmangel in die nächste Zeile um.
Ihre Breite richtet sich nach dem Inhalt, höchstens 36rem bzw. der verfügbaren
Breite. Einzelne und mehrere Karten verwenden dieselben Regeln, auch mit
sichtbarer Feldüberschrift. Andere Mehrfachwerte stehen mit 8 Pixel
Abstand untereinander. Die Anpassungen verändern weder die Tab-Steuerung noch
die Reihenfolge der Inhalte. Diese Feldzeilen-/Kartenregeln bleiben auf die
Anzeige begrenzt; die bestehenden Abstände zwischen Formulareingaben bleiben
erhalten. Am Anfang und Ende eines Formularabschnitts werden zusätzliche
Feldränder ausgeglichen. Paragraph-Unterformulare erhalten 16 Pixel Abstand
unter ihrer Überschrift, ohne zusätzliche seitliche Einrückung. Das Personenformular
erhält den normalen Kartenabstand auch ohne einzelne Tab-Unterbereiche.
Lange Texte sowie die Zeile mit Paragraph-Überschrift und Aktionen dürfen
auf schmalen Bildschirmen umbrechen. Das Template
`input--ddbgo-gin-paragraph-add.html.twig` gibt Hinzufügen-Aktionen als native
Submit-Buttons mit umbrechender Beschriftung aus; Name, Wert, ID und
AJAX-Attribute bleiben erhalten.

Die Library `section_spacing` wird über `theme_components` in Gin und Gin
Frontend geladen. Die CSS-Selektoren erfassen auch AJAX-neuaufgebaute
Node-Formulare. Suchfilter behalten ihre unabhängigen Abstände und Raster.

## Einheitliche Formularfelder

`ddbgo_gin.form-controls.css` wird über `theme_components` auf Gin und Gin
Frontend geladen. Native Texteingaben, Auswahlfelder, Textareas, Select2 und
Tagify nutzen die Breite ihrer vorhandenen Formular- oder Suchfilterspalte.
Die Höhe und Farben folgen Gins Variablen. Mehrfachauswahlen und lange
Select2-Werte dürfen umbrechen und wachsen; Textareas behalten ihre Zeilenanzahl.
Datum/Uhrzeit, Checkboxen, Radios, Dateiuploads, Sortiergewichte und kompakte
Editor-Auswahlen behalten ihre eigenen Maße.

Select2 erhält für Einfach- und Mehrfachauswahl denselben dekorativen Pfeil,
Gin-Farben auch im ausgelagerten Ergebnisfenster sowie sichtbare Fokus-, Fehler-
und deaktivierte Zustände. Der Pfeil fängt keine Zeigerereignisse ab. Die
Originalfelder, Labels, ARIA-Attribute, Tastatursteuerung, AJAX-Suche,
Auswahlreihenfolge und Löschfunktionen bleiben beim jeweiligen Widget.
Die Breitenregel überschreibt ausschließlich die inline gesetzte Breite des
Select2-Auswahlcontainers, nicht die Positionierung seines Ergebnisfensters.
Dadurch passen auch in geschlossenen Tabs initialisierte Widgets später in ihre
Spalte. Neue Felder mit denselben Widget-Klassen werden automatisch erfasst.

Die gemeinsamen Select2-Regeln erfassen sowohl den Default-Skin von Gin Frontend
als auch den Gin-Skin des Select2-Moduls. Zusätzliche Innenabstände um dessen
Suchbereich und Auswahlwerte werden ausgeglichen. Leere und einzeilig befüllte
Mehrfachauswahlen haben damit dieselbe Mindesthöhe wie Texteingaben; bei Umbruch
wachsen sie weiter. Formularabschnitte verwenden diese Regeln ebenfalls, ohne
eigene Select2-Größenregeln in `ddbgo_gin.frontend-layout.css`.
Die interne Auswahlliste erhält außerdem keinen Listenabstand: Drupals
allgemeine Listeneinrückung würde selbst bei leerer Auswahl den Suchbereich
mit dem Platzhalter in eine zusätzliche Zeile verschieben.

### Auswahl des Widgets

Kleine, feste Listen mit Einfachauswahl verwenden in `core.entity_form_display.*`
das Core-Widget `options_select`. Als Richtwert wurden bis zu 20 vorhandene
Begriffe zugrunde gelegt, etwa Ja/Nein, Status, Sparte, Bundesland, Medientyp und
Europeana-Tiers.

Mehrfachauswahllisten verwenden unabhängig von ihrer Größe Select2. Das
Dropdown bleibt dadurch kompakt; ausgewählte Werte erscheinen als einzeln
entfernbare Einträge. Dies gilt auch für Datenformat, Lieferweg, Coding da Vinci-
Events, die Ausrichtung nach Sparte und Sichtbarkeit. Die Feldkardinalität,
vorhandene Werte, Auswahlmöglichkeiten und bedingte Feldabhängigkeiten werden
beim Widget-Wechsel nicht geändert. Die Bestandstags behalten ausdrücklich
Tagify mit Such-Dropdown, Mehrfachauswahl und Begriffs-IDs.

Select2 bleibt bei umfangreichen oder erweiterbaren Listen: Bestandsart,
geografische Ausrichtung, Länder, Personenrollen sowie Verweise auf Aggregatoren,
KWEs und Personen. „Titel“ bleibt trotz der kleinen Liste ebenfalls Select2,
weil neue Titel ausdrücklich direkt im Personenformular anlegbar bleiben sollen.
Die Entscheidung steht in der Konfiguration und wird nicht beim Seitenaufruf
anhand einer Datenbankzählung getroffen. Bei stark wachsenden Listen die
Widget-Auswahl erneut prüfen.

Alle sieben konfigurierten Neuanlagen sind in `field.field.*.description`
erklärt: Bestandsart und geografische Ausrichtung bei Aggregatoren, akademischer
Titel bei Personen, Rollen in den drei Personen-Paragraphen und die Person im
Bestands-Personen-Paragraphen. Der Hilfetext erklärt Eingabe, Auswahl und Anlage
beim Speichern; er nutzt die bestehenden Hilfe-Templates. Bestehende
Beschreibungen bleiben enthalten. Neue KWE-Personenrollen verwenden wie die
Auswahlliste das Vokabular `personenrolle`.

```sh
node web/modules/custom/ddbgo_gin/tests/js/form-controls.test.cjs
```

Die erzeugte lokale HTML-Datei verwendet die installierten Gin-, Select2- und
Tagify-Dateien und benötigt keine Anmeldung. Sie prüft Feldmaße, Umbrüche,
Suchfunktion, Tastaturauswahl, Escape, Fokus, Löschen, deaktivierte/fehlerhafte
Felder und die Initialisierung in einem versteckten Bereich. Mit schmalem
Fenster wiederholen. Die URL-Parameter `?mode=frontend` und `?mode=gin` prüfen
zusätzlich Formularabschnitte in Gin Frontend bzw. den Gin-Skin von Select2.
Die Höhenprüfung umfasst auch eine bereits ausgewählte Option.
Ergänzend die echten Formulare und Suchfilter sowie
Dunkelmodus und hohen Kontrast prüfen; dies ersetzt keinen Screenreader-Test.

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
  und Tastaturfokus. Twig rendert die Tooltip-Texte bereits mit `hidden`, damit sie
  auch vor dem Laden von CSS/JavaScript und in AJAX-Antworten nicht aufblitzen
  oder das Layout verschieben. Escape schließt ohne Fokuswechsel, Klick/Touch
  hält die Hilfe bis zum nächsten Klick, Fokuswechsel oder Klick außerhalb offen.
  Der Mauszeiger
  bleibt ein normaler Pfeil. Der Tooltip bleibt beim Überfahren seines Textes
  sichtbar. Die Popover API verhindert Abschneiden durch Container; die Position
  passt sich Fenstergröße und Scrollen an. Hilfen innerhalb von Details werden
  während der Anzeige vorübergehend an `body` angehängt, weil geschlossene Details
  auch ihre Popovers verbergen. Danach kehren sie an die ursprüngliche Stelle
  zurück; ihre ID und Screenreader-Zuordnung ändern sich nicht. Ohne JavaScript
  bleibt der Text im Formular über die CSS-Abfrage `scripting: none` lesbar.
  Gin verwendet für diese Buttons einen anderen Selektor und überschreibt ihre Attribute daher nicht.
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
Tooltip-Library und prüft die anfängliche Sichtbarkeit vor CSS und verzögertem
JavaScript, das Layout beim Attach sowie Hover, Fokus, Escape, Klick, Verlassen,
wiederholtes Attach, AJAX, geschlossene Details und die Position am Bildschirmrand. Er sendet
keine Formulare ab. Zusätzlich mit schmalem Browserfenster prüfen. Die technischen
Tests ersetzen keinen manuellen Test mit NVDA oder VoiceOver.

## Zuständigkeiten und Konfiguration

| Bereich | Zuständigkeit |
| --- | --- |
| `ddbgo_gin.module` | Drupal-Hooks, Theme-Auswahl, Suchfilter, Lesezeichen und Formularnormalisierung |
| `DdbgoWorkspaceNavigationBlock` | Zugriffsgeprüfte Menüstruktur, aktuelle Links und Cache-Metadaten |
| `RouteSubscriber` / `UserKeyAuthAccessCheck` | Titel der Anlegeformulare und Zugriffsprüfung für API-Schlüssel |
| `ddbgo_gin.libraries.yml` | Assets und Abhängigkeiten; Kommentare nennen die jeweilige Einbindestelle |
| `ddbgo_gin.services.yml` / `ddbgo_gin.permissions.yml` | Registrierung der Route-/Access-Dienste und der administrativen Berechtigung |
| `ddbgo_gin.install` | Statusanzeige mit beim Build ersetzten Versionsplatzhaltern |

Das Modul hat keine eigene installierbare Fachkonfiguration. Menüdefinitionen,
Blockplatzierungen, Gin-Einstellungen sowie Such- und Feldhilfen liegen in der
Projektkonfiguration unter `config/sync`. Diese Einstellungen werden durch die
Darstellungshooks verwendet, nicht beim Seitenaufruf umgeschrieben.

`ddbgo_gin_is_theme()` prüft das aktive Theme einschließlich seiner Basisthemes.
Die Abfrage wird bewusst nicht statisch zwischengespeichert: Theme-Wechsel während
des Renderns müssen sofort berücksichtigt werden. Form-API-Normalisierung und
Unique-Field-Schutz gelten dagegen auch außerhalb von Gin.

Bei Vereinfachungen müssen folgende Verträge erhalten bleiben:

- Die Filtergruppierung bewahrt `#parents` und `#name`, damit Views weiterhin
  dieselben GET-Parameter erhält. Tagify verwendet den vorhandenen Submit-Button.
- Beschreibungs-IDs und ARIA-Verknüpfungen stammen aus Twig. Hilfe-Buttons und
  Hilfetexte werden gemeinsam eingebunden; Details benötigt die getrennte Ausgabe.
- Persönliche Lesezeichen bleiben Flag-Lazy-Builder mit ihren Cache-Abhängigkeiten.
  Zugriffsrechte und Sichtbarkeit werden nicht durch Client-Code ersetzt.
- Gin-Abhängigkeiten liefern weiterhin CSS für Checkboxen und Formularwrapper.
  Scheinbar doppelte CSS-Selektoren mit höherer Spezifität überschreiben gezielt
  Regeln von Gin/Gin Frontend und sind nicht automatisch überflüssig.
- Die bestehende Trim-/NFC-Normalisierung läuft vor den Formularvalidatoren der vier
  Inhaltstypen. Sie gehört zum Speichern und ist unabhängig von der Suchindexierung.

## PHP-/Twig-Integrationstest

In der installierten DDBgo-Umgebung vom Projektverzeichnis aus:

```sh
vendor/bin/drush php:script web/modules/custom/ddbgo_gin/tests/php/module.test.php
```

Der Test prüft Theme-Wechsel, Bereichszuordnung, Attribute, Suchparameter und die
Beschriftungspositionen vor/hinter Eingabefeldern einschließlich unsichtbarer und
fehlender Beschriftungen. Er speichert weder Inhalte noch Konfiguration. Für die
Browsertests weiterhin die oben beschriebenen HTML-Testseiten verwenden.
## Suchfilter zurücksetzen und Cache

Views kann die letzte Filterauswahl in der Sitzung speichern. Damit kann eine
Suchseite ohne URL-Parameter trotzdem einen Suchbegriff und Dropdown-Auswahlen
enthalten. Ein ausschließlich nach URL variierender Render-Cache liefert nach
„Zurücksetzen“ möglicherweise diese frühere Darstellung erneut aus.

Der Cache-Kontext `ddbgo_view_filters:VIEW_ID` berücksichtigt daher die gespeicherten
Filterwerte der jeweiligen View. Er wird nur Formularen mit aktivierter
Merkfunktion hinzugefügt und an die umgebende Ausgabe weitergereicht. Änderungen
und Reset innerhalb derselben Sitzung ergeben unterschiedliche Cache-Varianten.
Suchbegriffe erscheinen dabei nur gehasht im Cache-Schlüssel; die Suchergebnis-
und Index-Caches bleiben aktiv.

Der Reset bleibt ein normaler Submit mit den vorhandenen Core-/BEF-Callbacks.
Bei solchen Formularen entfällt BEFs `reset_ajax`-Library: Sie besucht lediglich
die URL ohne Parameter und überspringt damit das Löschen der Sitzungswerte.
`data-drupal-selector="edit-reset"` sorgt zusätzlich dafür, dass Core Views den
Reset von AJAX ausnimmt. Suchen und andere AJAX-Aktionen bleiben unverändert.

Regressionstest (keine gespeicherten Inhalte oder Konfiguration werden verändert):

```sh
vendor/bin/drush php:script web/modules/custom/ddbgo_gin/tests/php/exposed-reset.test.php
```

Der Test prüft wechselnde Sitzungswerte und die Reset-/Cache-Anbindung aller
sieben Suchformulare. Zusätzlich im Browser eine Suche mit Dropdown-Auswahl
anwenden, die URL ohne Parameter aufrufen, zurücksetzen und dieselbe URL erneut
laden. Die Felder müssen nach Reset auch bei gefülltem Cache leer beziehungsweise
auf ihrer konfigurierten Standardauswahl stehen.

## Migration des Statusfelds

Der gemeinsame Status-Formatter zeigt außerhalb von Tabellen eine farbig
hinterlegte Statusfläche in Inhaltsbreite mit Text. In Tabellen erscheint nur das Farbsymbol
mit Mouseover-Hinweis; der Statusname bleibt für Screenreader lesbar. Beim
Drucken und im erzwungenen Farbmodus bleibt der Text auch dort sichtbar.
Die Darstellung wird ausschließlich über Twig und CSS gesteuert.

Die Umstellung von Farbwerten auf eine Drupal-Liste erfordert Update 11002 vor
dem Konfigurationsimport. Ablauf, Prüfbefehle und Rückweg stehen in
[STATUS-MIGRATION.md](STATUS-MIGRATION.md). Die alten Farbwerte bleiben erhalten.
