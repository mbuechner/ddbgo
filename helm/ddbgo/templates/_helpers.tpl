{{/* Chart name. */}}
{{- define "ddbgo.name" -}}
ddbgo
{{- end }}

{{/* Resource base name: the requested naming scheme is the release name. */}}
{{- define "ddbgo.fullname" -}}
{{- .Release.Name | trunc 63 | trimSuffix "-" }}
{{- end }}

{{- define "ddbgo.componentName" -}}
{{- printf "%s-%s" (include "ddbgo.fullname" .root) .component | trunc 63 | trimSuffix "-" }}
{{- end }}

{{- define "ddbgo.labels" -}}
helm.sh/chart: {{ printf "%s-%s" .Chart.Name .Chart.Version | replace "+" "_" | quote }}
app.kubernetes.io/name: {{ include "ddbgo.name" . | quote }}
app.kubernetes.io/instance: {{ .Release.Name | quote }}
app.kubernetes.io/managed-by: {{ .Release.Service | quote }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
{{- end }}

{{- define "ddbgo.selectorLabels" -}}
app.kubernetes.io/name: {{ include "ddbgo.name" .root | quote }}
app.kubernetes.io/instance: {{ .root.Release.Name | quote }}
app.kubernetes.io/component: {{ .component | quote }}
{{- end }}

{{- define "ddbgo.protectionAnnotations" -}}
meta.helm.sh/release-name: {{ .Release.Name | quote }}
meta.helm.sh/release-namespace: {{ .Release.Namespace | quote }}
{{- if .Values.protection.keepOnDelete }}
helm.sh/resource-policy: keep
{{- end }}
{{- end }}

{{/*
Fail before an install can adopt or overwrite an existing object. During an
upgrade, only objects carrying this release's ownership metadata are accepted.
This check needs a connected cluster; Helm itself performs the same ownership
check when lookup is unavailable (for example, client-side helm template).
*/}}
{{- define "ddbgo.assertAvailable" -}}
{{- $root := .root -}}
{{- if $root.Values.protection.failOnExistingResource -}}
  {{- $existing := lookup .apiVersion .kind $root.Release.Namespace .name -}}
  {{- if $existing -}}
    {{- if $root.Release.IsInstall -}}
      {{- fail (printf "%s %s/%s already exists; refusing to overwrite or adopt it" .kind $root.Release.Namespace .name) -}}
    {{- else -}}
      {{- $annotations := dig "metadata" "annotations" (dict) $existing -}}
      {{- $ownerName := get $annotations "meta.helm.sh/release-name" -}}
      {{- $ownerNamespace := get $annotations "meta.helm.sh/release-namespace" -}}
      {{- if or (ne $ownerName $root.Release.Name) (ne $ownerNamespace $root.Release.Namespace) -}}
        {{- fail (printf "%s %s/%s is not owned by release %s/%s; refusing to overwrite it" .kind $root.Release.Namespace .name $root.Release.Namespace $root.Release.Name) -}}
      {{- end -}}
    {{- end -}}
  {{- end -}}
{{- end -}}
{{- end }}

{{- define "ddbgo.drupalName" -}}
{{ include "ddbgo.componentName" (dict "root" . "component" "drupal") }}
{{- end }}

{{- define "ddbgo.redisName" -}}
{{ include "ddbgo.componentName" (dict "root" . "component" "redis") }}
{{- end }}

{{- define "ddbgo.databaseName" -}}
{{ include "ddbgo.componentName" (dict "root" . "component" "db") }}
{{- end }}

{{- define "ddbgo.drupalServiceAccountName" -}}
{{- default (include "ddbgo.drupalName" .) .Values.serviceAccounts.drupalName }}
{{- end }}

{{- define "ddbgo.redisServiceAccountName" -}}
{{- default (include "ddbgo.redisName" .) .Values.serviceAccounts.redisName }}
{{- end }}

{{- define "ddbgo.databaseServiceAccountName" -}}
{{- default (include "ddbgo.databaseName" .) .Values.serviceAccounts.databaseName }}
{{- end }}

{{- define "ddbgo.drupalSecretName" -}}
{{- default (include "ddbgo.drupalName" .) .Values.drupal.secret.existingSecret }}
{{- end }}

{{- define "ddbgo.redisSecretName" -}}
{{- default (include "ddbgo.redisName" .) .Values.redis.secret.existingSecret }}
{{- end }}

{{- define "ddbgo.databaseSecretName" -}}
{{- default (include "ddbgo.databaseName" .) .Values.database.auth.existingSecret }}
{{- end }}

{{- define "ddbgo.drupalClaimName" -}}
{{- default (include "ddbgo.drupalName" .) .Values.drupal.persistence.existingClaim }}
{{- end }}

{{- define "ddbgo.redisClaimName" -}}
{{- default (include "ddbgo.redisName" .) .Values.redis.persistence.existingClaim }}
{{- end }}

{{- define "ddbgo.databaseClaimName" -}}
{{- default (include "ddbgo.databaseName" .) .Values.database.persistence.existingClaim }}
{{- end }}

{{/* OpenShift image change trigger for a container in a pod template. */}}
{{- define "ddbgo.imageTrigger" -}}
{{- $from := dict "kind" "ImageStreamTag" "name" (printf "%s:%s" .name .tag) "namespace" .root.Release.Namespace -}}
{{- $trigger := dict "from" $from "fieldPath" (printf "spec.template.spec.containers[?(@.name==\"%s\")].image" .container) "paused" false -}}
{{- list $trigger | toJson -}}
{{- end }}

{{/* Paths fixed by the DDBgo container image. */}}
{{- define "ddbgo.drupalWebRoot" -}}
/var/www/html/web
{{- end }}

{{- define "ddbgo.drupalPublicFilesMountPath" -}}
{{- printf "%s/%s" (include "ddbgo.drupalWebRoot" .) .Values.drupal.config.filePublicPath -}}
{{- end }}
