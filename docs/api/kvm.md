# Verwaltete KVM-Aufträge

Die Adminoberfläche benötigt eine angemeldete Admin-/Superadmin-Sitzung. Kunden erhalten keinen Zugriff auf Hostinventar oder KVM-Aufträge. Alle POST-Aktionen prüfen den Symfony-CSRF-Token `kvm-<node-id>`; GET-Anfragen führen keine Hostmutationen aus.

| Methode und Route | Verhalten |
| --- | --- |
| `GET /admin/kvm` | Node-Auswahl und Agentfähigkeit |
| `GET /admin/kvm/nodes/{id}` | Letzter beobachteter Bestand und 20 aktuelle Aufträge |
| `POST /admin/kvm/nodes/{id}/actions` | Validierte Aktion an die Agent-Warteschlange senden |
| `GET /admin/kvm/nodes/{id}/jobs/{jobId}` | Admin-JSON mit `{id,status}`; Job muss zum Node und Typ passen |

POST verwendet Formularfelder. Ohne XHR folgt eine 303-Weiterleitung zur Nodeansicht. Mit `X-Requested-With: XMLHttpRequest` liefert die Annahme HTTP 202 mit `{job_id,status}`; Validierungsfehler HTTP 422 mit `{error}`. Die Annahme ist noch keine erfolgreiche Ausführung. Fehlertexte in der Formularansicht sind übersetzt; XHR gibt den Übersetzungsschlüssel zurück. Statuswerte: `queued`, `running`, `success`, `failed`. Falsche/fehlende Berechtigung oder CSRF werden abgewiesen, nicht vorhandene Nodes und fremde/andersartige Jobs liefern 404. Inventar- und Statusantworten sind `private, no-store`.

## Agentvertrag

Jobtyp: **`kvm.managed`**. Der bestehende signierte Poll-/Finish-Vertrag transportiert Payload und Resultat. Der Agent muss `metadata.kvm_managed = {version:1,supported:true,...}` melden. Felder `missing_tools` und `acceleration` beschreiben den Hostzustand.

| `action` | Zusätzliche Payloadfelder |
| --- | --- |
| `inspect` | keine; lesend |
| `template.import` | `operation_id`, `template_id`, `version`, `url`, `sha256` |
| `vm.create` | `operation_id`, `label`, `template`, `hostname`, `username`, `ssh_keys[]`, `network_type`, `network`, `vcpus`, `memory_mib`, `disk_gib` |
| `vm.start/shutdown/stop/reboot/suspend/resume/autostart/autostart-disable` | `operation_id`, `vm_id` |
| `vm.limits` | `operation_id`, `vm_id`, `vcpus`, `memory_mib` |
| `snapshot.create/revert/delete` | `operation_id`, `vm_id`, `snapshot` |
| `backup.create` | `operation_id`, `vm_id` |
| `backup.restore/delete` | `operation_id`, `vm_id`, `backup_id` |
| `network.create` | `operation_id`, `network`, `mode: nat|isolated`, `cidr` |
| `network.start/stop/autostart/autostart-disable` | `operation_id`, `network` |

UUIDs müssen kanonische kleingeschriebene UUIDv4 sein. Bei `vm.create` wird `operation_id` zugleich zur VM-UUID, bei `backup.create` zur Backup-UUID. Das Panel kann `network_choice=network:default` bzw. `bridge:br-guests` annehmen und setzt daraus die beiden Agentfelder. Andere Pfad-/XML-/User-Data-Felder werden nicht übernommen. Vollständige Wertebereiche und Hostvoraussetzungen: `wiki/KVM-Integration.md`.

Ein erfolgreiches `inspect` liefert:

```json
{
  "inventory": {
    "observed_at": "2026-11-12T12:00:00Z",
    "capabilities": {"version": 1, "supported": true, "acceleration": true, "missing_tools": []},
    "guests": [], "templates": [], "networks": [], "bridges": [],
    "pending_operations": []
  }
}
```

Verwaltete Gäste enthalten UUID, Anzeigename, Template, hinterlegte Ressourcen, Netzwerkanbindung, Zustand, Snapshots und Backups. Fremde Gäste enthalten nur Inventardaten und `managed:false`. Ein optionales `error` kennzeichnet eine nicht sicher bearbeitbare VM. Templates enthalten Kennung, Version, SHA256 und virtuelle Größe. Backups enthalten UUID, VM-UUID, Zeit, Dateigröße und SHA256.

Mutationen liefern zusätzlich `operation_id`, `action` und – soweit abrufbar – einen aktuellen `inventory`-Stand. Ein `inventory_error` nach einer Mutation bedeutet, dass ein erneuter lesender Abruf erforderlich ist; es bedeutet nicht, dass die Mutation fehlgeschlagen ist. Ein fehlgeschlagener Vorgang kann ebenfalls einen aktualisierten Bestand enthalten. Ein unbestätigter Vorgang verwendet `KVM_OPERATION_UNCONFIRMED` im Fehlertext und sperrt weitere Mutationen auf diesem Host.

Vorgangs-IDs nicht mit veränderten Parametern wiederverwenden. Vor einem neuen Versuch nach unklarem Ergebnis den Hostzustand prüfen. Die Anzeige des letzten Bestands ersetzt keine Echtzeitabfrage unmittelbar vor einer sicherheitsrelevanten Aktion; der Agent prüft die Bedingungen bei jeder Ausführung selbst.
