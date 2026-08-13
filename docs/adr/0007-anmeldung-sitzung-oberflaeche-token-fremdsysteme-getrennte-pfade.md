# ADR-0007: Anmeldung — Sitzung für die Oberfläche, Token für Fremdsysteme, getrennte Pfade

**Status:** accepted
**Datum:** 2026-08-13
**Ersetzt:** —
**Ersetzt durch:** —
**Überarbeitet:** —
**Überarbeitet durch:** —
**Verwandt:** [ADR-0002](0002-usenet-nachbildung-statt-torrent.md), [ADR-0004](0004-einladung-zwingend-anmeldedienst-liefert-nur-identitaet.md), [ADR-0005](0005-go-kern-getrennte-oberflaeche-gemeinsames-abbild.md), [ADR-0009](0009-zwei-getrennte-protokollstroeme.md)

## Kontext

Die Oberfläche ist eine getrennte Anwendung ([ADR-0005](0005-go-kern-getrennte-oberflaeche-gemeinsames-abbild.md))
und holt jede Fähigkeit ausschliesslich über `/api/v1`. Damit stellt sich eine Frage, die im
ursprünglichen Entwurf noch nicht beantwortet war: **Wo liegen die Anbieter-Tokens** — die
Zugangsdaten, mit denen der Kern selbst gegenüber Medienserver, Syncthing und weiteren
Fremdsystemen auftritt?

Der IETF-Arbeitsgruppen-Entwurf **„OAuth 2.0 for Browser-Based Applications"**
(`draft-ietf-oauth-browser-based-apps`) zieht dafür eine explizite Rangfolge: Das
**Backend-for-Frontend**-Muster (Tokens bleiben ausschliesslich serverseitig, der Browser bekommt
nur ein Sitzungs-Cookie) gilt als sicherste Variante, gefolgt vom „Token-Mediating Backend"; die
reine Browser-OAuth-Variante — Tokens direkt im Browser — wird als unsicherste Option
eingestuft, „vulnerable to all attack scenarios" bei aktivem, in die Oberfläche eingeschleustem
JavaScript-Schadcode (siehe `docs/research/2026-08-13-standards-und-werkzeuge.md`, Abschnitt 4).
Genau diese unsicherste Variante wäre hier die naheliegende Umsetzung gewesen: Die Oberfläche
spricht ohnehin direkt mit `/api/v1`, ein Token dort abzulegen und die Oberfläche selbst mit dem
Medienserver oder Syncthing reden zu lassen, hätte den geringsten Bauaufwand bedeutet.

Gleichzeitig braucht die Arr-Anbindung aus [ADR-0002](0002-usenet-nachbildung-statt-torrent.md)
einen Zugang **ohne** Browser-Sitzung: Prowlarr, Sonarr und Radarr sprechen Tesserarr über ein
persönliches Token an, das am Nutzer aus [ADR-0004](0004-einladung-zwingend-anmeldedienst-liefert-nur-identitaet.md)
hängt — es gibt also von vornherein **zwei** grundsätzlich verschiedene Anmeldearten an
derselben öffentlichen Schnittstelle.

**Verworfene Varianten, mit ihren echten Vorzügen:**

1. **Browser hält die Tokens.** Der einfachste Bau: kein Sitzungszustand im Kern, die
   Oberfläche kann direkt mit dem Anbieter sprechen, ohne einen vermittelnden Umweg über `/api/v1`.
   Verworfen wegen der Einstufung im zitierten IETF-Entwurf — bei einer Skript-Einschleusung in
   die Oberfläche wären damit sämtliche Anbieter-Zugangsdaten des betroffenen Nutzers unmittelbar
   erreichbar.
2. **Ein Endpunkt, der beide Anmeldearten akzeptiert** und je nach mitgeschickter Art (Cookie
   oder Header) unterschiedlich prüft. Weniger Pfade, weniger Dopplung in der Middleware. Verworfen,
   weil eine Verzweigung nach Anmeldeart innerhalb desselben Codepfads genau die Stelle ist, an der
   später — bei einem neuen Endpunkt, einer Refaktorierung, einem übernommenen Bugfix — jemand einen
   Zweig übersieht und die beiden Anmeldearten unbeabsichtigt vermischt.
3. **`SameSite` allein** als Schutz gegen seitenübergreifende Anfragefälschung (CSRF) für den
   Cookie-Pfad. Verworfen — die OWASP-CSRF-Prevention-Cheat-Sheet-Serie stuft `SameSite` als
   alleinige Verteidigung ausdrücklich als nicht robust genug ein und empfiehlt eine zweite,
   serverseitig geprüfte Schicht zusätzlich.

## Entscheidung

1. Der Kern hält **alle** Anbieter-Tokens (Medienserver, Syncthing, weitere Fremdsysteme). Der
   Browser bekommt ausschliesslich ein Sitzungs-Kennzeichen als `HttpOnly`-Cookie — zusätzlich
   `Secure` (nur über HTTPS übertragen) und `SameSite=Strict` bzw. mindestens `SameSite=Lax`
   (OWASP Session Management Cheat Sheet).
2. Es gibt **zwei getrennte Pfad-Präfixe** unter `/api/v1`. Die Middleware des einen Präfixes
   kennt **ausschliesslich** die Sitzungsprüfung, die Middleware des anderen **ausschliesslich**
   die Tokenprüfung. Keine gemeinsame Verzweigungslogik.
3. **Der Token-Pfad liest das Sitzungs-Cookie nicht** — auch nicht lesend, auch nicht nebenbei,
   auch nicht „für den Fall, dass es später gebraucht wird". Diese Eigenschaft ist prüfbar und
   bekommt einen eigenen, expliziten Test: Ein Request an den Token-Pfad mit gültigem
   Sitzungs-Cookie, aber ohne `Authorization`-Header, muss abgelehnt werden, unabhängig davon, wie
   gültig das mitgeschickte Cookie ist.
4. Der Cookie-Pfad bekommt zusätzlich zum `SameSite`-Merkmal eine **zweite, serverseitig
   geprüfte Schicht** — entweder das Double-Submit-Cookie-Muster (ein zusätzlicher, nicht
   `HttpOnly`-Wert, den das Frontend als Header zurückschickt und der Server gegen das Cookie
   vergleicht) oder ein anwendungsspezifischer Pflicht-Header, den eine fremde Seite ohne
   CORS-Freigabe nicht setzen kann.
5. Der Token-Pfad braucht **keinen** solchen zusätzlichen Schutz. Begründung, damit sie nicht
   verloren geht: Ein individueller `Authorization`-Header wird vom Browser **nie automatisch**
   mitgeschickt — anders als ein Cookie. Eine fremde Seite kann ihn nicht ohne Weiteres selbst
   setzen, weil das einen CORS-Preflight auslöst, der ohne passende Serverfreigabe scheitert.
   CSRF ist strukturell ein Cookie-Problem; ein reiner Header-Pfad ist dagegen von Konstruktion
   her immun. Diese Begründung gehört ausdrücklich in Code-Kommentar und Dokumentation — sonst
   ergänzt später jemand „sicherheitshalber" doch einen CSRF-Schutz für den Token-Pfad und
   koppelt ihn damit implizit wieder an Browser-Zustand, was die Fremdsystem-Anbindung (Prowlarr,
   Sonarr, Radarr senden keine Browser-Cookies) unbrauchbar macht.

## Konsequenzen

**Positiv**

- Der Token-Weg ist **CSRF-immun by construction**, nicht durch eine zusätzliche Prüfung, die
  gewartet werden müsste.
- Anbieter-Tokens sind im Browser **zu keinem Zeitpunkt** vorhanden. Selbst eine
  Skript-Einschleusungslücke in der Next-Oberfläche (siehe [ADR-0005](0005-go-kern-getrennte-oberflaeche-gemeinsames-abbild.md))
  könnte sie nicht erbeuten — es gibt dort schlicht nichts, was gestohlen werden könnte.
- Die Architektur folgt der laut IETF-Entwurf sichersten bekannten Variante (BFF-Muster) statt der
  unsichersten, obwohl letztere der naheliegendere, einfachere Bauweg gewesen wäre.
- Die Trennung ist **strukturell erzwingbar und testbar** (Entscheidungspunkt 3), nicht nur eine
  Konvention, die im Code-Review eingehalten werden muss.

**Negativ**

- **Zwei Pfade bedeuten echte Dopplung in der Middleware-Schicht**: zwei Prüf-Mechanismen, die
  unabhängig voneinander gepflegt, getestet und bei jeder Änderung an der öffentlichen
  Schnittstelle synchron gehalten werden müssen. Das widerspricht in Teilen dem in
  [ADR-0005](0005-go-kern-getrennte-oberflaeche-gemeinsames-abbild.md) formulierten Ziel einer
  möglichst schlanken, einheitlichen `/api/v1` — hier wird bewusst Einheitlichkeit gegen Sicherheit
  eingetauscht.
- **Der Kern braucht einen Sitzungszustand, den er sonst nicht bräuchte.** Ein reiner
  Token-only-Dienst wäre zustandslos gewesen; jetzt kommt ein Sitzungsspeicher hinzu (Ablage,
  Ablauf, Erneuerung, und — nach OWASP-Empfehlung — Rotation der Sitzungs-ID nach jedem
  Login zur Vermeidung von Session-Fixation). Das ist zusätzliche Zustandshaltung, zusätzliche
  Fläche für Fehler, und ein zusätzlicher Ausfallmodus: Fällt der Sitzungsspeicher aus, sind
  Browser-Nutzer betroffen, während der Token-Pfad (Arr-Suite) unbeeinflusst weiterläuft — eine
  Asymmetrie in der Verfügbarkeit zwischen beiden Zugangsarten, die im Betrieb überraschen kann.
- **Die Trennung muss bei jedem neuen Endpunkt bewusst mitgedacht werden — sie erzwingt sich
  nicht von selbst.** Der Test aus Entscheidungspunkt 3 deckt nur ab, dass der bestehende
  Token-Pfad kein Cookie akzeptiert. Er verhindert **nicht**, dass ein künftiger Mitwirkender einen
  neuen Endpunkt versehentlich unter dem falschen Pfad-Präfix registriert — das bleibt eine Frage
  der Disziplin beim Hinzufügen, nicht etwas, das die Architektur allein erzwingt. Ohne einen
  Test, der bei jedem neuen Endpunkt automatisch mitprüft, ist die Garantie nur so stark wie die
  Aufmerksamkeit dessen, der den Endpunkt anlegt.
- **Zentralisierung verschiebt das Schadensausmass, statt es zu verringern.** Beim verworfenen
  „Browser hält Tokens"-Modell wäre bei einer Kompromittierung **eines einzelnen** Nutzer-Browsers
  auch nur dessen eigenes Anbieter-Token betroffen. Im gewählten Modell hält der Kern **alle**
  Anbieter-Tokens **aller** Nutzer an einer Stelle — eine andere, im Kern selbst liegende
  Schwachstelle (etwa ein Pfad-Traversal- oder Server-Side-Request-Forgery-Fehler in einem völlig
  anderen Teil desselben Binaries, siehe [ADR-0005](0005-go-kern-getrennte-oberflaeche-gemeinsames-abbild.md))
  hätte damit potenziell Zugriff auf die Zugangsdaten **aller** Nutzer gleichzeitig, nicht nur auf
  die eines einzelnen. Das ist ein bewusst akzeptierter Kompromiss (ein zentraler, gut geprüfter
  Angriffspunkt statt vieler verteilter, schwächer kontrollierter), aber kein Sicherheitsgewinn
  ohne Gegenseite.
- **Das Double-Submit-Cookie-Muster (falls gewählt) fügt ein zweites Cookie hinzu**, das vom
  Frontend gelesen und als Header zurückgeschickt werden muss — ein zusätzliches bewegliches Teil
  im Anmeldefluss, das selbst wieder korrekt gesetzt, erneuert und bei Sitzungsende invalidiert
  werden muss.
- **`Secure` auf dem Sitzungs-Cookie setzt zwingend HTTPS voraus.** Eine lokale
  Entwicklungsumgebung ohne TLS (etwa ein einfacher `go run` ohne vorgeschalteten Reverse Proxy)
  bricht die Anmeldung, wenn dieser Punkt nicht ausdrücklich in der Entwickler-Dokumentation
  adressiert wird — ein rein betrieblicher, aber realer Reibungspunkt.

**Wann diese Entscheidung neu zu bewerten ist**

- Wenn sich in der Praxis zeigt, dass die Pflege zweier getrennter Middleware-Pfade signifikant
  mehr Fehler produziert als eine sorgfältig getestete, gemeinsame Verzweigung — dann wäre
  Variante 2 (ein Endpunkt, zwei Prüfarten) trotz des im Kontext genannten Risikos neu zu
  bewerten, mit entsprechend härterer Testabdeckung der Verzweigung selbst.
- Wenn der IETF-Entwurf `draft-ietf-oauth-browser-based-apps` final als RFC verabschiedet wird und
  dabei von der hier zitierten Rangfolge abweicht.

## Referenzen

| Dokument | Fundstelle |
|---|---|
| Recherche | `docs/research/2026-08-13-standards-und-werkzeuge.md`, Abschnitte 1, 4 und 4a |
| draft-ietf-oauth-browser-based-apps | `https://datatracker.ietf.org/doc/html/draft-ietf-oauth-browser-based-apps` |
| OWASP Session Management Cheat Sheet | `https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html` |
| OWASP CSRF Prevention Cheat Sheet | `https://cheatsheetseries.owasp.org/cheatsheets/Cross-Site_Request_Forgery_Prevention_Cheat_Sheet.html` |
| Entwurf | `docs/superpowers/specs/2026-08-13-tesserarr-design.md`, Abschnitt „Öffentliche Schnittstelle" |
