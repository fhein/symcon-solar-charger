# SolarCharger

IP-Symcon Modul zur solargestützten, netzschonenden Steuerung von Ladeinfrastruktur basierend auf einem Energie-Gateway (z. B. Enphase).

- Verbraucht ein normalisiertes Energiedaten-Payload (`com.maxence.energy.v1`).
- Kommuniziert mit einem Charger-Adapter über das neutrale Protokoll `com.maxence.charger.v1` (z. B. über ein Warp2-Adaptermodul).
- Unterstützte Betriebsarten: Sonne, Sonne + Batterie, nur am Tag, fest, aus.

Voraussetzungen: IP-Symcon ≥ 5.5, ein Energy-Gateway (z. B. EnphaseGateway) sowie ein Charger-Adapter, der `com.maxence.charger.v1` spricht (z. B. ein Warp2-Adapter).

## Konfiguration

1. Im Konfigurationsformular den passenden Energy-Gateway auswählen (oder leer lassen, damit automatisch verbunden wird).
2. Den gewünschten Charger-Adapter auswählen. Das Modul stellt sicher, dass der Adapter als Child verbunden wird; ohne Auswahl wird der erste verfügbare Adapter mit neutralem Interface automatisch verknüpft.

## Charger-Schnittstelle `com.maxence.charger.v1`

Die Kommunikation zwischen `SolarCharger` (Parent) und einem Charger-Adapter (Child) erfolgt über JSON-Frames mit folgendem Aufbau:

```json
{
	"DataID": "{4C3D1DFE-6F92-4E2A-8A3D-2D1A4A2A7A7F}",
	"protocol": "com.maxence.charger.v1",
	"type": "…",
	"data": { … }
}
```

### Befehle vom Parent (`type = "charger.command"`)

| Command       | Zweck                           | Felder                                                                 |
|---------------|---------------------------------|------------------------------------------------------------------------|
| `set_current` | Sollstrom setzen                | `current_ma` (int, mA), optional `force` (bool), `min_current_ma`, `max_current_ma` |
| `refresh`     | Telemetrie aktualisieren        | optional `reason` (string), `requested_at` (Unix-Timestamp)            |
| `reboot`      | Ladeeinrichtung neu starten     | keine zusätzlichen Felder                                             |

Der Adapter muss Befehle idempotent verarbeiten. `refresh` ist gedrosselt (≥ 5 s Abstand); aktive Eigen-Polls sollen vermieden werden.

### Updates vom Adapter (`type = "charger.update"`)

Das Datenobjekt ist frei erweiterbar, sollte aber mindestens folgende Felder bereitstellen:

| Feld                      | Typ    | Beschreibung                                                          |
|---------------------------|--------|------------------------------------------------------------------------|
| `power_w`                 | float  | Aktuelle Ladeleistung in Watt                                         |
| `current_ma`              | int    | Aktueller Ladestrom in mA                                             |
| `status`                  | string | Freitext-Status der Wallbox                                           |
| `hardware.max_current_ma` | int    | (Optional) maximal unterstützter Hardware-Strom                       |
| `received_at`             | int    | (vom Parent gesetzt) Empfangszeitpunkt als Unix-Timestamp             |

Weitere optionale Strukturen (z. B. Gerätestatus, Phasenzahl, Spannungen) können beliebig ergänzt werden. `SolarCharger` übernimmt neue Hardware-Grenzwerte automatisch, sobald sie in `hardware.max_current_ma` (oder alternativ `hardware_settings.max_current_ma`, `limits.max_current_ma` etc.) auftauchen.

### Lebenszyklus

1. Adapter meldet sich als Child des `SolarCharger` an und sendet einen initialen `charger.update` mit Telemetrie sowie Hardware-Limits.
2. `SolarCharger` übermittelt Sollwertänderungen per `charger.command` (`set_current`, `refresh`, `reboot`).
3. Adapter bestätigt Änderungen über neue `charger.update`-Frames. Bleiben Updates länger als 15 s aus, fällt das Modul auf geschätzte Leistungswerte zurück und fordert eine Aktualisierung an.

