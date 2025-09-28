# SolarCharger

IP-Symcon Modul zur solargesttzten, netzschonenden Steuerung des WARP2-Ladestroms basierend auf einem Energie-Gateway (z. B. Enphase).

- Verbraucht ein normalisiertes Energiedaten-Payload (com.maxence.energy.v1)
- Steuert die WARP2 Wallbox ber RequestAction (target_current, update_now, reboot)
- Modi: Sonne, Sonne+Batterie, nur am Tag, fest, aus

Voraussetzungen: IP-Symcon 2 5.5, ein Energy-Gateway (z. B. EnphaseGateway) und das Warp2Gateway.
