# Musicbot TeamSpeak Voice Backend

Das Musicbot-Modul behandelt TeamSpeak 3 und TeamSpeak 6 über ein gemeinsames TeamSpeak Voice Backend. Die Auswahl erfolgt über ein Profil (`ts3` oder `ts6`) in der Verbindungskonfiguration; sie erzeugt keinen separaten Voice-Stack pro TeamSpeak-Generation.

## Gemeinsames Backend

- `platform` bleibt immer `teamspeak`.
- `profile` unterscheidet zwischen `ts3` und `ts6`.
- `backend` ist `ts3_client_compatible`.
- TeamSpeak 6 wird damit ausdrücklich als Profil über denselben TS3-client-kompatiblen Backend-Weg geführt.

## Audio-Anforderung

ServerQuery kann Verwaltungsaufgaben ausführen, aber kein Audio senden. Für Wiedergabe in einem TeamSpeak-Channel wird ein echter TeamSpeak-client-kompatibler Voice-Client benötigt, der sich wie ein nativer Client verbindet und Audio in den Zielchannel überträgt.

Der aktuelle Runtime-Connector ist weiterhin ein Placeholder. Die Runtime besitzt dafür eine `NativeTeamspeakVoiceClient`-Schnittstelle, an die später ein echter nativer TeamSpeak-client-kompatibler Voice-Layer angeschlossen werden kann. Der Placeholder meldet deshalb:

- `backend = ts3_client_compatible`
- `voice_client_available = false`
- `capability_status = client_backend_required`
- `reason = "TeamSpeak voice client backend is not configured."`

## Abgrenzung

Die Lösung bleibt First-Party und baut keine SinusBot- oder TS3AudioBot-Abhängigkeit ein. Solche Produkte dürfen weder installiert noch als Voraussetzung für den Betrieb des Musicbots angenommen werden.

## Runtime-Schnittstelle

Der native Voice-Layer wird über `NativeTeamspeakVoiceClient` angebunden. Diese Schnittstelle kapselt Verbindungsaufbau, Authentifizierung, Nickname-Änderung, Channel Join/Leave, OPUS-Frames, Client-ID, Verbindungsstatus und Fehlerabfrage. Die aktuelle `PlaceholderTeamspeakVoiceClient`-Implementierung validiert nur die Konfiguration und gibt bei Voice-Aktionen einen klaren Fehler zurück. Sie implementiert kein Reverse Engineering, keine Audio-Übertragung und bindet keine fremden Musicbot-Systeme ein.
