# Bahntafel

Abfahrts- und Ankunftstafel eines festen Heimatbahnhofs aus der DB OpenBoards API, mit Verspätungen, Ausfällen, Gleiswechseln und Hinweisen. Optional lässt sich die Tafel auf einen Zielbahnhof einschränken. Außerdem zeigt sie, ob man einen Zug von zu Hause noch erreicht.

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in IP-Symcon](#4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Visualisierung](#5-statusvariablen-und-visualisierung)
6. [PHP-Befehlsreferenz](#6-php-befehlsreferenz)
7. [Aktualisierung und Grenzen](#7-aktualisierung-und-grenzen)

### 1. Funktionsumfang

- Abfahrt und Ankunft eines Bahnhofs, umschaltbar, mit Echtzeitdaten (Verspätung als rote Zeit, Gleiswechsel als rotes Gleis, Ausfälle, Gründe für Verspätungen und Störungen).
- Auf Wunsch erweiterte Hinweise wie „Ein Wagen fehlt“ oder „Hohe Auslastung“.
- Filter nach Zuggattung und optional nach Zielbahnhof: Abfahrt nur Züge dorthin, Ankunft nur Züge von dort.
- Erreichbarkeit bei Abfahrt aus Wegezeit und Toleranz: *erreichbar*, *knapp*, *nicht erreichbar*, mit farbig hinterlegten Zeilen.
- HTML-Box für das alte WebFront und Kachel für die Tile-Visualisierung.

### 2. Voraussetzungen

- IP-Symcon ab 9.0
- Kostenloses Konto im [DB API Marketplace](https://developers.deutschebahn.com/) mit einer Anwendung, die die API **OpenBoards** abonniert hat. Dazu die API **OpenStation** für die Suche nach Namen, die Suche nach EVA-Nummer geht auch ohne.

### Zugang zur DB API

OpenBoards ist kostenlos. Man braucht dafür ein Entwicklerkonto, das in ein paar Minuten angelegt ist:

1. Im [DB API Marketplace](https://developers.deutschebahn.com/db-api-marketplace/apis/) auf *Registrieren* gehen und ein Konto anlegen. Die Bestätigungsmail öffnen und sich anmelden.
2. Unter *Anwendungen* (bzw. *Meine Anwendungen*) eine neue Anwendung anlegen, z. B. „Symcon Bahntafel“. Danach zeigt die Seite die **Client-ID** und den **API-Key** (Client Secret). Den API-Key gleich notieren, er wird unter Umständen nur einmal angezeigt; sonst lässt sich ein neuer erzeugen.
3. Im Katalog die API **OpenBoards** öffnen, *Abonnieren* wählen, den kostenlosen Plan nehmen und die eben angelegte Anwendung auswählen. Danach genauso **OpenStation** für die Bahnhofssuche abonnieren.
4. Client-ID und API-Key in der Instanz eintragen und „Verbindung testen“ klicken. Direkt nach dem Abonnieren kann es ein paar Minuten dauern, bis die Zugangsdaten angenommen werden.

Client-ID und API-Key gehören nur in die Instanz, nicht in Skripte, Screenshots oder öffentliche Repositories.

### 3. Software-Installation

Über den Module Store oder in der Konsole unter *Module Control* die URL `https://github.com/captmajac/IPSBahntafel` hinzufügen.

Kompatibilität: alle Bahnhöfe in Deutschland, die die DB API kennt. Die Tafel braucht eine Internetverbindung zur DB API.

### 4. Einrichten der Instanzen in IP-Symcon

Unter *Instanz hinzufügen* die Instanz *Bahntafel* auswählen. Für mehrere Bahnhöfe legt man mehrere Instanzen an.

Die Einstellungen sind in vier aufklappbare Bereiche gegliedert: *Zugang zur DB API*, *Heimat- und Zielbahnhof*, *Anzeige* und *Erreichbarkeit*.

1. Unter *Zugang zur DB API* Client-ID und API-Key eintragen. „Verbindung testen“ prüft die Zugangsdaten und meldet den Namen des Bahnhofs, ohne gewählten Bahnhof den von Frankfurt (Main) Hbf. Solange Zugangsdaten fehlen, ist dieser Bereich aufgeklappt.
2. Bei „Bahnhofsname oder EVA-Nummer“ den Namen (z. B. `WohnortBahnhof`) oder die 7-stellige EVA-Nummer eingeben und „Suchen“ klicken. Der erste Treffer steht dann direkt darunter unter „Heimatbahnhof“. Passt er nicht, dort einen anderen Treffer wählen. Dann übernehmen.
3. Optional unter „Zielbahnhof“ z. B. `Großstadt Hbf` wählen. Die Abfahrtstafel zeigt dann nur Züge nach Großstadt Hbf, die Ankunftstafel nur Züge von dort. „(alle Züge)“ hebt die Einschränkung wieder auf. Die Liste füllt sich, sobald ein Heimatbahnhof gewählt ist, mit den Bahnhöfen seiner Züge in den nächsten ein bis zwei Stunden. Speichern ist dafür nicht nötig. Die ausgeblendeten Züge verkürzen die Tafel nicht, das Modul lädt dann weitere Stunden.
   - Abfahrt: Züge, die danach am Zielbahnhof halten, auch wenn sie weiterfahren.
   - Ankunft: Züge, die vorher am Zielbahnhof gehalten haben, auch wenn sie woanders gestartet sind. Die Tafel zeigt, woher der Zug kommt und wohin er weiterfährt.
4. Optional unter *Erreichbarkeit* die Wegezeit von zu Hause zum Bahnhof eintragen, und zwar entspannt gegangen, dazu wie viele Minuten man mit zügigem Gehen spart. Die Abfahrtstafel zeigt dann *erreichbar* (entspannt), *knapp* (nur zügig) oder *nicht erreichbar*.
5. Optional unter *Anzeige* „Instanz nach Bahnhof benennen“ klicken: Die Instanz und damit die Kachel heißt dann wie der Bahnhof. Das Modul ändert Namen und Icons sonst nie selbst.

Unter der Instanz liegen:

- **Anzeige:** Umschalter Abfahrt/Ankunft. Er gilt für alle Clients.
- **Tafel:** HTML-Box für das alte WebFront, mit Uhr-Icon. Name und Icon werden nur beim Anlegen gesetzt.
- **Kachel:** In der Tile-Visualisierung ist die Instanz selbst die Kachel, mit eigenem Umschalter Abfahrt/Ankunft. Eine große Kachel zeigt die Liste, eine kleine nur den nächsten erreichbaren Zug.

| Einstellung | Bedeutung |
|---|---|
| Client-ID, API-Key | Zugangsdaten aus dem DB API Marketplace |
| Heimatbahnhof | über die Suche nach Name oder EVA-Nummer wählen |
| Zielbahnhof | Optional: Abfahrt nur Züge dorthin, Ankunft nur Züge von dort; „(alle Züge)“ zeigt alle |
| Züge | Gattungen: Fernverkehr, Regionalverkehr, S-Bahn, Bus, Tram, Sonstige |
| Erweiterte Hinweise | Auch Hinweise wie „Ein Wagen fehlt“, „Hohe Auslastung“ oder „Heute nur einteilig“, Standard aus |
| Zeilen auf der Tafel | 1 bis 20, Standard 10 |
| Wegezeit zum Bahnhof, entspannt | Minuten, 0 schaltet die Erreichbarkeit aus |
| davon zügig einsparbar | Minuten, die man zügig schneller ist; Züge in dieser Spanne gelten als *knapp* |
| Aktualisierungsintervall | 15 bis 240 Minuten, Standard 60 |

### 5. Statusvariablen und Visualisierung

| Ident | Typ | Bedeutung |
|---|---|---|
| Board | Integer | Anzeige: 0 = Abfahrt, 1 = Ankunft, schaltbar |
| BoardHtml | String (~HTMLBox) | Tafel für das alte WebFront |

In der Tile-Visualisierung ist die Instanz selbst die Kachel. Die Farben des Umschalters folgen dem Design.

### 6. PHP-Befehlsreferenz

Das Modul hat keine eigenen Funktionen mit Präfix. Alles läuft über `IPS_RequestAction`:

```php
IPS_RequestAction($id, 'Update', '');  // Tafel sofort abrufen
IPS_RequestAction($id, 'Board', 1);     // auf Ankunft umschalten (0 = Abfahrt)
```

### 7. Aktualisierung und Grenzen

Die Tafel wird im eingestellten Intervall abgerufen (Standard 60 Minuten). Jeder Abruf besteht aus 2 API-Aufrufen: Abfahrts- und Ankunftstafel ab jetzt bis zum Ende der nächsten Stunde, verspätete Züge sind enthalten. Füllen die Züge nach den Filtern die eingestellten Zeilen nicht, lädt das Modul weitere Stunden nach (je Stunde ein weiterer Abruf), höchstens 4 Stunden über die aktuelle hinaus, und beginnt beim nächsten Abruf gleich mit so vielen Stunden. Reichen die geladenen Stunden weniger als eine Stunde voraus, lädt das Modul sofort nach, auch bei längerem Intervall. Beim Umschalten der Anzeige wird zusätzlich abgerufen, höchstens alle 5 Minuten. Restzeit und Erreichbarkeit berechnet das Modul jede Minute neu, dafür ruft es die API nicht ab.

- Nur Direktverbindungen: Ob ein Zug zur Strecke gehört, ergibt sich aus seinem Laufweg.
- Vorschau 1 bis 2 Stunden, bei ausgeblendeten Zügen bis zu 5 Stunden.
- Verspätungen sind höchstens ein Intervall alt.
- OpenBoards liefert nur Schienenverkehr. Busse und Trams erscheinen damit nicht, Ersatzverkehr der Bahn schon.
