# Bericht: Anmeldung an HTTP anbinden (Lücke aus Aufgabe 5)

## Auftrag

Aufgabe 5 hatte `internal/identity/oidc.go` gebaut — `BeginLogin`/`Callback`, PKCE, Anmelde-
zustand, Sitzungsrotation, alles gegen einen nachgebauten Anbieter getestet. Nirgends war das
an einen HTTP-Request angebunden: kein `SetCookie`, keine Route, kein Weg für den Browser, dort
hinzukommen. Aufgabe: die fehlende Verbindung bauen.

## Vorgefundener Zustand

Der Klon wurde von der Spitze der Integrationshistorie (`feat/0.0.1-geruest`) angelegt, darin
bereits enthalten: OIDC-Baustein (Aufgabe 5, `a36eb46`), API-Token-Speicher (`9b257f2`),
Fälschungsschutz (`afe7da2`), `MiddlewarePublic` mit ausdrücklicher Positivliste (`f12c297`).
Die einzige Spur des vorgesehenen Rückruf-Pfads im ganzen Repository war ein Testwert in
`internal/identity/oidc_test.go:301` — dazu unten mehr.

## Vier Endpunkte

| Route | Middleware | Zweck |
|---|---|---|
| `GET /api/v1/app/login/oidc` | öffentlich | gewöhnliche Anmeldung starten |
| `GET /api/v1/app/login/oidc/callback` | öffentlich | Rückruf des Anbieters |
| `GET /invite/{token}` | öffentlich | Einlösung — Kennzeichen ist der Ausweis |
| `POST /api/v1/app/logout` | Sitzungspfad | Sitzung beenden |

Neue Datei `internal/api/login.go`: die vier Handler, plus `sessionCookie(...)` als einzige
Stelle, die das Sitzungs-Cookie baut (Anmeldung setzt es, Abmeldung löscht es über negatives
`MaxAge`) — beide Aufrufer teilen sich damit exakt dieselben drei Merkmale.

## Die Rückruf-Adresse — geklärt, keine neue Variable

Im ersten Anlauf hatte ich `<BASE_URL>/api/v1/app/auth/callback` angenommen (so stand es in
meinem Auftrag). Beim Lesen von `oidc_test.go` fand sich `RedirectURL:
"http://tesserarr.example/api/v1/app/login/oidc/callback"` — der einzige Beleg im Repository
für den vorgesehenen Pfad, und `docs/setup.md` kennt nur drei OIDC-Variablen (`_ISSUER_URL`,
`_CLIENT_ID`, `_CLIENT_SECRET`), keine vierte für den Rückruf. Ich habe den Pfad aus dem
Testwert übernommen (`LoginOIDCCallbackPath` in `login.go`) und die Adresse in `New()` aus
`cfg.BaseURL` **abgeleitet** — genau wie `createInvite` es für den Einlöse-Verweis schon macht,
statt eine eigene Einstellung dafür zu verlangen. Damit ist die Frage aus dem Auftrag
beantwortet: die Vorgabe-Adresse ändert sich nicht, sie bekommt nur keine eigene Variable.

## Das Sitzungs-Cookie

`HttpOnly`, `Secure`, `SameSite=Strict` — wie in ADR-0007, Entscheidungspunkt 1, gefordert. Kein
`Expires`/`MaxAge` beim Setzen (die Ablaufkontrolle liegt serverseitig in
`SessionStore.Lookup`, nicht im Cookie selbst) — dasselbe Muster wie beim
Fälschungsschutz-Cookie in `csrf.go`.

**`Secure` setzt zwingend HTTPS voraus** — in `docs/setup.md` unter „Sign in" dokumentiert
(eigener Warnhinweis), inklusive der Aussage, dass der pure `compose.yaml`-Weg ohne
vorgeschalteten Proxy damit nicht funktioniert. Genau der Punkt, den ADR-0007 im Abschnitt
„Negativ" ausdrücklich als dokumentationspflichtig benennt.

## Die Reihenfolge, an der es hätte scheitern können

Der Rückruf-Endpunkt liegt bewusst unter `PrefixApp` (`/api/v1/app/...`), aber **nicht** hinter
`requireSession` — er trägt `MiddlewarePublic` und steht mit Begründung auf `publicPaths`
(`inventory.go`): „der Anbieter ruft hier zurück ohne eigenes Sitzungs-Cookie — genau das ist
der Sinn einer Anmeldung". Ohne diesen Eintrag hätte `checkPairing` den Rückruf als
fehlplatziert gemeldet (Pfad unter `PrefixApp`, aber nicht `MiddlewareSession`) — das Inventar
hätte den Fehler selbst gefangen, bevor er in Produktion aufgefallen wäre.

## `New()` — Signatur geändert

`New(cfg, st) *API` → `New(ctx, cfg, st) (*API, error)`. Grund: `New` baut jetzt zusätzlich
`identity.NewOIDCLogin` (ein Netzwerk-Umlauf, Anbieter-Erkennung) — das gehört an den Start,
nicht in den Anfragepfad, und ein Dienst, der seinen Anbieter nicht erreicht, soll laut
scheitern statt einen Anmelde-Knopf auszuliefern, der nie funktionieren kann.
`cmd/tesserarr/main.go` zieht dafür die `signal.NotifyContext`-Erzeugung vor den `New`-Aufruf,
statt einen zweiten, nicht abbrechbaren Kontext zu bauen.

`internal/config/config.go` bekam drei neue Pflichtfelder (`OIDCIssuerURL`, `OIDCClientID`,
`OIDCClientSecret`) — mit denselben Prüfungen wie `BaseURL`/`DataDir`/`SessionSecret`.

## Prüfung

- `internal/api/oidc_fake_provider_test.go` — eigener, kleinerer Nachbau des Fake-Anbieters aus
  `internal/identity/oidc_test.go` (Vorlage wie im Auftrag vorgeschlagen). Kleiner, weil die
  Fehlerpfade (fehlendes `id_token`, falsches Publikum, kaputte Ansprüche) schon eine Ebene
  tiefer getestet sind — hier zählt nur, dass ein echter Umlauf ein echtes Cookie erzeugt.
- `internal/api/login_test.go` — alle sechs im Auftrag genannten Fälle: Anmeldung startet mit
  `state`; Rückruf mit gültigem Code setzt das Cookie mit allen drei Merkmalen; falscher `state`
  wird abgelehnt, kein Cookie, **und** der echte `state`+Code funktionieren danach noch (Beweis,
  dass der falsche Versuch den Code nicht verbraucht hat); Einlösung über `/invite/{token}`
  leitet weiter **und** das Kennzeichen kommt nachweislich durch — geprüft nicht am internen,
  für dieses Paket unerreichbaren Anmeldezustand, sondern daran, dass die Einladung nach dem
  vollständigen Umlauf tatsächlich verbraucht ist. Abmelden invalidiert die Sitzung und löscht
  das Cookie, dazu ein Leerlauf- und ein Netzwerkfehler-Fall für `Destroy`. Das Pfad-Inventar
  bleibt sauber — bereits durch den bestehenden `TestRunningServerPassesInventory` mit
  abgedeckt, kein doppelter Test nötig.
- `internal/config/config_test.go` — die drei neuen Pflichtfelder ergänzt, je ein
  Fehlt-Fall zusätzlich zu den bestehenden Erfolgsfällen.

## Zwischenfall: gosec G710 (falsch-positiv, mit Begründung unterdrückt)

`http.Redirect(w, r, login.BeginLogin(inviteToken), ...)` markiert gosec als offene
Weiterleitung, weil `inviteToken` request-nah ist. Tatsächlich landet `inviteToken` **nie** in
der zurückgegebenen Adresse — `BeginLogin` kodiert nur `state` und die PKCE-Challenge hinein,
das Kennzeichen selbst liegt serverseitig im Anmeldezustand. `//nolint:gosec` mit ausführlicher
Begründung direkt an der Stelle, keine anderswo übliche pauschale Unterdrückung.

## Zusammenführung mit der Zwischenzeit

Während der Arbeit landete auf `feat/0.0.1-geruest` `8abf56c` — Abschaffung von
`MiddlewareNone`, `/healthz` auf `MiddlewarePublic` migriert —, dazu `7e76534`, das genau den
falschen Test behob, den ich vorher als vermeintlich vorbestehenden Defekt gemeldet hatte
(Bearer-Kennung stammte fälschlich aus dem Sitzungs-Speicher statt aus `apiTokens`). Zusammen-
führung von `work/feat/0.0.1-geruest` verlief **ohne Konflikt** (automatisches Zusammenführen,
`ort`-Strategie) — die Änderungen lagen in getrennten Bereichen derselben Dateien. Danach
erneut vollständig geprüft: Bau, `go vet`, `gofmt -l .`, `golangci-lint run ./...` (0 Befunde),
`go test ./... -race` (alles grün), Abdeckungs-Schranke (`internal/api` 97,8 % ≥ 80 %,
`internal/config` 98,4 % ≥ 60 %), `commitlint` rückwirkend auf allen drei eigenen Commits (0
Befunde, nach `pnpm install` — der Haken war im frischen Klon zunächst nicht aktiv).

## Nachtrag: drei Punkte aus der Freigabe-Nachricht

Nach dem ersten Abschluss kamen drei verspätete Nachrichten (Antwort auf meine
Vorab-Ankündigung, vor meinem Fertig-Bericht abgeschickt, aber erst danach zugestellt) mit drei
konkreten Punkten:

1. **Betriebsfolge von `New()`s Netzwerkzugriff dokumentieren.** Ergänzt in `docs/setup.md`:
   eigener Warnhinweis direkt nach dem `docker compose up -d`-Befehl — scheitert die
   Anbieter-Erkundung, startet der Dienst gar nicht, `docker compose ps` zeigt „restarting" statt
   „healthy", und das sieht ohne diesen Hinweis nach einem Datenbank- oder Netzwerkproblem aus,
   nicht nach `TESSERARR_OIDC_ISSUER_URL`.
2. **Fünf Compose-Dateien, nicht nur die eine in `docs/setup.md` gezeigte.** Nachgeprüft: **vier
   von fünf** `deploy/compose*.yaml` führten die drei neuen Pflichtvariablen gar nicht —
   `compose.caddy.yaml`, `compose.pangolin.yaml`, `compose.syncthing.yaml`,
   `compose.traefik.yaml`. Jede davon hätte nach dieser Aufgabe einen Container ausgeliefert, der
   sofort mit „TESSERARR_OIDC_ISSUER_URL is required" abbricht. Alle vier nachgezogen, exakt
   dieselben drei Zeilen wie in `compose.yaml`. `docker compose -f <datei> config --quiet` bleibt
   für alle fünf grün — dieser Fund wäre von der bestehenden CI-Prüfung (`compose-validate.yml`)
   nicht gefangen worden, sie validiert nur Syntax, startet den Binary nie. Sonst keine weitere
   Stelle gefunden, die die Variablenliste führt (README.md nennt keine, keine `.env`-Vorlage im
   Repo) — selbst nachgesehen, nicht nur der Zahl in der Nachricht vertraut.
3. **Sitzungs-Kennzeichen ist kein Bearer-Token.** Bereits erfüllt — `Callback` gibt seinen Wert
   ausschliesslich an `sessionCookie(...)` weiter, nirgends an eine `Authorization`-Prüfung. Der
   im ersten Bericht erwähnte, bereits von dir behobene Test (`7e76534`) betraf genau diese
   Trennung, nur auf der Gegenseite (`requireToken`).

Alles erneut geprüft: `go build`/`vet`/`gofmt`/`golangci-lint` weiterhin sauber (mit eigenem
`GOLANGCI_LINT_CACHE`, wie empfohlen), `go test ./... -race` grün, `docker compose config
--quiet` für alle fünf Vorlagen grün, `commitlint` auf den beiden neuen Commits geprüft.

## Nachtrag 2: Fälschungsschutz-Cookie im Rückruf mitsetzen

Nach der Zusammenführung ins Integrationsbuch ein weiterer Punkt: Das Fälschungsschutz-Cookie
wurde bislang nur **beiläufig** gemintet — beim ersten lesenden Zugriff auf dem Sitzungspfad
(`ensureCSRFCookie` in `csrf.go`). Solange es keine Anmeldung gab, war das folgenlos. Jetzt gibt
es einen bestimmten Moment, in dem eine Sitzung entsteht — und die erste ändernde Aktion danach
(z. B. ein Abmelden direkt nach dem Anmelden, ohne vorherigen Katalog-Abruf) hätte kein Cookie
zum Zurückspiegeln gehabt und wäre einmal, ohne erkennbaren Grund, gescheitert.

Testgetrieben behoben: `TestHandleLoginOIDCCallbackAlsoMintsCSRFCookie` in `login_test.go` zuerst
scheitern lassen (kein Fälschungsschutz-Cookie in der Rückruf-Antwort), dann
`handleLoginOIDCCallback` um einen `ensureCSRFCookie(w, r)`-Aufruf direkt neben dem
Sitzungs-Cookie ergänzt (`login.go`). Der beiläufige Weg bleibt unverändert als Rückfall
bestehen — er ist nicht falsch, nur nicht mehr die einzige Quelle.

Erneut vollständig geprüft (Bau, `vet`, `gofmt`, `golangci-lint` 0 Befunde, `go test ./... -race`
grün über alle acht Pakete, Abdeckungs-Schranken erfüllt, `commitlint` auf dem neuen Commit).

## Nachtrag 3: Oberfläche ausliefern, falsche Pfad-Annahme geraderücken

`git pull work feat/0.0.1-geruest` (`786770c..ceb7e38`, Schnelllauf) — die Oberfläche (`web/`,
`internal/web/`) war eingebaut, Aufgabe 7 Schritte 3–4 waren absichtlich offen gelassen, damit
zwei parallele Bearbeiter nicht in `router.go` kollidieren.

**Falsche Annahme in `web/src/lib/auth-links.ts`**: Der Oberflächen-Bearbeiter hatte `/auth/login`
und `/auth/callback` **außerhalb** von `/api/v1` vermutet — analog zu `/invite/`, ausdrücklich als
Annahme markiert. Tatsächlich liegen beide Endpunkte unter `/api/v1/app/login/oidc`, mit
`MiddlewarePublic` statt `MiddlewareSession` — ein Pfad kann öffentlich sein und trotzdem unter
dem Sitzungspräfix liegen, das eine folgt aus dem anderen nicht. Angeglichen: die Oberfläche folgt
dem Code. `page.test.tsx` prüft den Link-`href` gegen die Konstante `LOGIN_START_URL`, nicht gegen
einen wörtlichen Pfad — blieb dadurch grün, ohne selbst geändert zu werden (Vitest-Suite erneut
vollständig gelaufen: 34 Tests, unveränderte Abdeckung 100/97,67/100/100).

**Kein fachlicher Einwand nötig** — die Alternative (Anmeldung bewusst außerhalb `/api/v1`) wäre
eine Entwurfsentscheidung für dich gewesen, aber die vorhandene Platzierung unter
`/api/v1/app/login/oidc` mit `MiddlewarePublic` ist bereits stimmig mit dem bestehenden Muster
(`publicPaths`-Positivliste) — kein Grund, sie infrage zu stellen.

**Ausliefern der eingebetteten Dateien** (`internal/api/static.go`, neu): `newStaticHandler`
kapselt `http.FileServerFS` um genau eine Ergänzung — ein unbekannter Pfad bekommt die **eigene**
kompilierte `404.html` der Oberfläche bei Status 404, nicht die Katalogseite bei 200. Begründung:
diese Anwendung ist drei getrennt gebaute statische Seiten (`trailingSlash: true`), kein
client-geroutetes Ein-Seiten-Programm, bei dem ein unbekannter Pfad noch von einem
Browser-Router aufgelöst werden könnte — die Katalogseite an einer fremden Adresse auszuliefern
würde nur ihre eigene Logik (Sitzungsprüfung, Weiterleitung) an der falschen Stelle auslösen.

Registriert als `"/"` — die unspezifischste Route, die `net/http.ServeMux` kennt. Kein
Verschluckungsrisiko: jede andere Route ist spezifischer, und der Mux löst immer zur
spezifischsten passenden Route auf, unabhängig von der Registrierungsreihenfolge.
`TestStaticRouteDoesNotShadowAPIRoutes` hält genau das fest, nicht nur implizit über bestehende
Tests. In `inventory.go` ein neuer `publicPaths`-Eintrag für `"/"` — **bewusst `prefix: false`**,
anders als der bestehende `/invite/`-Eintrag: `isPublicPath` sieht nur die EINE registrierte
Route-Pattern-Zeichenkette `"/"`, nie eine eingehende Anfrage-URL — ein Präfix-Eintrag hier wäre
gefährlich gewesen (jeder Pfad beginnt mit `"/"`, also hätte `isPublicPath` plötzlich JEDE andere
Route ebenfalls getroffen und `MiddlewarePublic` für sitzungs-/token-geprüfte Routen verlangt).
Ausführlich im Code kommentiert, genau an der Stelle.

**Handprobe — ehrlich, mit Grenze:** Kein registrierter OIDC-Client verfügbar in dieser Umgebung.
Statt die Probe deshalb auszulassen: lokalen Server gegen den echten, öffentlichen
Google-Discovery-Endpunkt (`https://accounts.google.com`) gestartet, mit einer erfundenen
Client-Kennung. Per Playwright im echten Browser: `/` → Weiterleitung zu `/login/` (Sitzungsprüfung
schlägt korrekt mit 401 fehl, wie erwartet für einen nicht angemeldeten Besucher) → Klick auf
„Continue to sign in" → **landet tatsächlich bei Google**, mit korrekt gebildeten Parametern
(`client_id`, `code_challenge`, `redirect_uri=http://127.0.0.1:8099/api/v1/app/login/oidc/callback`,
`state`) → Googles echte Fehlerseite „Error 401: invalid_client" (erwartet — die Client-Kennung ist
nicht real registriert). Das beweist: DNS/TLS/Netzwerk bis zum echten Anbieter funktionieren, die
Weiterleitung ist korrekt geformt. **Was NICHT geprüft wurde**: der vollständige Rundlauf
(Anmeldung abschließen, Rückruf, Cookie setzen) — dafür fehlt ein echter, registrierter
OIDC-Client. Zusätzlich: unbekannter Pfad im Browser aufgerufen → Status 404, eigene `404.html`
angezeigt (Titel „Tesserarr"), wie vorgesehen. Vollständiger Drei-Stufen-`docker build -f
deploy/Dockerfile .` einmal komplett durchgebaut (57,9 s Node-Stufe, 86,6 s Go-Stufe, Abbild danach
entfernt) — zusätzlich zur Handprobe, nicht als Ersatz dafür.

Zwei Nebenfunde bereinigt, nicht committet: `next build` hatte `web/next-env.d.ts` und
`web/tsconfig.json` automatisch umformatiert (Next-16-Werkzeug-Nebenwirkung, keine inhaltliche
Änderung mit Bezug zu dieser Aufgabe) — beide vor dem Commit zurückgesetzt.

Zwei neue Commits: `feat(api): serve the embedded web interface at "/"` (Go-Seite komplett:
`static.go`, `static_test.go`, `router.go`, `router_test.go`, `inventory.go`) und `fix(web): point
login links at the real oidc routes` (`auth-links.ts`). Erneut vollständig geprüft: Bau, `vet`,
`gofmt`, `golangci-lint` (0 Befunde, eigener `GOLANGCI_LINT_CACHE`), `go test ./... -race` grün
über alle neun Pakete (`internal/web` jetzt mit dabei, 100 % Abdeckung, Schranke 60 %),
`docker compose config --quiet` für alle fünf Vorlagen, `commitlint` auf beiden Commits.

## Nachtrag 4: die vier Sicherheits-Audit-Befunde

Prüflauf `~/security-audit-skill/tesserarr/run-1/findings.json` — 0 kritisch, 0 hoch, 4 mittel.
Alle vier behoben, in genau der vom Auftraggeber vorgegebenen Reihenfolge (Nutzen pro Aufwand),
jeder mit eigenem Commit, testgetrieben, jeder Test scheitert nachweislich vor dem jeweiligen
Fix — siehe unten, wo geprüft, wie geprüft.

1. **Einladungs-Kennzeichen-Länge begrenzt** (`maxInviteTokenLen = 128` in
   `internal/api/login.go`). Ohne Begrenzung wanderte ein bis zu ~1-MiB-grosses Kennzeichen
   (Gos `MaxHeaderBytes`-Grenze war die einzige vorherige Schranke) unverändert zehn Minuten in
   den Speicher — gemessen 4 100-facher Verstärkungsfaktor, 474 MB Speicherzuwachs durch 300
   Anfragen in 14,5 Sekunden. Echte Kennzeichen sind 64 Zeichen (32-Byte-Hex); 128 ist
   grosszügiger Spielraum. Test: ein Kennzeichen einen Byte über der Grenze wird mit 400
   abgelehnt, eines an der Grenze funktioniert weiter normal.

2. **Sitzungs-Cookie von `SameSite=Strict` auf `SameSite=Lax`.** ADR-0007 selbst erlaubt das
   bereits („SameSite=Strict bzw. mindestens SameSite=Lax") — keine ADR-Änderung nötig. `Strict`
   verhinderte die Zustellung des Cookies auf dem seitenübergreifenden Rückruf vom externen
   Anbieter (RFC 6265bis), wodurch `previousSessionToken` bei praktisch jeder echten Anmeldung
   leer ankam und `Rotate("", userID)` nichts löschte — eine vorbestehende, anderweitig erlangte
   Sitzung überlebte die eigene Neuanmeldung, entgegen der eigenen Kommentar-Absicht im Code.
   `Lax` schliesst die eigentliche CSRF-Lücke weiterhin (der Doppel-Absende-Schutz in `csrf.go`
   deckt das bereits ab). Test: direkte Prüfung des `SameSite`-Attributs — von Hand gegengeprüft,
   dass er am unveränderten Attribut scheitert, `net/http`s Testclient modelliert echtes
   Browser-`SameSite`-Verhalten nicht, das ist der ehrliche Grenzwert dessen, was diese Testebene
   zeigen kann.

3. **`state` an den anfragenden Browser gebunden** (`internal/api/login.go`, neues Cookie
   `tesserarr_oauth_state`) — der gewichtigste Befund. `identity.OIDCLogin.Callback` prüfte
   bisher nur „von uns ausgestellt" und „unverbraucht", nie welcher Browser es vorlegt. Ein
   Angreifer mit gültiger Identität konnte die eigene, echte Anmeldung abschliessen, die
   Rückruf-Adresse VOR dem Laden abfangen und als gewöhnlichen Link an ein Opfer weitergeben —
   dessen Browser bekam dann eine Sitzung für das Konto des ANGREIFERS. PKCE schliesst das
   nicht (bindet den Code an den Server, nicht an den Browser). Fix: kurzlebiges,
   `HttpOnly`+`Secure`+`SameSite=Lax`-Cookie bei Anmeldestart, zeitkonstanter Vergleich
   (`constantTimeEqual`, dieselbe Technik wie beim Fälschungsschutz-Cookie) beim Rückruf, vor
   jedem Zugriff auf `Callback`. Test: den Angriff wortwörtlich nachgestellt — eigenen,
   vollständigen Anmelde-Rundlauf als „Angreifer" abgeschlossen, das Rückruf-Ergebnis dann OHNE
   das zugehörige Cookie eingereicht (simuliert das Opfer-Browserfenster) → muss abgelehnt
   werden. Von Hand die Prüfung deaktiviert, Test scheitert mit `302` (Sitzung fürs
   Angreifer-Konto würde tatsächlich gesetzt), dann wiederhergestellt.

4. **Einladungs-Einlösung in derselben Transaktion wie die Kontoanlage** (`internal/invite/invite.go`,
   `internal/identity/oidc.go`). Bisher wurden `users`/`identities` committet, BEVOR
   `invite.Redeem` versucht wurde; scheiterte das, war die einzige Absicherung ein einzelnes,
   nicht wiederholtes `DELETE`, dessen eigener Fehler verworfen wurde. Ein Prozessabsturz im
   schmalen Fenster dazwischen — ein gewöhnliches Betriebsereignis bei einem selbst gehosteten
   Dienst — liess ein Konto dauerhaft bestehen, das nie eine echte Einladung eingelöst hatte
   (Bruch von ADR-0004). `invite.Redeem`/`explainRejection` nehmen jetzt eine Schnittstelle
   (`execQuerier`, das `Exec`/`QueryRow`-Duo) statt eines konkreten `*sql.DB` — jeder bestehende
   Aufrufer bleibt unverändert (`*sql.DB` erfüllt sie automatisch), aber `createUserFromInvite`
   kann jetzt `invite.Redeem(tx, ...)` innerhalb derselben Transaktion aufrufen, vor dem Commit;
   das gesonderte `DELETE` entfällt vollständig. Test: ein synchroner Aufruf mit erfundenem
   Kennzeichen hätte den ursprünglichen Fehler NIE gefangen (das `DELETE` lief im Normalfall
   korrekt) — stattdessen ein Auslöser, der das `DELETE` blockiert (Datenbank-Trigger, dieselbe
   Technik wie die bestehenden INSERT-Fehlschlag-Tests in derselben Datei), bestätigt scheiternd
   (verwaistes Konto bleibt bestehen) vor dem Fix, grün danach.

Erneut vollständig geprüft nach jedem der vier Fixes einzeln UND am Ende gesamt: Bau, `vet`,
`gofmt`, `golangci-lint` (0 Befunde, eigener `GOLANGCI_LINT_CACHE`), `go test ./... -race` grün
über alle neun Pakete, Abdeckungs-Schranken erfüllt (`internal/api` 96,4 %, `internal/identity`
99,4 %), `docker compose config --quiet` für alle fünf Vorlagen, `commitlint` auf allen vier
Commits.

## Commits

1. `feat(config): require oidc provider settings`
2. `feat(identity): wire the oidc login into the http surface`
3. `docs(setup): correct the redirect uri, note the https requirement`
4. `merge: integrate upstream feat/0.0.1-geruest`
5. `docs(sdd): add the report for the login task`
6. `fix(deploy): add the three oidc settings to every compose variant`
7. `docs(setup): document the provider-unreachable startup failure`
8. `docs(sdd): record the follow-up from the review message`
9. `fix(api): mint the csrf cookie together with the session cookie`
10. `docs(sdd): record the csrf-cookie fix in the login report`
11. `feat(api): serve the embedded web interface at "/"`
12. `fix(web): point login links at the real oidc routes`
13. `fix(api): cap the invite token length to close a memory-exhaustion path`
14. `fix(api): change the session cookie's samesite from strict to lax`
15. `fix(api): bind the oidc login state to the initiating browser`
16. `fix(identity): redeem the invite inside the same transaction as the account`

## Offen / für die Zusammenführung

- Zweig `b-login` in `/opt/repos/tesserarr-b-login`, **nicht gepusht** — Anweisung war
  ausdrücklich, nicht zu pushen. Zuletzt auf `git pull work feat/0.0.1-geruest` gebracht
  (`786770c..ceb7e38`).
- Aufgabe 7 Schritte 3–4 (Ausliefern der eingebetteten Dateien, Handprobe) sind mit diesem
  Nachtrag erledigt — Aufgabe 7 ist damit vollständig.
- **Offen bleibt Aufgabe 8** (Playwright-Oberflächenprüfung als hartes Tor, sieben Prüfungen,
  Skill `ui-verification`) — ausdrücklich NICHT Teil dieser Aufgabe, setzt nur Aufgabe 7 voraus,
  die jetzt erfüllt ist.
- `router.go` trägt jetzt acht registrierte Routen (sieben aus den vorigen Nachträgen plus `"/"`)
  — relevant für jeden weiteren Zweig, der dieselbe Datei anfasst.
- Die vier Sicherheits-Audit-Befunde sind behoben (Nachtrag 4), aber **nicht selbst
  abgenommen** — der Prüfer prüft gegen, wie angekündigt.
