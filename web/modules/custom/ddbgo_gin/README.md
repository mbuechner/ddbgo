# Darstellung und Interaktion

Statisches HTML wird in Twig ausgegeben. PHP stellt die notwendigen Daten,
Berechtigungen und Cache-Abhängigkeiten bereit. JavaScript ist auf Interaktionen
und die unten beschriebenen Gin-Korrekturen begrenzt.

## Templates

- `form-element--ddbgo-gin`, `fieldset--ddbgo-gin`, `details--ddbgo-gin` und
  `datetime-wrapper--ddbgo-gin` übernehmen die Struktur der Gin-Formularwrapper.
  Das gemeinsame `ddbgo-help-toggle.html.twig` liefert Button-Typ, zugänglichen
  Namen und bei allen Formularhilfen die vorhandene Beschreibung als Mouseover-Text.
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

- `ddbgo_gin.workspace-navigation.js`: Öffnen/Schließen, Escape und Fokus,
  mobile Menübedienung sowie Positionierung am Bildschirmrand. Markierungen
  und HTML-Struktur werden hier nicht mehr nachträglich ergänzt.
- `ddbgo_gin.exposed-filters.js`: Automatisches Absenden nach Tagify-Änderungen.
  Zusätzlich wird bei Suchhilfen die Zuordnung zum Beschreibungselement nach
  Gins Behavior wiederhergestellt: Gin setzt `aria-controls` derzeit auf den
  Platzhalter `target`. Namen und Mouseover-Texte kommen ausschließlich aus Twig.
- `ddbgo_gin.toolbar-navigation.js`: Kompatibilitätskorrektur für Gins
  Verwaltungsnavigation. Ein Klick auf einen Verwaltungslink bzw. dessen
  Beschriftung folgt dem Ziel; der Aufklapp-Auslöser bleibt bedienbar. Das greift
  in Gins Ereignisbehandlung ein und lässt sich nicht allein durch statisches
  Markup ersetzen. Bei Änderungen an Gins Toolbar erneut prüfen.
- Gins eigenes Description-Toggle-Behavior und Flags AJAX-Verhalten werden
  weiterverwendet. Es gibt kein eigenes JavaScript mehr zur Benennung der
  Hilfeschaltflächen oder zum Verschieben des Lesezeichens.

Formularvalidierung, Normalisierung und Filteraufbau bleiben in PHP/Form API.
CSS übernimmt Gestaltung, Umbrüche und responsive Anordnung.

Nach Änderungen an Templates, Theme-Hooks oder Libraries: `drush cr`.
Zur Prüfung Anlegeformulare aller vier Inhaltstypen, alle sieben Suchseiten,
Inhaltsseiten mit Lesezeichen und die mobile Navigation öffnen. Hilfenamen
bereits im Seitenquelltext kontrollieren; Klick, Tastatur, AJAX und einen
zweiten Seitenaufruf mit gefülltem Cache ebenfalls prüfen.
