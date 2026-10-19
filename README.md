# multiplayer.gratis WHMCS-Modul

Provisioning-Modul für [WHMCS](https://www.whmcs.com), mit dem du Server von [multiplayer.gratis](https://multiplayer.gratis) automatisch bestellst, verlängerst und verwaltest. Bezahlt wird nicht mit Geld, sondern mit dem Coin-Guthaben deines multiplayer.gratis-Kontos. Das Modul nutzt das Composer-Paket [`multiplayer-gratis/api-client`](https://packagist.org/packages/multiplayer-gratis/api-client).

## Funktionen

| WHMCS-Aktion | Was passiert bei multiplayer.gratis |
| --- | --- |
| Create | Server wird mit Paket, Spiel und optionalem Rabattcode bestellt |
| Renew | Server wird um die Paketlaufzeit verlängert |
| Change Package | Upgrade auf das neue Paket |
| Suspend | Server stoppen, sofort beenden oder nichts tun (einstellbar) |
| Unsuspend | Server optional wieder starten |
| Terminate | Server löschen, stoppen, sofort beenden oder nichts tun (einstellbar) |

- **Kundenbereich:** Status, Serverdetails, Auslastung, Link zum Panel, Power-Buttons sowie Backups erstellen, herunterladen, wiederherstellen und löschen
- **Admin-Bereich:** Serverdaten, Auslastung und Backups im Dienst-Tab, Power-Buttons, manuelles Verlängern und Backup erstellen
- **Produkt-Einstellungen:** Server-Art, Paket und Spiel als Dropdown direkt aus der API

## Voraussetzungen

- WHMCS 8.x
- PHP 8.1+ mit `ext-curl` und `ext-json`
- Composer (nur zum Installieren der Abhängigkeiten)
- genug Coins auf dem multiplayer.gratis-Konto

## Installation

1. Abhängigkeiten installieren:

   ```bash
   cd modules/servers/multiplayergratis
   composer install --no-dev
   ```

2. Den Ordner `modules/servers/multiplayergratis` inklusive `vendor/` in deine WHMCS-Installation nach `modules/servers/multiplayergratis` kopieren.

## Einrichtung

### 1. Server anlegen

Unter **System Settings → Servers → Add New Server**:

| Feld | Wert |
| --- | --- |
| Module | `multiplayer.gratis` |
| Hostname | leer lassen oder `multiplayer.gratis` eintragen (die API-Adresse ist fest eingestellt) |
| Password | dein API-Schlüssel (`mg_…`) aus dem Dashboard unter **API** |

Mit **Test Connection** prüfst du den Schlüssel. Der Server-Schlüssel gilt für alle Produkte, die keinen eigenen Schlüssel haben. Leg den Server anschließend in eine **Server Group**.

### 2. Produkt anlegen

Unter **System Settings → Products/Services** ein Produkt anlegen, im Tab **Module Settings** das Modul `multiplayer.gratis` und die Server Group auswählen.

| Option | Standard | Beschreibung |
| --- | --- | --- |
| Server-Art | `gameserver` | `gameserver`, `runtime` oder `software`. Webspaces sind aktuell nicht bestellbar |
| Paket | – | Paket, das bestellt wird, z. B. `plus` |
| Spiel / Umgebung | – | Egg-ID des Spiels, der Runtime (z. B. Node.js) oder der Software (z. B. Discord-Bot) |
| Rabattcode | leer | Wird bei jeder Bestellung angerechnet |
| API-Schlüssel | leer | Eigener Schlüssel für dieses Produkt, überschreibt den Schlüssel vom Server |
| Bei Sperrung | Server stoppen | `Server stoppen`, `Sofort beenden` oder `Nichts tun` |
| Bei Entsperrung starten | an | Server nach dem Entsperren automatisch starten |
| Bei Kündigung | Server löschen | `Server löschen`, `Server stoppen`, `Sofort beenden` oder `Nichts tun` |

Server-Art, Paket und Spiel lädt WHMCS als Dropdown direkt aus der API. Dafür braucht der Server in der Server Group einen gültigen API-Schlüssel. Klappt das nicht, zeigt WHMCS ein normales Textfeld, und du trägst die Werte von Hand ein.

`Server löschen` ist endgültig. Der Server und seine Daten sind dann weg, und es gibt keine Coins zurück.

### 3. Custom Fields (empfohlen)

Im Tab **Custom Fields** des Produkts:

| Feldname | Typ | Einstellungen | Zweck |
| --- | --- | --- | --- |
| `Server ID` | Text Box | Admin Only | Das Modul trägt hier nach der Bestellung die ID des Servers ein |
| `Servername` | Text Box | Show on Order Form | Optional: Name des Servers, den der Kunde bei der Bestellung wählt |

Ohne `Server ID`-Feld funktioniert das Modul auch. Es sucht den Server dann bei jedem Aufruf über die Referenz `whmcs-<Dienst-ID>`, was eine zusätzliche API-Anfrage kostet.

Ohne `Servername` heißt der Server wie die Domain des Dienstes oder `Server <Dienst-ID>`.

### 4. Kunden wählen lassen (optional)

Sollen Kunden Paket oder Spiel bei der Bestellung selbst wählen, leg **Configurable Options** mit diesen Namen an:

| Name der Option | Überschreibt |
| --- | --- |
| `Paket` | Paket |
| `Spiel` | Spiel / Umgebung |
| `Server-Art` | Server-Art |

Die Werte der Unteroptionen schreibst du im WHMCS-Format `Wert|Anzeigename`, zum Beispiel `3|Minecraft (Paper)` oder `plus|Plus-Paket`.

## API-Schlüssel pro Produkt (Reselling)

Das Modul sucht den API-Schlüssel in dieser Reihenfolge:

1. **Produkt** → Module Settings → *API-Schlüssel*
2. **Server** → *Password*
3. **Server** → *Access Hash*

So kannst du verschiedene Produkte über verschiedene multiplayer.gratis-Konten laufen lassen, zum Beispiel einen Schlüssel pro Reseller oder Paket. Wenn du nur einen Schlüssel brauchst, reicht der am Server.

WHMCS speichert Produkt-Optionen unverschlüsselt in der Datenbank. Das Server-Passwort dagegen wird verschlüsselt gespeichert. Wenn du nur einen Schlüssel nutzt, ist der Server deshalb der sicherere Ort.

## Hinweise

- **Coins:** Jede Bestellung, Verlängerung und jedes Upgrade bucht Coins von deinem Konto ab. Fehlen Coins, schlägt die Aktion mit einer Meldung fehl, wie viele fehlen. Schlägt die Einrichtung im Panel fehl, bucht multiplayer.gratis die Coins automatisch zurück.
- **Laufzeit:** Ein Paket läuft eine feste Anzahl Tage. Stell den Abrechnungszeitraum des Produkts passend ein, zum Beispiel *Monthly* bei 30 Tagen. Sonst läuft der Server vor der nächsten Rechnung ab.
- **Limits:** Pro Konto sind höchstens 5 aktive Server möglich, pro Schlüssel 60 Anfragen pro Minute. Jeder Aufruf der Dienstseite braucht drei Anfragen.
- **Doppelte Bestellungen:** Wird *Create* erneut ausgeführt, bestellt das Modul keinen zweiten Server, solange es einen Server mit der Referenz `whmcs-<Dienst-ID>` findet.
- **Kundenaktionen:** Power-Aktionen und Backups gehen nur, solange der Dienst *Active* ist. Admins können Power-Aktionen immer ausführen.
- **Sperren:** Ein gesperrter Kunde hat keinen Zugriff auf dein multiplayer.gratis-Konto. Gesperrt ist also wirklich gesperrt, solange die Option *Bei Sperrung* nicht auf `Nichts tun` steht.
- **Module Log:** Alle API-Aufrufe landen unter System Logs → Module Log. Der API-Schlüssel wird dort maskiert.

## Lizenz

MIT – siehe [LICENSE](LICENSE).
