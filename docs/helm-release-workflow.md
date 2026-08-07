# Helm-Release- und Deployment-Workflow

Dieses Runbook beschreibt, wie DDBgo-Helm-Charts veröffentlicht, getestet und
gezielt nach OpenShift übernommen werden. Die Veröffentlichung und die
Installation sind bewusst getrennt:

- Die GitHub Action veröffentlicht unveränderliche Chartversionen.
- Ein Operator wählt anschließend eine konkrete Version für das jeweilige
  OpenShift-System aus.
- Es gibt kein automatisches Deployment auf einen Cluster und damit auch keine
  im Repository hinterlegten Cluster-Zugangsdaten.

## Systeme und Veröffentlichungskanäle

| Verwendung | Git-Branch | Chartversion | Helm-Repository | Release / Namespace |
| --- | --- | --- | --- | --- |
| Test | `test` | `<Basis>-test.<Lauf>.<Versuch>.sha<Commit>` | `https://mbuechner.github.io/ddbgo/helm/test/` | `ddbgo-t` / `ddbgo-t` |
| Produktion | `master` | `<Basis>` | `https://mbuechner.github.io/ddbgo/helm/stable/` | `ddbgo` / `ddbgo` |

Beide Kanäle werden außerdem unverändert als OCI-Artefakte unter
`oci://ghcr.io/mbuechner/helm/ddbgo` veröffentlicht.

Die Basisversion steht in `helm/ddbgo/Chart.yaml` und muss eine stabile
SemVer-Version im Format `MAJOR.MINOR.PATCH` sein, beispielsweise `0.2.0`.

## Einmalige Einrichtung

### GitHub Pages

Nach dem ersten erfolgreichen Lauf der Action auf `test`:

1. Unter **Settings → Actions → General → Workflow permissions** muss
   **Read and write permissions** erlaubt sein.
2. Unter **Settings → Pages** als Quelle **Deploy from a branch** auswählen.
3. Den Branch `gh-pages` und das Verzeichnis `/ (root)` auswählen.
4. Folgende URL muss anschließend erreichbar sein:

   ```text
   https://mbuechner.github.io/ddbgo/helm/test/index.yaml
   ```

### OpenShift-Repositories

Im Testprojekt wird ausschließlich der Testkanal registriert:

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

Im Produktionsprojekt wird ausschließlich der stabile Kanal registriert:

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

Nach dem Anwenden kann die Registrierung geprüft werden:

```sh
oc get projecthelmchartrepositories -n ddbgo-t
oc get projecthelmchartrepositories -n ddbgo
```

## Vollständiger Release-Ablauf

### 1. Nächste Basisversion auf `test` festlegen

Bevor Arbeiten an einer neuen Chartversion beginnen, wird ausschließlich auf
`test` die nächste Basisversion in `helm/ddbgo/Chart.yaml` eingetragen:

```yaml
version: 0.2.0
```

Nach einer bereits veröffentlichten Produktionsversion darf deren Basisversion
nicht wiederverwendet werden. Hat `master` bereits `0.1.0` veröffentlicht, muss
die nächste Entwicklung also mindestens mit `0.1.1` beginnen.

### 2. Testversion veröffentlichen

Jeder Push auf `test`, der `helm/ddbgo/**` oder den Publish-Workflow selbst
ändert, startet `.github/workflows/helm-publish.yml`. Aus `0.2.0` entsteht
beispielsweise:

```text
0.2.0-test.184.1.shad41a6cc
```

Die Action:

1. lintet und rendert Produktions- und Testkonfiguration;
2. erzeugt die eindeutige Prerelease-Version;
3. veröffentlicht sie in GHCR;
4. legt das unveränderte `.tgz` im Pages-Testrepository ab;
5. aktualisiert `helm/test/index.yaml`;
6. zeigt Version und Installationsbefehl in der Action-Zusammenfassung an.

Eine vorhandene Versionsdatei wird nie ersetzt. Bei einem erneuten Lauf wird
der Inhalt verglichen: Identischer Inhalt bleibt unverändert, abweichender
Inhalt führt zum Abbruch.

### 3. Konkrete Testversion auswählen

Die genaue Version steht an drei Stellen:

- in der Zusammenfassung des GitHub-Action-Laufs;
- unter GitHub **Packages**;
- im Testrepository:

  ```sh
  helm repo add ddbgo-test https://mbuechner.github.io/ddbgo/helm/test/
  helm repo update ddbgo-test
  helm search repo ddbgo-test/ddbgo --versions --devel
  ```

Die Versionsnummer muss für die Installation vollständig übernommen werden.
Es sollte nie implizit „die neueste“ Testversion installiert werden.

### 4. Konkrete Testversion in `ddbgo-t` installieren

#### Über die OpenShift-Weboberfläche

1. In die Developer-Perspektive wechseln und das Projekt `ddbgo-t` auswählen.
2. **+Add → Helm Chart** öffnen.
3. Im Repository **DDBgo Test** das Chart **DDBgo** auswählen.
4. Unter **Chart Version** exakt die geprüfte Prerelease-Version auswählen.
5. Als Release-Namen `ddbgo-t` verwenden.
6. In der YAML-Ansicht mindestens die Testwerte eintragen:

   ```yaml
   drupal:
     externalHost: go-t.deutsche-digitale-bibliothek.de
     image:
       tag: test
       pullPolicy: Always
   ```

7. Die angezeigten Änderungen prüfen und die Release-Erstellung beziehungsweise
   das Upgrade bestätigen.

Das Testprofil ist nicht automatisch das Standardprofil des Charts. In der CLI
wird deshalb `values-test.yaml` angegeben; in der Weboberfläche müssen die
Testwerte in der YAML-Ansicht gesetzt werden.

Die OpenShift-Route enthält keinen TLS-Block. Der vorgeschaltete Reverse Proxy
terminiert HTTPS und leitet intern per HTTP an die Route weiter.
`TRUSTED_HOST_PATTERNS`, Route-Name, Route-Host und Probe-Host werden automatisch
aus `drupal.externalHost` und dem Drupal-Service-Namen erzeugt. Der Reverse Proxy
muss `Host` und `X-Forwarded-Host` auf die öffentliche `go-t`-Domain sowie
`X-Forwarded-Proto` auf `https` und `X-Forwarded-Port` auf `443` setzen.

#### Über die CLI

```sh
TEST_CHART_VERSION='0.2.0-test.184.1.shad41a6cc'

helm repo add ddbgo-test \
  https://mbuechner.github.io/ddbgo/helm/test/ \
  --force-update
helm repo update ddbgo-test

TEST_CHART_DIRECTORY="$(mktemp -d)"
helm pull ddbgo-test/ddbgo \
  --version "$TEST_CHART_VERSION" \
  --untar \
  --untardir "$TEST_CHART_DIRECTORY"

helm upgrade --install ddbgo-t "$TEST_CHART_DIRECTORY/ddbgo" \
  --namespace ddbgo-t \
  --values "$TEST_CHART_DIRECTORY/ddbgo/values-test.yaml" \
  --atomic \
  --timeout 15m
```

Dadurch stammen das Chart und `values-test.yaml` garantiert aus derselben
veröffentlichten Version. Nach dem Deployment kann das temporäre Verzeichnis
entfernt werden.

Der Namespace sollte vorab existieren. Falls der ausführende Benutzer das Recht
dazu besitzt, kann einmalig `oc new-project ddbgo-t` verwendet werden.

### 5. Testversion verifizieren und abnehmen

```sh
helm status ddbgo-t -n ddbgo-t
helm list -n ddbgo-t
oc get deployment,statefulset,pod,service,route,pvc -n ddbgo-t
oc rollout status deployment/ddbgo-t-drupal -n ddbgo-t --timeout=10m
```

Die tatsächlich installierte Chartversion steht in `helm list` in der Spalte
`CHART`. Zusätzlich sollte die Anwendung unter
`https://go-t.deutsche-digitale-bibliothek.de` fachlich geprüft werden.

Das Testchart verwendet den beweglichen Container-Tag `test`. Eine neue
Chartversion erzwingt einen Drupal-Rollout und `imagePullPolicy: Always` lädt den
aktuellen Testcontainer. Die Chartversion allein identifiziert deshalb nicht
den Containerdigest; für eine vollständige Freigabedokumentation sollte auch
der getestete Image-Digest notiert werden.

### 6. `test` unverändert nach `master` mergen

Nach der Abnahme wird `test` nach `master` gemergt. Direkt vor oder nach dem
Merge darf keine branch-spezifische Änderung an `Chart.yaml` vorgenommen werden.
Die dort stehende Basisversion bleibt beispielsweise `0.2.0`.

Weil Test-Prereleases nur von der Action abgeleitet werden, enthält der Branch
selbst keinen Suffix. Dadurch lässt sich `test` ohne Versionskonflikt nach
`master` mergen.

### 7. Produktionsversion veröffentlichen

Der Push beziehungsweise Merge auf `master` startet dieselbe Action. Für
`master` wird kein Prerelease-Suffix ergänzt:

```text
Basisversion auf test:       0.2.0
veröffentlichte Testversion: 0.2.0-test.184.1.shad41a6cc
veröffentlichte Produktion:  0.2.0
```

Die stabile Version wird nach GHCR und nach
`https://mbuechner.github.io/ddbgo/helm/stable/` veröffentlicht. Sollte `0.2.0`
bereits mit anderem Inhalt existieren, bricht der Workflow ab. In diesem Fall
muss auf `test` eine neue Basisversion vergeben und erneut gemergt werden.

Die Veröffentlichung installiert noch nichts im Produktionscluster.

### 8. Konkrete Produktionsversion in `ddbgo` installieren

#### Über die OpenShift-Weboberfläche

1. Das Projekt `ddbgo` auswählen.
2. **+Add → Helm Chart → DDBgo** aus dem Repository **DDBgo** öffnen.
3. Unter **Chart Version** exakt die freigegebene stabile Version auswählen.
4. Den Release-Namen `ddbgo` verwenden.
5. Die Produktionswerte prüfen. Für Host und Route gelten bereits die
   Produktionsvorgaben aus `values.yaml`.
6. Erstellung oder Upgrade bestätigen.

#### Über die CLI

```sh
PRODUCTION_CHART_VERSION='0.2.0'

helm repo add ddbgo-stable \
  https://mbuechner.github.io/ddbgo/helm/stable/ \
  --force-update
helm repo update ddbgo-stable

helm upgrade --install ddbgo ddbgo-stable/ddbgo \
  --version "$PRODUCTION_CHART_VERSION" \
  --namespace ddbgo \
  --atomic \
  --timeout 15m
```

Für reproduzierbare Produktion sollte außerdem ein konkreter, veröffentlichter
Anwendungs-Image-Tag oder Digest gesetzt werden. Ohne Override verwendet das
Chart den beweglichen Tag `tagged`:

```sh
helm upgrade --install ddbgo ddbgo-stable/ddbgo \
  --version "$PRODUCTION_CHART_VERSION" \
  --namespace ddbgo \
  --set-string drupal.image.tag=v11.4.5 \
  --atomic \
  --timeout 15m
```

`v11.4.5` ist hier nur ein Beispiel. Der angegebene Image-Tag muss zuvor als
Git-Tag auf `master` angelegt und durch den Docker-Release-Workflow
veröffentlicht worden sein.

### 9. Produktion verifizieren

```sh
helm status ddbgo -n ddbgo
helm list -n ddbgo
oc get deployment,statefulset,pod,service,route,pvc -n ddbgo
oc rollout status deployment/ddbgo-drupal -n ddbgo --timeout=10m
```

Danach muss die Anwendung unter
`https://go.deutsche-digitale-bibliothek.de` fachlich geprüft werden.

## Upgrade und Rollback

Eine andere Chartversion wird immer als Upgrade desselben Release-Namens
installiert. Es wird kein zweites Release angelegt.

Vorherige Helm-Revision anzeigen:

```sh
helm history ddbgo-t -n ddbgo-t
helm history ddbgo -n ddbgo
```

Rollback auf eine vorhandene Release-Revision:

```sh
helm rollback ddbgo-t <REVISION> -n ddbgo-t --wait --timeout 15m
helm rollback ddbgo <REVISION> -n ddbgo --wait --timeout 15m
```

Alternativ kann eine bekannte ältere Chartversion erneut mit
`helm upgrade --install --version ...` angewendet werden.

`helm uninstall` ist kein normaler Rollback-Mechanismus. Es entfernt Deployment,
StatefulSets, Services, Route, ConfigMap, Secrets, ServiceAccounts und
ImageStreams. Die drei vom Chart erzeugten PVCs bleiben mit
`helm.sh/resource-policy: keep` erhalten. Eine Neuinstallation mit demselben
Release-Namen im selben Namespace übernimmt diese PVCs automatisch, wenn deren
Helm-Owner-Annotationen unverändert sind.

Da erzeugte Secrets entfernt werden, müssen für die Wiederverwendung des
MariaDB-PVCs dieselben Datenbankzugangsdaten erneut angegeben oder über ein
extern verwaltetes Secret referenziert werden. Vor dem Uninstall einer älteren
Chart-Version muss zuerst auf die korrigierte Version aktualisiert werden, damit
die frühere globale `keep`-Annotation von allen Nicht-PVC-Ressourcen entfernt
wird.

Ist die alte Version bereits deinstalliert, können die verwaisten
Nicht-PVC-Ressourcen gezielt über das Release-Label entfernt werden:

```sh
oc delete deployment,statefulset,service,route,configmap,secret,serviceaccount,imagestream \
  --selector app.kubernetes.io/instance=ddbgo-t \
  --namespace ddbgo-t
```

Der PVC-Ressourcentyp wird dabei bewusst nicht angegeben.

## Fehlerfälle

### „Version already exists“

- Auf `test` erzeugt ein neuer Workflow-Lauf automatisch eine neue
  Prerelease-Version.
- Auf `master` muss die Basisversion in `Chart.yaml` über `test` erhöht und
  erneut gemergt werden.
- Eine vorhandene Version darf nicht gelöscht oder überschrieben werden.

### Chart erscheint nicht im Developer Catalog

```sh
oc get projecthelmchartrepository ddbgo -n ddbgo-t -o yaml
oc get projecthelmchartrepository ddbgo -n ddbgo -o yaml
```

Außerdem die jeweilige `index.yaml` direkt aufrufen und prüfen, ob die erwartete
Version enthalten ist. Nach einer Veröffentlichung kann ein erneutes Laden des
Developer Catalog erforderlich sein.

### Installation bricht wegen vorhandener Ressourcen ab

Das ist beabsichtigt. Das Chart übernimmt keine fremden oder nach einem
Uninstall verwaisten Ressourcen. Vor weiteren Schritten muss geklärt werden,
welchem Release die vorhandenen Ressourcen gehören. Sie dürfen nicht durch
`--take-ownership` oder manuelles Überschreiben übernommen werden.
