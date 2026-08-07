# DDBgo Helm-Chart für Kubernetes und OpenShift

Dieses Chart installiert DDBgo mit Redis und MariaDB in einem Kubernetes-
Namespace oder OpenShift-Projekt. Nicht privilegierte Container,
`RuntimeDefault`-Seccomp, entfernte Linux-Capabilities und keine fest
eingetragene UID funktionieren mit Kubernetes Pod Security und erlauben
OpenShift zugleich die Zuweisung einer projektspezifischen UID über die SCC.

`platform.type` wählt die plattformspezifischen Ressourcen. `openshift` erzeugt
eine Route sowie ImageStreams und Image-Change-Trigger. `kubernetes` erzeugt
stattdessen ein Standard-Ingress. Route und Ingress enthalten bewusst keine
TLS-Konfiguration; TLS/SSL endet am vorgeschalteten Reverse Proxy.

## Benennung

Die Namen werden aus dem Release-Namen gebildet:

| Ressource | Produktion | Test |
| --- | --- | --- |
| Release / Namespace | `ddbgo` | `ddbgo-t` |
| Drupal Deployment, Service, ConfigMap, Secret, PVC, ServiceAccount | `ddbgo-drupal` | `ddbgo-t-drupal` |
| Redis StatefulSet, Service, Secret, PVC, ServiceAccount | `ddbgo-redis` | `ddbgo-t-redis` |
| MariaDB StatefulSet, Service, Secret, PVC, ServiceAccount | `ddbgo-db` | `ddbgo-t-db` |
| ImageStreams für Drupal, Redis und MariaDB | wie die jeweilige Komponente | wie die jeweilige Komponente |
| VerticalPodAutoscaler für Drupal, Redis und MariaDB | wie die jeweilige Komponente | wie die jeweilige Komponente |
| optionales HTTP-Basic-Auth-Secret | `ddbgo-drupal-http-auth` | `ddbgo-t-drupal-http-auth` |
| Route oder Ingress (Name und Host) | `go.deutsche-digitale-bibliothek.de` | `go-t.deutsche-digitale-bibliothek.de` |

Drei getrennte ServiceAccounts statt eines gemeinsamen Accounts halten die
Workloads nach dem Least-Privilege-Prinzip voneinander getrennt. Es werden
keine Rollen oder RoleBindings benötigt.

## Installation

Voraussetzung ist Helm 3 sowie ein Zugriff auf den Zielcluster. Die
Standardwerte verwenden OpenShift.

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

Kubernetes-Produktion:

```sh
helm upgrade --install ddbgo ./helm/ddbgo \
  --namespace ddbgo \
  --create-namespace \
  --values ./helm/ddbgo/values-kubernetes.yaml \
  --atomic \
  --timeout 15m
```

Kubernetes-Testsystem:

```sh
helm upgrade --install ddbgo-t ./helm/ddbgo \
  --namespace ddbgo-t \
  --create-namespace \
  --values ./helm/ddbgo/values-test.yaml \
  --values ./helm/ddbgo/values-kubernetes.yaml \
  --atomic \
  --timeout 15m
```

Die zuletzt angegebene Datei aktiviert Kubernetes und deaktiviert die dort nicht
verfügbaren OpenShift-Image-Trigger. Falls der Cluster keine Standard-
IngressClass besitzt, muss beispielsweise
`--set-string drupal.ingress.className=nginx` ergänzt werden. Der Name hängt vom
installierten Ingress-Controller ab.

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

Das Chart verwendet diesen Wert für Route-/Ingress-Name, Host und
Trusted-Host-Regel.
Die Drupal-Probes sind davon unabhängig. `values-test.yaml` überschreibt nur
diesen Wert mit der Testdomain. Für temporäre Installationen kann auf dieselbe
Weise eine beliebige andere Domain gesetzt werden.

## Kontakt-Annotationen

Alle vom Chart erzeugten Kubernetes- und OpenShift-Ressourcen erhalten die
Einträge aus `commonAnnotations`. Standardmäßig sind die vereinbarten
Kontaktinformationen gesetzt:

```yaml
commonAnnotations:
  dnb.contact/emails: m.buechner@dnb.de
  dnb.contact/persons: Michael Büchner
```

Die Werte können in einer umgebungsspezifischen Values-Datei geändert oder um
weitere Annotationen ergänzt werden. Ein leerer String unterdrückt die jeweilige
Annotation. Die von Helm benötigten Owner- und `resource-policy`-Annotationen
sind reserviert und können hier nicht ersetzt werden.

## Schutz bestehender Ressourcen

`protection.failOnExistingResource=true` bewirkt:

- Eine Erstinstallation bricht ab, wenn eine der zu erzeugenden Ressourcen
  bereits vorhanden ist. Einzige Ausnahme sind behaltene PVCs und Secrets, die
  nachweislich demselben Release-Namen und Namespace gehören.
- Ein Upgrade ändert nur Ressourcen, deren Helm-Metadaten exakt zu diesem
  Release und Namespace gehören.
- Fremde oder lediglich namensgleiche PVCs und Secrets werden nicht übernommen.
  Sie müssen explizit über `existingClaim` beziehungsweise `existingSecret`
  referenziert werden.

Ein normales Upgrade aktualisiert weiterhin die Ressourcen, die bereits zu
diesem Release gehören. Ohne diese Ausnahme wären Helm-Upgrades nicht möglich.
Bei `helm uninstall` werden Deployment, StatefulSets, Services, Route oder
Ingress, ConfigMap, ServiceAccounts, gegebenenfalls ImageStreams und
VerticalPodAutoscaler entfernt. Die vom Chart erzeugten PVCs und Secrets tragen
`helm.sh/resource-policy: keep` und bleiben erhalten.
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
oc delete deployment,statefulset,service,route,ingress,configmap,serviceaccount,imagestream,verticalpodautoscaler \
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
| Redis | `password` |
| MariaDB | `database`, `username`, `password`, `root-password` |
| HTTP Basic Auth | `HTPASSWD_USER`, `HTPASSWD_PWD` |

Die Redis-ACL wird beim Podstart ausschließlich aus `password` erzeugt und in
ein flüchtiges `emptyDir` geschrieben. Ein eventuell aus einer älteren
Chart-Version vorhandener Secret-Schlüssel `users.acl` wird aus
Kompatibilitätsgründen toleriert, aber nicht mehr verwendet. Das Chart verändert
das behaltene Secret dabei nicht.

Vom Chart erzeugte Secrets werden zusammen mit den PVCs behalten. Bei einer
Neuinstallation desselben Releases übernimmt das Chart sie und verwendet ihre
vorhandenen Werte. Fehlt das MariaDB-Secret trotz vorhandenem Daten-PVC, bricht
das Chart ab, statt unbemerkt neue, zur Datenbank unpassende Zugangsdaten zu
erzeugen. Alternativ kann weiterhin ein extern verwaltetes `existingSecret`
verwendet werden.

## Ressourcenbedarf

Die Standardwerte sind für eine sparsame Einzelinstanz auf Kubernetes oder OpenShift
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

Die beiden kurzlebigen Abhängigkeitsprüfungen vor dem Drupal-Start verwenden
zusätzlich die sparsamen Werte unter `drupal.dependencyChecks.resources`. Da
Init-Container nacheinander laufen, werden deren Anforderungen nicht addiert.

## Vertical Pod Autoscaler

Das Chart erzeugt standardmäßig drei `VerticalPodAutoscaler`-Ressourcen:

| VPA | Ziel | Container |
| --- | --- | --- |
| `ddbgo[-t]-drupal` | `Deployment/ddbgo[-t]-drupal` | `drupal` |
| `ddbgo[-t]-redis` | `StatefulSet/ddbgo[-t]-redis` | `redis` |
| `ddbgo[-t]-db` | `StatefulSet/ddbgo[-t]-db` | `mariadb` |

MariaDB und Redis sind bewusst als `StatefulSet` referenziert. Der Wert
`containerName` bezeichnet den tatsächlichen Container im Pod und nicht den
Namen des Workload-Objekts. Die Standardkonfiguration berücksichtigt CPU und
Arbeitsspeicher mit `controlledValues: RequestsAndLimits`.

`updateMode: "Off"` ist ein reiner Beobachtungsmodus: Der VPA-Recommender füllt
Empfehlungen im Status der VPA-Ressource, verändert aber weder Pods noch deren
Requests oder Limits. Anzeigen lassen sich die Empfehlungen beispielsweise mit:

```sh
oc describe verticalpodautoscaler ddbgo-t-drupal -n ddbgo-t
```

Der VPA-Operator und die CRD `verticalpodautoscalers.autoscaling.k8s.io` müssen
im Cluster vorhanden sein. Ohne diese Cluster-Voraussetzung ist die Erzeugung
vor der Installation zu deaktivieren:

```yaml
verticalPodAutoscaler:
  enabled: false
```

Modus, kontrollierte Ressourcen und kontrollierte Werte stehen gemeinsam unter
`verticalPodAutoscaler` in `values.yaml`. Das Chart validiert die zulässigen
Werte und verlangt für dieses Setup exakt CPU und Arbeitsspeicher.

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
| `UPDATEDB_ON_STARTUP` | `drupal.env.UPDATEDB_ON_STARTUP`, Standard `no` | – |
| `CACHEREBUILD_ON_STARTUP` | `drupal.env.CACHEREBUILD_ON_STARTUP`, Standard `no` | – |
| `HTPASSWD_GREETING` | `drupal.env.HTPASSWD_GREETING`, Standard `<unset>` | – |
| `HTPASSWD_USER` | optionales oder externes HTTP-Auth-Secret, Standard `<unset>` | – |
| `HTPASSWD_PWD` | optionales oder externes HTTP-Auth-Secret, Standard `<unset>` | – |

Die öffentlichen und privaten Mount-Pfade werden aus denselben Values erzeugt,
die als `FILE_PUBLIC_PATH` und `FILE_PRIVATE_PATH` an Drupal übergeben werden.
Dadurch können ENV-Wert und Volume-Mount nicht mehr unbemerkt auseinanderlaufen.
Das Chart lehnt einen absoluten öffentlichen Pfad, einen privaten Pfad innerhalb
des Webroots sowie einen relativen privaten oder temporären Pfad ab.

`UPDATEDB_ON_STARTUP` und `CACHEREBUILD_ON_STARTUP` akzeptieren ausschließlich
`yes` oder `no`. Beide stehen standardmäßig auf `no`, weil sie bei `yes` bei
jedem Containerstart den Datenbankstand beziehungsweise Drupal-Cache verändern.

Die drei `HTPASSWD_*`-Variablen sind standardmäßig nicht gesetzt und HTTP Basic
Auth bleibt deaktiviert. Für eine direkte Konfiguration müssen Benutzername und
Passwort gemeinsam gesetzt werden:

```yaml
drupal:
  env:
    HTPASSWD_GREETING: "Geschütztes System"
    HTPASSWD_USER: "testsystem"
    HTPASSWD_PWD: "nicht-in-git-speichern"
```

Das Chart legt Benutzername und Passwort dann im behaltenen Secret
`<release>-drupal-http-auth` ab und referenziert es aus dem Drupal-Container.
Produktiv sollte das Passwort nicht in einer eingecheckten Values-Datei stehen.
Stattdessen kann ein extern verwaltetes Secret verwendet werden:

```yaml
drupal:
  basicAuth:
    existingSecret: ddbgo-drupal-http-auth
```

Dieses Secret muss die Schlüssel `HTPASSWD_USER` und `HTPASSWD_PWD` enthalten.
`HTPASSWD_GREETING` kann unabhängig davon in `drupal.env` gesetzt werden. Bleibt
es leer, wird die Variable nicht exportiert; das Container-Entrypoint verwendet
bei aktivierter Authentifizierung dann seinen eingebauten Begrüßungstext.

Die MariaDB-Variablen im Datenbank-Pod heißen dagegen `MARIADB_DATABASE`,
`MARIADB_USER`, `MARIADB_PASSWORD` und `MARIADB_ROOT_PASSWORD`, weil das
MariaDB-Image diese Namen erwartet. Drupal und MariaDB erhalten ihre Werte aus
demselben Secret.

`TRUSTED_HOST_PATTERNS` wird nicht als redundanter fertiger String gepflegt.
Das Chart leitet die Regeln aus `drupal.externalHost` und dem
Release-/Ressourcennamen ab. Literale Zusatzhosts können unter `additionalHosts`
ergänzt werden. Sämtliche Regex-Sonderzeichen werden automatisch maskiert. Für
Produktion ergibt sich standardmäßig:

```text
^localhost$, ^127\.0\.0\.1$, ^go\.deutsche-digitale-bibliothek\.de$, ^ddbgo-drupal$
```

Der vorgeschaltete Reverse Proxy muss den öffentlichen Host unverändert an den
OpenShift-Router beziehungsweise Kubernetes-Ingress-Controller senden. Für
Produktion sind insbesondere folgende Header erforderlich; im Testsystem wird
entsprechend die `go-t`-Domain verwendet:

```text
Host: go.deutsche-digitale-bibliothek.de
X-Forwarded-Host: go.deutsche-digitale-bibliothek.de
X-Forwarded-Proto: https
X-Forwarded-Port: 443
```

OpenShift-Router und Kubernetes-Ingress benötigen den öffentlichen `Host` zum
Matchen von Route beziehungsweise Ingress. Nginx übergibt diesen Host sowie das
externe Scheme und den externen Port an PHP-FPM. Drupal erzeugt dadurch
öffentliche URLs mit der HTTPS-Domain, obwohl die clusterinterne Weiterleitung
per HTTP erfolgt.

Bei mehreren Proxy-Stufen können Header als Listen ankommen, beispielsweise
`X-Forwarded-Proto: https, http` und `X-Forwarded-Port: 443, 80`. Nginx wertet
bewusst den ersten, ursprünglichen Proto-Wert aus und normalisiert die an
PHP-FPM übergebenen Werte auf `https` und `443`. Ein später angehängtes `http`
eines zwischengeschalteten Proxys überschreibt damit nicht die externe
Client-Sicht.

MariaDB erhält zusätzlich ein beschreibbares `emptyDir` unter `/run/mariadb`.
Das ist auf OpenShift erforderlich, weil die zufällig zugewiesene Container-UID
den Unix-Socket nicht in das schreibgeschützte Verzeichnis des Images legen
kann. Dieses Runtime-Volume enthält keine persistenten Daten.

Die globale Transaktionsisolation von MariaDB ist unter
`database.config.transactionIsolation` explizit auf `READ-COMMITTED` gesetzt.
Das StatefulSet übergibt dazu beim Serverstart
`--transaction-isolation=READ-COMMITTED`. Der Wert gilt als Standard für neu
aufgebaute Verbindungen; ein Helm-Upgrade rollt den MariaDB-Pod mit dieser
Einstellung neu aus.

## Zustandsprüfungen

Alle drei Workloads besitzen Startup-, Readiness- und Liveness-Probes:

- Drupal verwendet für alle drei Prüfungen den nicht gecachten HTTP-Endpunkt
  `/health` direkt auf Port 8080 des Pods. Die Probe sendet standardmäßig
  `localhost` als `Host`-Header, damit Drupals `trusted_host_patterns` die Anfrage
  akzeptiert. Route, öffentliches DNS und Reverse Proxy sind nicht beteiligt.
  Ist HTTP Basic Auth aktiviert, wechseln Startup-, Readiness- und
  Liveness-Probe automatisch auf einen lokalen `curl`-Aufruf mit den Secret-
  basierten Zugangsdaten. Dadurch wird weiterhin der echte Nginx-/Drupal-Endpunkt
  geprüft, ohne an der Authentifizierung mit HTTP 401 zu scheitern.
- Redis verlangt bei allen drei Probes die exakte authentifizierte Antwort
  `PONG`. Das Passwort erhält der Client über die Umgebungsvariable
  `REDISCLI_AUTH` aus dem Redis-Secret. Eine Redis-Fehlerantwort mit Exit-Code 0
  gilt dadurch nicht versehentlich als erfolgreiche Probe.
- MariaDB verwendet das mit dem offiziellen Image gelieferte `healthcheck.sh`.
  Startup und Readiness verlangen zusätzlich eine initialisierte InnoDB-Engine;
  Liveness prüft die Verbindung zum Server.

Pfad, lokaler Host-Header und Zeitwerte der Drupal-Probes sind unter
`drupal.probes` konfigurierbar. `httpGet.host` wird bewusst nicht gesetzt: Dieser
Wert würde das Netzwerkziel der Kubelet-Probe ändern; `hostHeader` ändert nur den
virtuellen HTTP-Host der direkten Pod-Anfrage.

Vor dem eigentlichen Drupal-Container laufen außerdem zwei Init-Container
nacheinander:

1. `wait-for-database` führt mit den Zugangsdaten aus dem MariaDB-Secret ein
   `SELECT 1` in der konfigurierten Datenbank aus.
2. `wait-for-redis` erwartet mit dem Passwort aus dem Redis-Secret ein
   authentifiziertes `PONG`. Bei `redis.enabled=false` entfällt dieser Check.

Solange eine Prüfung fehlschlägt, wartet sie und versucht es nach
`drupal.dependencyChecks.retryIntervalSeconds` erneut. Der Drupal-Container wird
erst nach beiden Erfolgen gestartet. Dadurch führen auch falsche Secrets oder
noch nicht initialisierte Dienste nicht zu einem vorzeitigen Anwendungsstart.
Der Redis-Check schreibt die empfangene Fehlerantwort ins Init-Container-Log,
aber niemals das verwendete Passwort.

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

Mit `platform.type=openshift` erzeugt das Chart standardmäßig für Drupal, Redis
und MariaDB jeweils einen OpenShift `ImageStream`. OpenShift importiert die
konfigurierten Tags regelmäßig.
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

Standard-Kubernetes besitzt weder ImageStreams noch äquivalente native
Image-Change-Trigger. `values-kubernetes.yaml` deaktiviert diese Funktionen und
setzt für alle drei beweglichen Tags `imagePullPolicy: Always`. Dadurch wird ein
neues Image bei einem Podstart geladen, aber ein neuer Registry-Digest löst
allein noch keinen Rollout aus. Dafür ist ein separater GitOps-, Deployment- oder
Image-Automation-Controller erforderlich.

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
