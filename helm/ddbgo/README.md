# DDBgo Helm-Chart für OpenShift

Dieses Chart installiert DDBgo mit Redis und MariaDB in einem OpenShift-
Namespace. Es verwendet eine OpenShift `Route`, nicht privilegierte Container,
`RuntimeDefault`-Seccomp, entfernte Linux-Capabilities und keine fest
eingetragene UID. Dadurch kann die OpenShift-SCC die projektspezifische UID
vergeben.

Die Route enthält bewusst keine TLS-Konfiguration. Sie wird clusterseitig über
HTTP angesprochen; TLS/SSL endet am vorgeschalteten Reverse Proxy.

## Benennung

Die Namen werden aus dem Release-Namen gebildet:

| Ressource | Produktion | Test |
| --- | --- | --- |
| Release / Namespace | `ddbgo` | `ddbgo-t` |
| Drupal Deployment, Service, ConfigMap, Secret, PVC, ServiceAccount | `ddbgo-drupal` | `ddbgo-t-drupal` |
| Redis StatefulSet, Service, Secret, PVC, ServiceAccount | `ddbgo-redis` | `ddbgo-t-redis` |
| MariaDB StatefulSet, Service, Secret, PVC, ServiceAccount | `ddbgo-db` | `ddbgo-t-db` |
| ImageStreams für Drupal, Redis und MariaDB | wie die jeweilige Komponente | wie die jeweilige Komponente |
| Route (Name und Host) | `go.deutsche-digitale-bibliothek.de` | `go-t.deutsche-digitale-bibliothek.de` |

Drei getrennte ServiceAccounts statt eines gemeinsamen Accounts halten die
Workloads nach dem Least-Privilege-Prinzip voneinander getrennt. Es werden
keine Rollen oder RoleBindings benötigt.

## Installation

Voraussetzungen sind Helm 3 und ein angemeldeter OpenShift-Client.

Produktion:

```sh
helm upgrade --install ddbgo ./helm/ddbgo \
  --namespace ddbgo \
  --create-namespace \
  --atomic \
  --timeout 15m
```

Test:

```sh
helm upgrade --install ddbgo-t ./helm/ddbgo \
  --namespace ddbgo-t \
  --create-namespace \
  --values ./helm/ddbgo/values-test.yaml \
  --atomic \
  --timeout 15m
```

Vor einer Installation kann der serverseitige Dry-Run Namenskollisionen
erkennen:

```sh
helm upgrade --install ddbgo ./helm/ddbgo \
  --namespace ddbgo \
  --dry-run=server \
  --debug
```

Das Anwendungs-Image verwendet standardmäßig den vom Projekt veröffentlichten
Tag `tagged`. Für ein reproduzierbares Deployment kann stattdessen ein konkreter
Release-Tag gesetzt und die automatische Aktualisierung deaktiviert werden:

```sh
helm upgrade --install ddbgo ./helm/ddbgo \
  --namespace ddbgo \
  --set-string drupal.image.tag=v1.2.3 \
  --set drupal.image.autoUpdate.enabled=false
```

Die öffentliche Domain wird pro Umgebung nur einmal gesetzt:

```yaml
drupal:
  externalHost: go.deutsche-digitale-bibliothek.de
```

Das Chart verwendet diesen Wert für Route-Name, Route-Host, Trusted-Host-Regel
und den Host-Header der Drupal-Probes. `values-test.yaml` überschreibt nur diesen
Wert mit der Testdomain. Für temporäre Installationen kann auf dieselbe Weise
eine beliebige andere Domain gesetzt werden.

## Schutz bestehender Ressourcen

`protection.failOnExistingResource=true` bewirkt:

- Eine Erstinstallation bricht ab, wenn eine der zu erzeugenden Ressourcen
  bereits vorhanden ist. Einzige Ausnahme sind behaltene PVCs, die nachweislich
  demselben Release-Namen und Namespace gehören.
- Ein Upgrade ändert nur Ressourcen, deren Helm-Metadaten exakt zu diesem
  Release und Namespace gehören.
- Fremde oder lediglich namensgleiche PVCs werden nicht übernommen. Sie müssen
  explizit über `existingClaim` referenziert werden.

Ein normales Upgrade aktualisiert weiterhin die Ressourcen, die bereits zu
diesem Release gehören. Ohne diese Ausnahme wären Helm-Upgrades nicht möglich.
Bei `helm uninstall` werden Deployment, StatefulSets, Services, Route,
ConfigMap, ServiceAccounts und ImageStreams entfernt. Die vom Chart erzeugten
PVCs und Secrets tragen `helm.sh/resource-policy: keep` und bleiben erhalten.
Die PVC-Aufbewahrung lässt sich je Komponente mit `persistence.retain=false`
abschalten; Secrets werden zum Schutz persistenter Zugangsdaten immer behalten.

Eine spätere Installation mit identischem Release-Namen und Namespace übernimmt
die behaltenen PVCs und Secrets automatisch, sofern deren Helm-Owner-Annotationen
noch unverändert vorhanden sind. Vorhandene Secret-Daten werden wiederverwendet,
statt neue Zufallswerte zu erzeugen.

Bestehende Installationen einer älteren Chart-Version müssen vor dem Uninstall
zuerst auf diese Chart-Version aktualisiert werden. Erst das Upgrade entfernt
die früher global gesetzte `keep`-Annotation von den nicht persistenten
Ressourcen.

Wurde die alte Version bereits deinstalliert, sind die behaltenen Ressourcen
nicht mehr Teil eines Helm-Releases. Die Nicht-PVC-Ressourcen können dann anhand
des Release-Labels gezielt entfernt werden, beispielsweise für Test:

```sh
oc delete deployment,statefulset,service,route,configmap,serviceaccount,imagestream \
  --selector app.kubernetes.io/instance=ddbgo-t \
  --namespace ddbgo-t
```

Die Ressourcentypen `persistentvolumeclaim` und `secret` sind absichtlich nicht
Teil dieses Befehls.

Bestehende Secrets und PVCs können referenziert werden. Dazu `create=false` und
den vorhandenen Namen setzen, zum Beispiel:

```yaml
drupal:
  secret:
    create: false
    existingSecret: ddbgo-drupal
  persistence:
    create: false
    existingClaim: ddbgo-drupal
```

Erwartete Schlüssel in vorhandenen Secrets:

| Secret | Schlüssel |
| --- | --- |
| Drupal | `hash-salt` |
| Redis | `password`, `users.acl` |
| MariaDB | `database`, `username`, `password`, `root-password` |

`users.acl` muss eine gültige Redis-ACL enthalten, deren Passwort mit
`password` übereinstimmt, beispielsweise
`user default on >GEHEIM ~* &* +@all`.

Vom Chart erzeugte Secrets werden zusammen mit den PVCs behalten. Bei einer
Neuinstallation desselben Releases übernimmt das Chart sie und verwendet ihre
vorhandenen Werte. Fehlt das MariaDB-Secret trotz vorhandenem Daten-PVC, bricht
das Chart ab, statt unbemerkt neue, zur Datenbank unpassende Zugangsdaten zu
erzeugen. Alternativ kann weiterhin ein extern verwaltetes `existingSecret`
verwendet werden.

## Ressourcenbedarf

Die Standardwerte sind für eine sparsame Einzelinstanz auf OpenShift
ausgelegt:

| Komponente | CPU-Request | CPU-Limit | Speicher-Request | Speicher-Limit |
| --- | ---: | ---: | ---: | ---: |
| Drupal | `50m` | `500m` | `128Mi` | `512Mi` |
| Redis | `25m` | `250m` | `64Mi` | `256Mi` |
| MariaDB | `100m` | `1` | `256Mi` | `1Gi` |

Für Produktion sollten diese Werte nach Messung der tatsächlichen Last
angepasst werden. Insbesondere Drupal und MariaDB können bei Importen,
Cache-Neuaufbau oder Datenbankmigrationen vorübergehend mehr Speicher benötigen.
Alle Werte lassen sich unter `drupal.resources`, `redis.resources` und
`database.resources` überschreiben.

## Verzeichnisstruktur der PVCs

Jeder Anwendungscontainer bindet ausschließlich einen Unterpfad innerhalb
seines PVCs ein. Im Wurzelverzeichnis legt das Chart nur den Ordner `data` an:

```text
<pvc-root>/
└── data/
```

Redis und MariaDB schreiben direkt in ihren jeweiligen Ordner `data`. Beim
Drupal-PVC liegen die öffentlichen und privaten Dateien getrennt unter
`data/public` und `data/private`. Init-Container legen die benötigten
Verzeichnisse vor dem Anwendungsstart an; die Hauptcontainer verwenden dafür
Kubernetes-`subPath`-Mounts.

Bei einem bereits befüllten PVC werden vorhandene Dateien im Wurzelverzeichnis
nicht automatisch verschoben. Vor dem Upgrade müssen die Workloads gestoppt,
die Daten gesichert und kontrolliert nach `data` beziehungsweise bei Drupal
nach `data/public` und `data/private` migriert werden. Andernfalls starten die
Anwendungen mit einem zunächst leeren Unterverzeichnis.

## Anwendungsvariablen und Containerpfade

Das DDBgo-Image enthält das Projekt unter `/var/www/html` und verwendet
`/var/www/html/web` als Drupal-Webroot. Drupal erwartet den öffentlichen
Dateipfad relativ zu diesem Webroot, den privaten Dateipfad dagegen absolut
und außerhalb des Webroots. Die Standardkonfiguration ergibt daher:

| Variable | Wert beziehungsweise Quelle | Zugehöriger Mount |
| --- | --- | --- |
| `MYSQL_DATABASE` | Schlüssel `database` im MariaDB-Secret | – |
| `MYSQL_USER` | Schlüssel `username` im MariaDB-Secret | – |
| `MYSQL_PASSWORD` | Schlüssel `password` im MariaDB-Secret | – |
| `MYSQL_HOSTNAME` | MariaDB-Service `ddbgo[-t]-db` | – |
| `MYSQL_PORT` | `3306` | – |
| `HASH_SALT` | Schlüssel `hash-salt` im Drupal-Secret | – |
| `UPDATE_FREE_ACCESS` | `false` | – |
| `FILE_PUBLIC_PATH` | `sites/default/files` | `/var/www/html/web/sites/default/files` → PVC `data/public` |
| `FILE_PRIVATE_PATH` | `/var/www/html/private` | `/var/www/html/private` → PVC `data/private` |
| `TMP` | `/tmp` | `emptyDir` unter `/tmp` |
| `TRUSTED_HOST_PATTERNS` | automatisch erzeugte Regex für localhost, `drupal.externalHost` und Drupal-Service | – |
| `USE_REDIS` | aus `redis.enabled` | – |
| `REDIS_HOST` | Redis-Service `ddbgo[-t]-redis` | – |
| `REDIS_PORT` | `6379` | – |
| `REDIS_PASSWORD` | Schlüssel `password` im Redis-Secret | – |
| `DRUSH_OPTIONS_URI` | `http://localhost:8080`; lokaler Drush-Request-Kontext | – |
| `UPDATEDB_ON_STARTUP` | standardmäßig `no` | – |
| `CACHEREBUILD_ON_STARTUP` | standardmäßig `no` | – |

Die öffentlichen und privaten Mount-Pfade werden aus denselben Values erzeugt,
die als `FILE_PUBLIC_PATH` und `FILE_PRIVATE_PATH` an Drupal übergeben werden.
Dadurch können ENV-Wert und Volume-Mount nicht mehr unbemerkt auseinanderlaufen.
Das Chart lehnt einen absoluten öffentlichen Pfad, einen privaten Pfad innerhalb
des Webroots sowie einen relativen privaten oder temporären Pfad ab.

Die MariaDB-Variablen im Datenbank-Pod heißen dagegen `MARIADB_DATABASE`,
`MARIADB_USER`, `MARIADB_PASSWORD` und `MARIADB_ROOT_PASSWORD`, weil das
MariaDB-Image diese Namen erwartet. Drupal und MariaDB erhalten ihre Werte aus
demselben Secret. Optionale HTTP-Basic-Auth-Variablen des DDBgo-Entrypoints
werden vom Chart nicht gesetzt; ohne diese Variablen bleibt Basic Auth
deaktiviert.

`TRUSTED_HOST_PATTERNS` wird nicht als redundanter fertiger String gepflegt.
Das Chart leitet die Regeln aus `drupal.externalHost` und dem
Release-/Ressourcennamen ab. Literale Zusatzhosts können unter `additionalHosts`
ergänzt werden. Sämtliche Regex-Sonderzeichen werden automatisch maskiert. Für
Produktion ergibt sich standardmäßig:

```text
^localhost$, ^127\.0\.0\.1$, ^go\.deutsche-digitale-bibliothek\.de$, ^ddbgo-drupal$
```

Der vorgeschaltete Reverse Proxy muss den öffentlichen Host unverändert an den
OpenShift-Router senden. Für Produktion sind insbesondere folgende Header
erforderlich; im Testsystem wird entsprechend die `go-t`-Domain verwendet:

```text
Host: go.deutsche-digitale-bibliothek.de
X-Forwarded-Host: go.deutsche-digitale-bibliothek.de
X-Forwarded-Proto: https
X-Forwarded-Port: 443
```

Der OpenShift-Router benötigt den öffentlichen `Host` zum Matchen der Route.
Nginx übergibt diesen Host sowie das externe Scheme und den externen Port an
PHP-FPM. Drupal erzeugt dadurch öffentliche URLs mit der HTTPS-Domain, obwohl
Route und Pod intern per HTTP kommunizieren.

Bei mehreren Proxy-Stufen können Header als Listen ankommen, beispielsweise
`X-Forwarded-Proto: https, http` und `X-Forwarded-Port: 443, 80`. Nginx wertet
bewusst den ersten, ursprünglichen Proto-Wert aus und normalisiert die an
PHP-FPM übergebenen Werte auf `https` und `443`. Ein später angehängtes `http`
des OpenShift-Routers überschreibt damit nicht die externe Client-Sicht.

MariaDB erhält zusätzlich ein beschreibbares `emptyDir` unter `/run/mariadb`.
Das ist auf OpenShift erforderlich, weil die zufällig zugewiesene Container-UID
den Unix-Socket nicht in das schreibgeschützte Verzeichnis des Images legen
kann. Dieses Runtime-Volume enthält keine persistenten Daten.

## Zustandsprüfungen

Alle drei Workloads besitzen Startup-, Readiness- und Liveness-Probes:

- Drupal verwendet für alle drei Prüfungen den nicht gecachten HTTP-Endpunkt
  `/health` auf Port 8080. Die Probe sendet `drupal.externalHost` als
  `Host`-Header, damit Drupals `trusted_host_patterns` die Anfrage akzeptiert.
- Redis führt `redis-cli ping` aus. Das Passwort erhält der Client über die
  Umgebungsvariable `REDISCLI_AUTH` aus dem Redis-Secret.
- MariaDB verwendet das mit dem offiziellen Image gelieferte `healthcheck.sh`.
  Startup und Readiness verlangen zusätzlich eine initialisierte InnoDB-Engine;
  Liveness prüft die Verbindung zum Server.

Pfad und Zeitwerte der Drupal-Probes sind unter `drupal.probes` konfigurierbar.

## Passwörter und Persistenz

Bleiben Passwortwerte leer, erzeugt das Chart sie bei der ersten Installation
zufällig. Die erzeugten Secrets sind unveränderlich und werden bei Upgrades
weiterverwendet. Für zentrale Secret-Verwaltung sollten stattdessen vorhandene
Secrets referenziert werden.

Drupal verwendet einen PVC für öffentliche und private Dateien. Ein
Init-Container legt darin getrennte Verzeichnisse an. Redis aktiviert AOF;
MariaDB und Redis verwenden jeweils einen eigenen PVC. Die Standardgrößen und
StorageClasses können in `values.yaml` angepasst werden.

Alle drei PVCs fordern standardmäßig `ReadWriteMany` (RWX) an. Die ausgewählte
StorageClass muss Shared Access unterstützen; andernfalls bleibt der jeweilige
PVC im Status `Pending`. Eine geeignete StorageClass kann pro Komponente über
`persistence.storageClass` gesetzt werden. Das Chart validiert, dass
`persistence.accessModes` für jede aktivierte Komponente `ReadWriteMany`
enthält.

Ein bereits gebundener RWO-PVC kann nicht durch ein Helm-Upgrade in RWX
umgewandelt werden. Erkennt das Chart einen vorhandenen PVC ohne
`ReadWriteMany`, bricht es mit einer verständlichen Fehlermeldung ab. Die Daten
müssen dann kontrolliert in einen neuen PVC einer RWX-fähigen StorageClass
migriert werden; das Chart löscht oder verändert den alten PVC nicht automatisch.

## Versionslinien

- Drupal Core ist im Projekt-Lockfile auf `11.4.5` festgelegt.
- Redis verwendet den Tag `docker.io/library/redis:8.2-alpine`; die 8.2-Linie ist GA und bis
  1. September 2030 unterstützt.
- MariaDB verwendet `docker.io/library/mariadb:11.8-ubi9`; MariaDB 11.8 ist eine
  LTS-Linie und das UBI-Image ist für OpenShift geeignet.

Beide Tags bleiben innerhalb ihrer jeweiligen LTS-Linie beweglich und erhalten
dadurch Patch- und Sicherheitsupdates ohne Änderung an `values.yaml`.
Unbeschränkte Tags wie `latest`, `8`, `11` oder `lts`, die auf eine andere
Versionslinie wechseln können, werden nicht verwendet.

## Automatische Image-Aktualisierung

Das Chart erzeugt standardmäßig für Drupal, Redis und MariaDB jeweils einen
OpenShift `ImageStream`. OpenShift importiert die konfigurierten Tags regelmäßig.
Ändert sich der Digest eines Tags, aktualisiert ein Image-Change-Trigger das
zugehörige Deployment beziehungsweise StatefulSet. Dadurch wird automatisch ein
Rollout mit dem neuen Image gestartet. Bei Redis und MariaDB kann der Registry-Tag
nur innerhalb der ausgewählten LTS-Linie auf einen neuen Patchstand zeigen.

Die Aktualisierung ist für jede Komponente unabhängig konfigurierbar:

```yaml
drupal:
  image:
    autoUpdate:
      enabled: true
      scheduledImport: true

redis:
  image:
    autoUpdate:
      enabled: true
      scheduledImport: true

database:
  image:
    autoUpdate:
      enabled: true
      scheduledImport: true
```

- `enabled: false` entfernt ImageStream und Trigger der Komponente aus der
  Chart-Verwaltung. Der ImageStream wird beim Upgrade entfernt; der zugehörige
  PVC bleibt davon unberührt.
- `scheduledImport: false` behält ImageStream und Trigger bei, beendet aber die
  regelmäßige Prüfung der externen Registry. Ein Import kann dann kontrolliert
  mit `oc import-image` angestoßen werden.
- `imagePullPolicy: Always` stellt zusätzlich sicher, dass jeder neu erzeugte
  Pod den aktuell referenzierten Digest aus der Registry abruft.

Status und zuletzt importierte Images lassen sich beispielsweise so prüfen:

```sh
oc get imagestream -n ddbgo-t
oc describe imagestream ddbgo-t-redis -n ddbgo-t
oc rollout status statefulset/ddbgo-t-redis -n ddbgo-t
```

Der OpenShift-Import reagiert auf eine Änderung des Image-Digests. Er führt
keine eigenen Anwendungstests durch. Für MariaDB sollte deshalb weiterhin ein
Backup- und Wiederherstellungsverfahren vorhanden sein.

## Veröffentlichung über GitHub

Der vollständige operative Ablauf einschließlich Auswahl, Installation,
Abnahme und Rollback konkreter Test- und Produktionsversionen steht im
[`Helm-Release- und Deployment-Workflow`](../../docs/helm-release-workflow.md).

Die Action [`.github/workflows/helm-publish.yml`](../../.github/workflows/helm-publish.yml)
veröffentlicht das Chart als OCI-Artefakt unter:

```text
oci://ghcr.io/mbuechner/helm/ddbgo
```

Sie läuft bei Chart-Änderungen sowohl auf `test` als auch auf `master`. Beide
Branches verwenden dieselbe Basisversion aus `Chart.yaml`; die veröffentlichte
Version wird abhängig vom Branch gebildet:

| Branch | Kanal | Beispielversion | Zielrelease |
| --- | --- | --- | --- |
| `test` | Test-Prerelease | `0.1.0-test.123.1.shaabc1234` | `ddbgo-t` in `ddbgo-t` |
| `master` | Produktion | `0.1.0` | `ddbgo` in `ddbgo` |

Die Testversion enthält Workflow-Lauf, Versuch und Commit-ID. Sie ist dadurch
eindeutig und kann eine vorhandene Version nicht überschreiben. Beim Merge von
`test` nach `master` bleibt `Chart.yaml` unverändert; aus derselben Basisversion
wird auf `master` die stabile Produktionsversion.

Der typische Ablauf für die nächste Version ist:

1. Auf `test` die Basisversion in `Chart.yaml` auf beispielsweise `0.2.0`
   erhöhen.
2. Änderungen auf `test` veröffentlichen und mit Versionen wie
   `0.2.0-test.…` im Testsystem prüfen.
3. `test` ohne zusätzliche Versionsänderung nach `master` mergen.
4. Die Action veröffentlicht auf `master` genau `0.2.0` für Produktion.

Eine bereits veröffentlichte Version wird nicht überschrieben. Weitere
Änderungen nach einer Produktionsveröffentlichung erfordern deshalb die nächste
Basisversion. Die Action kann auch unter
**Actions → Publish Helm chart → Run workflow** gestartet werden; dabei muss als
Branch `test` oder `master` ausgewählt sein.

`GITHUB_TOKEN` genügt zum Veröffentlichen. Das Paket erscheint unter
**Packages**. GitHub legt neue Pakete abhängig von den Account-Einstellungen
möglicherweise zunächst als privat an. Für anonymen Abruf muss seine Sichtbarkeit
einmalig auf **Public** gestellt werden.

### GitHub Pages für den OpenShift Developer Catalog

Zusätzlich zu GHCR pflegt dieselbe Action zwei klassische HTTP-Helm-Repositories
auf dem Branch `gh-pages`:

| Namespace | Repository-URL | Inhalt |
| --- | --- | --- |
| `ddbgo-t` | `https://mbuechner.github.io/ddbgo/helm/test/` | Test-Prereleases aus `test` |
| `ddbgo` | `https://mbuechner.github.io/ddbgo/helm/stable/` | stabile Versionen aus `master` |

Jeder Kanal besitzt eine eigene `index.yaml`. Bereits vorhandene Chartarchive
werden vor dem Veröffentlichen inhaltlich verglichen und niemals überschrieben.
Die Action verwendet keinen Force-Push und wird über `concurrency` serialisiert,
damit Test- und Produktionspublikationen den Pages-Branch nicht gleichzeitig
ändern.

Einmalige GitHub-Konfiguration:

1. Den Workflow zunächst auf `test` ausführen, damit der Branch `gh-pages`
   angelegt wird.
2. Unter **Settings → Actions → General → Workflow permissions** die Option
   **Read and write permissions** erlauben, sofern eine Organisationsrichtlinie
   Schreibzugriff nicht bereits zulässt.
3. Unter **Settings → Pages** als Quelle **Deploy from a branch**, Branch
   `gh-pages` und Verzeichnis `/ (root)` auswählen.
4. Prüfen, ob
   `https://mbuechner.github.io/ddbgo/helm/test/index.yaml` erreichbar ist.

Repository für das Testprojekt:

```yaml
apiVersion: helm.openshift.io/v1beta1
kind: ProjectHelmChartRepository
metadata:
  name: ddbgo
  namespace: ddbgo-t
spec:
  name: DDBgo Test
  description: Testversionen des DDBgo Helm-Charts
  connectionConfig:
    url: https://mbuechner.github.io/ddbgo/helm/test/
```

Repository für Produktion:

```yaml
apiVersion: helm.openshift.io/v1beta1
kind: ProjectHelmChartRepository
metadata:
  name: ddbgo
  namespace: ddbgo
spec:
  name: DDBgo
  description: Stabile Versionen des DDBgo Helm-Charts
  connectionConfig:
    url: https://mbuechner.github.io/ddbgo/helm/stable/
```

Nach dem Anlegen erscheinen die Charts im jeweiligen OpenShift-Projekt im
Developer Catalog:

```sh
oc apply -f ddbgo-test-repository.yaml
oc apply -f ddbgo-production-repository.yaml

oc get projecthelmchartrepositories -n ddbgo-t
oc get projecthelmchartrepositories -n ddbgo
```

### Installation aus GHCR

Die genaue erzeugte Version steht in der Zusammenfassung des jeweiligen
Action-Laufs. Ein öffentliches Produktionschart wird so installiert:

```sh
helm upgrade --install ddbgo \
  oci://ghcr.io/mbuechner/helm/ddbgo \
  --version 0.1.0 \
  --namespace ddbgo \
  --create-namespace \
  --atomic \
  --timeout 15m
```

Für das Testsystem wird die konkrete Prerelease-Version zusammen mit dem
Testprofil verwendet:

```sh
helm upgrade --install ddbgo-t \
  oci://ghcr.io/mbuechner/helm/ddbgo \
  --version 0.1.0-test.123.1.shaabc1234 \
  --namespace ddbgo-t \
  --create-namespace \
  --values ./helm/ddbgo/values-test.yaml \
  --atomic \
  --timeout 15m
```

Das Testprofil setzt den Drupal-Container auf den beweglichen Tag `test` und
`imagePullPolicy: Always`. Die Chart-Version steht zusätzlich als Annotation im
Pod-Template, sodass jede neue Testchart-Version einen Drupal-Rollout auslöst.
Produktion verwendet weiterhin den freigegebenen Tag `tagged`, sofern kein
konkreter Tag oder Digest übergeben wird.

Bei einem privaten Paket ist zuerst eine Anmeldung mit einem klassischen GitHub
Personal Access Token mit `read:packages` notwendig:

```sh
export CR_PAT='GITHUB_TOKEN'
printf '%s' "$CR_PAT" | helm registry login ghcr.io \
  --username GITHUB_BENUTZERNAME \
  --password-stdin
```

Metadaten lassen sich vorab prüfen:

```sh
helm show chart oci://ghcr.io/mbuechner/helm/ddbgo --version 0.1.0
helm show values oci://ghcr.io/mbuechner/helm/ddbgo --version 0.1.0
```

OCI-Repositories werden nicht mit `helm repo add` registriert. Der vollständige
`oci://`-Pfad und die gewünschte Version werden direkt an `helm install`,
`helm upgrade`, `helm pull` oder `helm show` übergeben.

Die Publish-Action installiert nicht selbst auf einem OpenShift-Cluster, da
dafür bewusst noch keine Cluster-Zugangsdaten im Repository definiert sind. Eine
separate Deployment-Action kann darauf aufbauend über geschützte GitHub-
Environments für `test` und `production` ergänzt werden.
