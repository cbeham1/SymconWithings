# Withings-Entwickler-App einrichten (Schritt für Schritt)

Diese Anleitung beschreibt ausführlich, wie die für das Modul **Withings Scale** nötige App im Withings-Entwicklerportal
angelegt und mit IP-Symcon verbunden wird.

[Zurück zur Modul-Anleitung](README.de.md)

## Inhaltsverzeichnis

1. [Warum eine eigene App?](#1-warum-eine-eigene-app)
2. [Was du brauchst](#2-was-du-brauchst)
3. [Reihenfolge im Überblick](#3-reihenfolge-im-überblick)
4. [Modul installieren und Instanz anlegen](#4-modul-installieren-und-instanz-anlegen)
5. [Konto im Entwicklerportal](#5-konto-im-entwicklerportal)
6. [App anlegen](#6-app-anlegen)
7. [Client-ID und Client-Secret in Symcon eintragen](#7-client-id-und-client-secret-in-symcon-eintragen)
8. [Autorisieren](#8-autorisieren)
9. [Sofortmeldung (Benachrichtigung) einschalten](#9-sofortmeldung-benachrichtigung-einschalten)
10. [Mehrere Personen](#10-mehrere-personen)
11. [Ohne Symcon Connect](#11-ohne-symcon-connect)
12. [Bestehende Integrationen (eigene Skripte) parallel betreiben](#12-bestehende-integrationen-eigene-skripte-parallel-betreiben)
13. [Sicherheit](#13-sicherheit)
14. [Fehlersuche](#14-fehlersuche)

## 1. Warum eine eigene App?

Withings gibt Messdaten nur an registrierte Anwendungen heraus (OAuth 2.0). Jede Anwendung hat eine **Client-ID** und ein
**Client-Secret** und darf Benutzer nur auf vorher eingetragene **Callback-Adressen** zurückleiten. Da jede
Symcon-Installation eine eigene Adresse hat, legt jeder Benutzer seine eigene (kostenlose) App an. Das Withings-Passwort
wird dabei nie in Symcon gespeichert – Symcon erhält nur Zugriffs-Tokens, die du jederzeit widerrufen kannst.

## 2. Was du brauchst

| Was | Wozu |
| --- | --- |
| Withings-Konto mit Waage | das Konto, dessen Messungen gelesen werden (Anmeldung wie in der Withings-App) |
| Konto im Entwicklerportal | [developer.withings.com](https://developer.withings.com) – darf dieselbe E-Mail-Adresse sein, ist aber ein eigenes Konto |
| IP-Symcon 7.0+ mit **Symcon Connect** | Withings leitet nach der Anmeldung auf eine von außen erreichbare https-Adresse zurück |
| WebHook Control | Standard in jeder Symcon-Installation; das Modul trägt seinen WebHook selbst ein |

## 3. Reihenfolge im Überblick

```
Symcon: Modul installieren ─► Instanz anlegen ─► Callback-URL steht im Formular
                                                          │
Withings-Portal: App anlegen ◄── Callback-URL eintragen ◄─┘
        │
        └─► Client-ID + Client-Secret ─► Symcon: eintragen, übernehmen ─► „Bei Withings autorisieren“
```

Wichtig: **Erst die Instanz anlegen, dann die App.** Die Callback-URL enthält die Instanz-ID und ist erst danach bekannt;
außerdem kann Withings die Adresse erst testen, wenn der WebHook existiert.

## 4. Modul installieren und Instanz anlegen

1. Symcon-Konsole → Kern Instanzen → **Modules** (Module Control) → **+** → `https://github.com/cbeham1/SymconWithings`.
2. Objektbaum → **Instanz hinzufügen** → „Withings“ suchen → **Withings Scale** (auch unter „Withings Waage“,
   „Withings Body“, „Withings Körperanalyse“ zu finden – alles dasselbe Modul).
3. Name z. B. `Withings Christian` (die Kachel zeigt dann „Christian“) → **OK**.
4. Im Formular steht unten die **Callback-URL**, z. B.

   ```
   https://0123456789abcdef0123456789abcdef.ipmagic.de/hook/Withings12345
   ```

   Der Status „Client-ID und Client-Secret fehlen“ (rotes Symbol) ist zu diesem Zeitpunkt normal.

## 5. Konto im Entwicklerportal

1. [developer.withings.com](https://developer.withings.com) öffnen → **Developer Dashboard** → registrieren bzw. anmelden.
2. Bei der ersten Anmeldung fragt Withings nach einer Organisation (Name frei wählbar, z. B. „Symcon Zuhause“).
3. Oben links muss **Withings Public Cloud** gewählt sein.

## 6. App anlegen

**Overview → Create an application.**

### Schritt 1 – Art der Integration

| Feld | Eingabe |
| --- | --- |
| Service | **Public API integration** |
| Nutzungsbedingungen | lesen und bestätigen |

### Schritt 2 – Information

| Feld | Eingabe | Hinweis |
| --- | --- | --- |
| Target environment | **Development** | reicht für die private Nutzung im eigenen Haushalt |
| Application name | z. B. `IP-Symcon Waage` | frei wählbar, erscheint auf der Zustimmungsseite |
| Application description | z. B. `Private Anbindung meiner Withings-Waage an IP-Symcon` | frei wählbar |
| Registered URLs | die **Callback-URL aus dem Symcon-Formular**, genau so | siehe unten |
| Project logo | optional | |

**Registered URLs:**

* Die Adresse **zeichengenau** übernehmen (https, ohne Leerzeichen, ohne Schrägstrich am Ende).
* **Test** klicken: Withings ruft die Adresse auf, das Modul antwortet mit „OK“ → grünes Häkchen.
* Für jede weitere Instanz (weitere Person) mit **Register another url** deren Adresse ergänzen (siehe [Abschnitt 10](#10-mehrere-personen)).
* Dieselbe Adresse gilt auch für die Sofortmeldung ([Abschnitt 9](#9-sofortmeldung-benachrichtigung-einschalten)).

Mit **Done** abschließen.

### Schritt 3 – Zugangsdaten ablesen

Unter **Overview** erscheint die App mit

| Eintrag | Bedeutung |
| --- | --- |
| ClientID | lange Zeichenkette – mit **Copy** kopieren |
| Secret | wird verdeckt angezeigt; mit **Copy** bzw. dem Auge-Symbol kopieren. **Renew** erzeugt ein neues Secret – danach muss es auch in Symcon neu eingetragen werden |
| Callback URL | die eingetragene(n) Adresse(n); mit **Edit** änderbar |

## 7. Client-ID und Client-Secret in Symcon eintragen

1. In der Instanz unter **Withings-Entwickler-App** Client-ID und Client-Secret einfügen.
2. „Eigene Callback-URL“ **leer lassen** (dann wird automatisch die Connect-Adresse verwendet).
3. **Übernehmen**. Der Status wechselt auf „Autorisierung bei Withings erforderlich“.

## 8. Autorisieren

1. In der Instanz **Bei Withings autorisieren** klicken – es öffnet sich die Withings-Anmeldeseite.
2. Mit dem **Withings-Konto der Person** anmelden (nicht zwingend das Entwicklerkonto).
3. Gegebenenfalls das Profil wählen und den Zugriff auf die Messdaten **erlauben**.
4. Withings leitet auf Symcon zurück: „Autorisierung erfolgreich. Dieses Fenster kann geschlossen werden.“
5. Das Formular zeigt „Autorisiert (Withings-Benutzer …)“, die Instanz liest die Messungen der letzten Tage
   (mindestens 30) und legt die Variablen an.

Der Code aus Schritt 4 ist bei Withings nur **30 Sekunden** gültig – die Rückleitung erledigt das automatisch.
Das Modul prüft dabei einen zufälligen `state`-Wert, damit niemand fremde Konten unterschieben kann.

## 9. Sofortmeldung (Benachrichtigung) einschalten

Ohne Sofortmeldung fragt das Modul im eingestellten Intervall (Standard 30 min). Mit Sofortmeldung schickt Withings
nach jeder neuen Messung eine Nachricht, und die Werte sind nach wenigen Sekunden in Symcon (z. B. rechtzeitig für eine
Morgendurchsage).

1. In der Instanz **Withings meldet neue Messungen sofort** einschalten → **Übernehmen**.
2. Das Modul meldet die Adresse `…/hook/Withings<ID>?n=<zufälliges Token>` bei Withings an. Die Domain muss in der App
   unter Registered URLs eingetragen sein (ist sie bereits durch Schritt 6).
3. Prüfen: nach der nächsten Messung zeigt die Debug-Ausgabe der Instanz „Notify“, kurz darauf „getmeas“.

Das Abfrageintervall kann trotzdem eingeschaltet bleiben (Sicherheitsnetz, falls eine Meldung verloren geht).

## 10. Mehrere Personen

* Pro Person (eigenes Withings-Konto) eine eigene Instanz anlegen.
* **Eine App reicht** für alle Personen – nur die Callback-URL jeder Instanz (`…/hook/Withings<ID>`) zusätzlich unter
  Registered URLs eintragen.
* In jeder Instanz dieselbe Client-ID / dasselbe Secret eintragen und jede Person einzeln autorisieren.
* Personen, die die Waage nur über ein Profil im Konto eines anderen nutzen (z. B. Kinder ohne eigenes Konto), haben keine
  eigenen Zugangsdaten; ihre Messungen erscheinen nicht im fremden Konto. Messungen, die die Waage niemandem sicher
  zuordnen kann, ignoriert das Modul standardmäßig.

## 11. Ohne Symcon Connect

Withings muss die Callback-Adresse über das Internet erreichen. Ohne Connect gibt es zwei Wege:

**a) Eigene Adresse:** Eine eigene https-Adresse (Reverse-Proxy, DynDNS), die auf
`http://<symcon>:3777/hook/Withings<ID>` weiterleitet, unter „Eigene Callback-URL“ eintragen und in der App registrieren.

**b) Manuell (ohne Erreichbarkeit):**

1. In der App eine beliebige Adresse registrieren, z. B. `http://localhost:3000/` (Withings markiert sie als „Restricted“, das ist in Ordnung).
2. Dieselbe Adresse in der Instanz unter „Eigene Callback-URL“ eintragen → **Übernehmen**.
3. **Bei Withings autorisieren** → anmelden → erlauben. Der Browser zeigt danach eine Fehlerseite – das ist erwartet.
4. Die komplette Adresse aus der Adresszeile (`http://localhost:3000/?code=…&state=…`) kopieren und **innerhalb von 30 Sekunden**
   unter „Manuell autorisieren“ einfügen → **Autorisierung abschließen**.

Die Sofortmeldung ist ohne erreichbare Adresse nicht möglich.

## 12. Bestehende Integrationen (eigene Skripte) parallel betreiben

Withings ersetzt beim Erneuern das **Refresh-Token**; das alte wird ungültig. Nutzen zwei Integrationen dieselbe
Autorisierung, wirft die eine die andere hinaus.

* Für das Modul eine **eigene App** anlegen (oder zumindest eine eigene Autorisierung durchführen) – nicht die Tokens eines
  bestehenden Skripts übernehmen.
* Die alte App erst löschen, wenn das alte Skript abgeschaltet ist. Das Löschen einer App macht alle ihre Tokens ungültig.

## 13. Sicherheit

* Client-Secret und Tokens liegen nur in der Instanz (Eigenschaft bzw. Attribute) und erscheinen nie in der Debug-Ausgabe.
* Der WebHook beantwortet nur: leere Prüfaufrufe („OK“, ohne Daten), die Rückleitung mit gültigem `state` und
  Sofortmeldungen mit dem zufälligen Token. Alles andere → 404.
* Zugriff widerrufen: in der Withings-App bzw. im Withings-Konto unter den verbundenen Partner-Apps die App entfernen,
  oder im Entwicklerportal die App löschen bzw. das Secret erneuern. Danach in Symcon neu autorisieren.

## 14. Fehlersuche

| Meldung / Verhalten | Ursache | Abhilfe |
| --- | --- | --- |
| Test im Portal: „Host Unreachable“ | Symcon Connect nicht aktiv, Instanz noch nicht übernommen oder Modul älter als 1.0 build 2 | Connect prüfen, Instanz **Übernehmen**, Modul in Module Control aktualisieren |
| Test im Portal: „Too Many Request: rate limit reached“ | zu viele Tests in kurzer Zeit | einige Minuten warten; der Test ist optional – **Done** geht auch so |
| Withings-Seite: „invalid redirect_uri“ o. ä. | Callback-URL in der App weicht ab | Adresse aus dem Symcon-Formular **zeichengenau** registrieren |
| „Autorisierung fehlgeschlagen“ | Code abgelaufen (30 s), falsches Secret oder state passt nicht (Seite doppelt geöffnet) | Secret prüfen, erneut „Bei Withings autorisieren“ |
| Status „Autorisierung bei Withings erforderlich“ nach Tagen | Refresh-Token ungültig (Zugriff widerrufen, Secret erneuert, Tokens anderweitig benutzt) | erneut autorisieren; bei Parallelbetrieb [Abschnitt 12](#12-bestehende-integrationen-eigene-skripte-parallel-betreiben) beachten |
| „Abfrage fehlgeschlagen“, Debug „Withings status 401“ | Access-Token abgelehnt | erledigt sich beim nächsten Abruf (Token wird erneuert) |
| „Withings status 601“ | zu viele Anfragen | Intervall verlängern |
| Keine neuen Werte | Waage hat noch nicht synchronisiert, oder Messung „nicht zugeordnet“ | Withings-App öffnen bzw. Messung dort der Person zuweisen |
| Sofortmeldung kommt nicht | Domain nicht registriert, Connect aus | Registered URLs prüfen, Häkchen aus/ein → **Übernehmen** (meldet neu an) |

Weitere Details zur API: [Withings Developer Documentation](https://developer.withings.com/api-reference).
