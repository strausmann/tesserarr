# Bericht: eigener API-Token-Speicher (Teil B)

## Auftrag

`requireToken` löste ein Bearer-Kennzeichen bislang über denselben `identity.SessionStore`
auf wie `requireSession` — die Prüfpfade waren getrennt, die Speicher nicht. Damit fehlte die
im Konzept ("Anmeldung und Sitzungen") geforderte **zweite, unabhängige Schranke**: ein
Sitzungs-Kennzeichen war als Bearer gültig und umgekehrt. Auftrag war, einen eigenen
`internal/apitoken`-Speicher zu bauen, `requireToken` darauf umzustellen und mit zwei
Schranken-Tests zu belegen, dass die Trennung wirklich greift.

## Ergebnis

Erledigt. Alle drei Bausteine aus dem Auftrag stehen:

1. **Wanderung** `internal/store/migrations/0003_api_tokens.sql` — Tabelle `api_tokens`
   (`token_hash` Primärschlüssel, `user_id` Fremdschlüssel, `label`, `created_at`,
   `last_used_at`). Keine `expires_at` — begründet unten.
2. **`internal/apitoken`** (neues Paket) — `Store` mit `Create`, `Lookup`, `Revoke`, `List`.
3. **`requireToken`** in `internal/api/middleware.go` löst jetzt über `a.apiTokens.Lookup`
   auf, nicht mehr über `a.sessions.Lookup`.

## Rot-Phase gegen den jetzigen Stand (wie verlangt)

Beide Schranken-Tests wurden zuerst geschrieben und **gegen den unveränderten
`requireToken`** ausgeführt (nur `a.apiTokens`-Feld existierte bereits, damit ein API-Token
überhaupt gemintet werden konnte — der Zugriff in `requireToken` selbst war noch nicht
umgestellt):

```
=== RUN   TestSessionTokenAsBearerIsRejected
    middleware_test.go:78: status = 200, want 401 (a valid session token must not authenticate the token path)
--- FAIL: TestSessionTokenAsBearerIsRejected (0.09s)
=== RUN   TestAPITokenAsSessionCookieIsRejected
--- PASS: TestAPITokenAsSessionCookieIsRejected (0.10s)
```

- **Test 1 (Sitzungs-Kennzeichen als Bearer → muss abgelehnt werden) ist wie erwartet
  gescheitert** (`200` statt `401`) — das ist der belegte Defekt: `requireToken` prüfte gegen
  `a.sessions`, ein gültiges Sitzungs-Kennzeichen authentifizierte also den Token-Pfad.
- **Test 2 (API-Kennzeichen als Sitzungs-Cookie → muss abgelehnt werden) bestand bereits
  vorher.** Das ist kein Widerspruch zur Auftragsformulierung "weil beide denselben Speicher
  benutzen" — die Aussage stimmt für die Bearer-Richtung (`requireToken` griff auf
  `a.sessions` zu), nicht symmetrisch für die Cookie-Richtung: `requireSession` hat **nie**
  auf `a.apiTokens` zugegriffen, weder vorher noch nachher. Ein frisch gemintetes
  API-Kennzeichen existiert nur in `api_tokens`, `a.sessions.Lookup` findet dort naturgemäß
  nichts — die Ablehnung war also schon vor der Umstellung korrekt, nur aus einem anderen
  Grund (das API-Token-Konzept existierte für `requireSession` schlicht nicht) als nach der
  Umstellung (bewusste Trennung der Speicher). Ich melde das so, statt die Beobachtung an die
  Erwartung anzupassen.

Nach der Umstellung (`requireToken` → `a.apiTokens.Lookup`) sind beide Tests grün, ebenso der
positive Gegenbeleg `TestEachPathAcceptsOnlyItsOwnCredential` (neu aufgeteilt: Sitzungs-Pfad
mit einem `a.sessions`-Kennzeichen, Token-Pfad mit einem `a.apiTokens`-Kennzeichen — vorher
nutzte dieser Test EIN Kennzeichen für beide, was nach der Trennung nicht mehr stimmt).

## `last_used_at`: Entscheidung und Begründung

**Entscheidung:** `Lookup` schreibt `last_used_at` bei **jedem** Aufruf fort — unbedingt
(kein Drosseln auf z. B. "höchstens einmal pro Stunde") und **best effort** (Fehler beim
Schreiben werden ignoriert, ändern nichts am bereits getroffenen Auth-Verdikt).

**Warum unbedingt, nicht auf einer separaten Route:** Die Auth-Middleware ist die einzige
Stelle, die überhaupt weiß, dass ein Kennzeichen gerade benutzt wurde. Ein separater
Trigger (Cron-Job, expliziter Endpunkt) würde entweder dasselbe UPDATE aus einem
ungenaueren Signal nachbauen, oder — realistischer — nie aufgerufen werden. Der Nutzen ist
konkret: `List()` gibt `LastUsedAt` zurück, damit ein Nutzer ein von Sonarr stündlich
abgefragtes Kennzeichen von einem seit einem Jahr unbenutzten unterscheiden und Letzteres
gefahrlos widerrufen kann — das ist der eigentliche Zweck des Felds.

**Warum unbedingt, nicht gedrosselt:** Drosseln würde vor jedem Schreiben ein Lesen des
bisherigen Werts erzwingen (conditional read-then-maybe-write) — mehr Rundläufe, nicht
weniger, für eine selbst gehostete Einzelmandanten-App, deren Token-Pfad-Verkehr
(Prowlarr/Sonarr/Radarr-Polling) im Bereich von Minuten liegt, nicht Anfragen pro Sekunde.
SQLites WAL-Modus plus der bereits in `internal/store` konfigurierte `busy_timeout` fangen
diesen einen zusätzlichen Schreibvorgang pro authentifizierter Anfrage ohne relevante
Kontention ab.

**Warum best effort:** Dasselbe Idiom wie beim bereits bestehenden Löschen abgelaufener
Sitzungen in `identity.SessionStore.Lookup` — ob das Fortschreiben gelingt, ändert nichts am
bereits ermittelten Ergebnis der SELECT-Abfrage davor. Ein vorübergehender Schreibfehler darf
nie ein gültiges Kennzeichen in eine gescheiterte Authentifizierung verwandeln.

**Keine `expires_at`:** anders als Sitzungen tragen API-Kennzeichen keine Ablaufzeit. Eine
Sitzung steht stellvertretend für eine interaktive Anmeldung im Browser und soll ablaufen;
ein persönliches API-Kennzeichen ist das Kennzeichen, mit dem Prowlarr/Sonarr/Radarr einmal
konfiguriert werden und sich dauerhaft darauf verlassen (ADR-0007) — ein erzwungener Ablauf
würde bedeuten, dass der Nutzer denselben Wert wiederkehrend in drei fremde Oberflächen
einträgt, ohne Sicherheitsgewinn gegenüber einem jederzeit möglichen `Revoke`.

## Abdeckung und Werkzeuge

- `go build ./...`, `go vet ./...`: sauber.
- `go test ./... -race`: alle Pakete grün.
- `internal/apitoken`: **99,0 %** (Gate-Schwelle 90 %, wie bei `internal/identity` gesetzt).
  Nicht abgedeckt: der `rows.Err()`-Zweig in `List` (aus demselben Grund wie bei
  `identity.SessionStore` nicht erzwingbar ohne den Testlauf selbst zum Absturz zu bringen)
  sowie ein Fehlerzweig in `Create`, der nur bei einem Datenbankfehler nach dem Insert
  greifen würde.
- `internal/store`: `wantSchemaVersion` von 2 auf 3 angehoben (die einzige nötige Anpassung
  an bestehenden Tests außerhalb von `internal/api`).
- `.github/workflows/test.yml`: `internal/apitoken:90` zum Coverage-Gate ergänzt.
- `golangci-lint run ./...`: 0 Findings.
- `gofmt -l .`: keine unformatierten Dateien.

## Werkzeug-Check vor Arbeitsbeginn

`golangci-lint --version` lief (2.12.2). `git config core.hooksPath` war in diesem frischen
Klon zunächst **leer** — `npm install` musste zuerst laufen, damit Husky den Commit-Hook
aktiviert (`.husky/_`, siehe `eigener-arbeitsbereich-je-bearbeiter.md`, Abschnitt "Nebenwirkung:
Im frischen Klon schweigen die Commit-Prüfungen"). Danach war `core.hooksPath` gesetzt.

## Commit

Kleine, selektiv gestagte Commits (Migration+Paket, Middleware-Umstellung+Tests,
Store-Test-Anpassung, CI-Gate) — siehe `git log`.
