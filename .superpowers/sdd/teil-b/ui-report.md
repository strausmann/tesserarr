# Bericht: Die Oberfläche — Anmeldung und leerer Katalog (Teil B, Aufgabe 7)

## Auftrag

Schritt 1 (Next-Anwendung anlegen, statischer Export, `go:embed` der Ausgabe) und Schritt 2
(Vitest+jsdom für jede Datei mit eigenem Zustand — Cookie-Lesen für den Double-Submit-Header).
Schritt 3 (Ausliefern der eingebetteten Dateien aus dem Go-Kern) und Schritt 4 (Handprobe)
ausdrücklich **nicht** mein Auftrag — die macht jemand nach dem Zusammenführen.
`internal/api/router.go` bewusst nicht angefasst (paralleler Kollege bindet die Anmeldung an
HTTP).

## Ergebnis

Erledigt, committet auf `b-ui` (Commit `7d77239`), **nicht gepusht, nicht zusammengeführt**.

- `web/` — Next.js-App (App Router, TypeScript), statischer Export (`output: 'export'`), drei
  Ansichten: `/` (Katalog), `/login/`, `/logout/`.
- `internal/web/` — neues Paket, bettet die Ausgabe per `go:embed` ein, `FS() (fs.FS, error)`.
- Vitest+jsdom: 34 Tests, 5 Dateien, **100 % Statements/Funktionen/Zeilen, 97,67 % Zweige**.
- `deploy/Dockerfile` und `.github/workflows/test.yml` angepasst (Begründung unten).
- `docker build -f deploy/Dockerfile .` **einmal komplett durchgebaut** (Next-Kompilierung der
  drei Seiten, Einbettung, Go-Build) — Abbild danach wieder entfernt, keine Handprobe
  (Klick-Test) gemacht, das bleibt Schritt 4.

## Die drei Ansichten

- **Katalog (`/`)**: prüft den Sitzungsstatus client-seitig gegen `GET /api/v1/app/session`.
  Nicht angemeldet → Weiterleitung nach `/login/` statt eines leeren Katalogs, der aussieht wie
  "du bist drin, es ist nur nichts da". Angemeldet → die geforderte Erklärung, dass der
  Medien-Weg erst mit 0.1.0 kommt.
- **Anmeldung (`/login/`)**: ein Verweis auf die (noch nicht verdrahtete) Anmeldestart-Route —
  siehe Abschnitt "Offene Annahme" unten.
- **Abmelden (`/logout/`)**: **Bestätigungs-Ansicht, kein Auto-Trigger beim Laden** — reines
  Navigieren dorthin darf nie selbst die Sitzung beenden. Erst der Klick auf "Confirm log out"
  löst `POST /api/v1/app/logout` mit dem Double-Submit-Header aus.

## Anforderungen aus dem Konzept — wie umgesetzt

Alle vier Punkte aus dem Konzept sind umgesetzt, keiner übersprungen:

- **Kein waagerechtes Scrollen in keiner Breite**: `box-sizing: border-box` global,
  `width: min(100%, 40rem)` für den Inhaltsbereich, keine festen Breiten in Pixeln. Nicht
  automatisiert gegen 360/768/1440 geprüft — das ist Aufgabe 8 (Playwright, ADR-0006), hier nur
  die CSS-Grundlage dafür gelegt.
- **Hell und dunkel**: `prefers-color-scheme` mit eigenem Farbschema für beide.
- **Sichtbarer Tastatur-Fokus**: `:focus-visible` mit sichtbarem Ring, **kein** `:focus { outline:
  none }` irgendwo.
- **Kein Overseerr-Design übernommen**: eigenes, von Grund auf geschriebenes Layout — nur die
  Konzept-Idee "Katalog-Ansicht" ist geliehen, keine Zeile CSS/Markup.

## Entscheidung: `internal/web/dist` statt `web/out` — und warum das Dockerfile angefasst wurde

Das war der eigentliche Kern von Schritt 1, nicht nur Nebensache. `go:embed`-Muster dürfen
**nicht** aus dem eigenen Paketverzeichnis herausreichen (kein `..`) — die Next-Export-Ausgabe
musste also **innerhalb** von `internal/web/` liegen, damit die Direktive dort überhaupt
kompiliert.

Das von Teil A bereits committete `deploy/Dockerfile` kopierte die Node-Stufen-Ausgabe aber nach
`./web/out` (repo-relativ) — ein Ort, den `internal/web/` aus genau diesem Grund nicht erreichen
kann. Ich habe die **eine** betroffene `COPY`-Zeile im Go-Build-Stage von `./web/out` auf
`./internal/web/dist` umgestellt (mit Begründungskommentar direkt daneben). Das ist die einzige
Zeile, die ich in `router.go`-fremdem Terrain angefasst habe — bewusst, weil ohne diese
Korrektur die Produktions-Abbilder für immer den Platzhalter statt der echten Oberfläche
eingebettet hätten, ein stiller Fehler, den niemand vor dem ersten echten Deploy bemerkt hätte.

**`internal/web/dist/index.html`** ist ein eingecheckter Platzhalter (keine echte Oberfläche) —
notwendig, weil `go:embed` ein nicht-leeres Verzeichnis braucht und ein frischer Klon (ohne
Node-Build) sonst gar nicht kompiliert. Der Docker-Build überschreibt ihn vollständig innerhalb
des Build-Containers, **nie** im Arbeitsverzeichnis eines Entwicklers. `scripts/build-web.sh`
macht denselben Schritt für lokale Entwicklung.

**Verifiziert, nicht nur behauptet:**
```
$ go build ./...      # frischer Zustand, KEIN vorheriger Frontend-Build
BUILD_OK
$ go test ./... -race
ok  	.../internal/web	1.072s	coverage: 100.0% of statements
$ docker build -f deploy/Dockerfile -t tesserarr-ui-build-check:test .
...
#18 62.35 Route (app)
#18 62.35 ┌ ○ /
#18 62.35 ├ ○ /_not-found
#18 62.35 ├ ○ /login
#18 62.35 └ ○ /logout
...
#25 DONE 23.2s   (Abbild erfolgreich exportiert, danach entfernt)
```

## Offene Annahme: Anmeldestart-Route

`internal/api/router.go` registriert bislang **keine** Route, die `OIDCLogin.BeginLogin`
tatsächlich an HTTP bindet — das ist die parallele Arbeit, die ich laut Auftrag nicht anfassen
sollte. Die Anmelde-Schaltfläche verlinkt auf `/auth/login` (mit `/auth/callback` als
zugehöriger Rückruf-Adresse), beides zentral in **einer** Datei
(`web/src/lib/auth-links.ts`) mit ausführlichem Kommentar zur Annahme. Begründung der Wahl:
außerhalb von `/api/v1` (Chicken-egg — vor der Anmeldung existiert keine Sitzung, die
`requireSession` bestehen könnte) und außerhalb aller drei eigenen Next-Routen. Falls die
tatsächliche Route anders benannt wird: **eine** Datei ändern, keine Suche nötig.

## Der Flaky-Test — Ursache und Fix, weil es die Zeit gekostet hat, die es gekostet hat

`page.test.tsx` (Katalog-Seite) schlug reproduzierbar in ~30–50 % der Läufe fehl —
`fetchSessionStatus()` gab `undefined` statt eines Promise zurück. **Nur wenn die ganze
Testsuite lief, nie in Isolation.** Nach Ausschluss mehrerer falscher Spuren (React-Scheduler
+ Worker-Threads, `pool: "forks"`, `fileParallelism: false` — keine davon hat es
zuverlässig behoben) war die tatsächliche Ursache: **zwei Testdateien mockten denselben
Modul-Pfad `"@/lib/api"` mit unterschiedlichem Umfang** (`page.test.tsx` nur
`fetchSessionStatus`, `logout/page.test.tsx` nur `logout`) — unter Vitests
Standard-Wiederverwendung von Fork-/Worker-Prozessen über Dateien hinweg konnte das eine
Mock-Objekt gelegentlich das andere überschreiben.

**Fix:** Beide Testdateien mocken jetzt **nicht mehr das Modul**, sondern stubben `global.fetch`
direkt (`vi.stubGlobal("fetch", ...)`) — dieselbe Grenze, an der `src/lib/api.test.ts` von
Anfang an zuverlässig war. Seit dieser Umstellung: **15+ aufeinanderfolgende, vollständig grüne
Suite-Läufe**, keine Ausnahme mehr. Für die Zukunft dokumentiert (Kommentar direkt im Testcode):
Modul-Mocks auf demselben Spezifizierer aus verschiedenen Dateien sind in diesem Projekt ein
bekannter Risikofaktor.

## Testabdeckung — konkrete Zahlen

```
% Coverage report from v8
----------------|---------|----------|---------|---------|-------------------
File            | % Stmts | % Branch | % Funcs | % Lines |
----------------|---------|----------|---------|---------|
All files       |     100 |    97.67 |     100 |     100 |
 lib            |     100 |    95.65 |     100 |     100 |
  csrf.ts       |     100 |     90.9 |     100 |     100 |  (Zeile 28 offen)
----------------|---------|----------|---------|---------|
```

Einzige verbliebene ungetestete Zeile: `csrf.ts:28`, der `typeof document === "undefined"`-Zweig
(SSR-Schutz) — in jsdom strukturell nicht ohne das globale `document`-Objekt zu entfernen prüfbar,
niedriger Wert für diese Prüfung. Schwellen in `vitest.config.ts` auf 95/95/90/95 gesetzt (deutlich
über den Kategorie-Mindestwerten der Regel: 85/80/75) — die Suite erfüllt sie mit Reserve.

Go-Seite: `internal/web` 100 % Statements, gated in `test.yml` mit `internal/web:60`
(Adapter/Glue-Code-Kategorie).

## Was noch fehlt (bewusst außerhalb meines Auftrags)

1. Verdrahtung der drei Next-Routen mit `internal/api/router.go`/`app.go`/`static.go`
   (Schritt 3).
2. Handprobe: Abbild bauen, im Browser klicken, Anmelde-Weiterleitung live sehen (Schritt 4).
3. Sobald die echte Anmeldestart-Route feststeht: `web/src/lib/auth-links.ts` abgleichen (eine
   Datei).
4. Aufgabe 8 (Playwright-Oberflächenprüfung) prüft die vier Konzept-Anforderungen automatisiert
   gegen echte Breiten — hier nur die CSS-Grundlage dafür gelegt, nicht automatisiert verifiziert.
