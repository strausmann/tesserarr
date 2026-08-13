# ADR-0005: Go-Kern, getrennte Oberfläche, ein gemeinsames Abbild

**Status:** accepted
**Datum:** 2026-08-13
**Ersetzt:** —
**Ersetzt durch:** —
**Überarbeitet:** —
**Überarbeitet durch:** —
**Verwandt:** [ADR-0002](0002-usenet-nachbildung-statt-torrent.md), [ADR-0006](0006-oberflaechenpruefung-als-hartes-tor.md), [ADR-0007](0007-anmeldung-sitzung-oberflaeche-token-fremdsysteme-getrennte-pfade.md)

## Kontext

Für den Kern von Tesserarr (Zustandsautomat, Anbindung an Medienserver und Syncthing,
Nachbildung von Indexer und Download-Client, öffentliche Schnittstelle) und für die
Weboberfläche mussten Sprache und Bauform gewählt werden. Zwei Fragen standen dabei getrennt
zur Entscheidung.

**Sprache des Kerns.** Die Arr-Suite selbst ist in .NET geschrieben. Naheliegend wäre daher,
sich sprachlich anzugleichen. Dagegen spricht: Die Arr-Anbindung, die Tesserarr braucht
([ADR-0002](0002-usenet-nachbildung-statt-torrent.md)), läuft ausschliesslich über HTTP
(Newznab- und SABnzbd-kompatible Schnittstellen) und ist damit **sprachneutral** — .NET wäre
nur nötig, wenn Tesserarr **innerhalb** der Arr-Suite als Plugin laufen sollte, was
ausdrücklich nicht vorgesehen ist. Go dagegen entspricht dem vorhandenen Bestand: Prüfkette,
Freigabe-Automatik, Abdeckungs-Schranke und das sechsphasige Sicherheits-Audit sind für Go
bereits eingerichtet und erprobt. Ein Go-Binary läuft ohne Laufzeitumgebung und mit kleinem
Speicherbedarf — passend für die Zielumgebung, in der Tesserarr betrieben werden soll: fremde,
unter Umständen knapp bemessene Hardware bei befreundeten Homelab-Betreibern.

**Bauform der Oberfläche.** Für die Weboberfläche standen zwei Bauformen zur Wahl:

**A — Zwei getrennte Abbilder**, eines für den Go-Kern, eines für einen laufenden
Next-Prozess (analog zu Overseerr, das Next als eigenen Node-Prozess betreibt). Das wäre die
naheliegende Trennung entlang der Sprachgrenze, hätte aber zwei Konsequenzen: doppelter
Einrichtungsaufwand für jeden, der Tesserarr betreibt (zwei Compose-Dienste, zwei
Netzwerkpfade), und die Gefahr, dass beide Abbilder im Betrieb auseinanderlaufen — eine ältere
Oberfläche gegen einen neueren Kern oder umgekehrt, mit unklarer Kompatibilität.

**B — Ein gemeinsames Abbild.** Die Oberfläche wird als React-/Next-Anwendung im statischen
Export gebaut (`output: 'export'`), mit Node 22 und pnpm — bewusst nicht mit Bun, siehe die
bestehende Bestandsnotiz zu dessen Instabilität. Die dabei entstehenden statischen Dateien
wandern per `go:embed` in dasselbe Go-Binary, das auch den Kern enthält. Der Bau läuft
mehrstufig: eine Node-Stufe erzeugt die statischen Dateien, eine Go-Stufe bindet sie ein und
baut das Binary, eine `scratch`-Stufe liefert das fertige, minimale Abbild.

Der ausschlaggebende Punkt für B: Ein Abbild bedeutet eine Version, ein Deploy-Vorgang, keine
Möglichkeit für Kern und Oberfläche, im Betrieb auseinanderzulaufen.

## Entscheidung

1. Der Kern von Tesserarr wird in **Go** geschrieben.
2. Die Weboberfläche entsteht getrennt als **React-/Next-Anwendung im statischen Export**,
   gebaut mit Node 22 und pnpm.
3. Es gibt **ein** Docker-Abbild. Der Bau läuft dreistufig: Node-Stufe (statische Dateien) →
   Go-Stufe (`go:embed`, Binary) → `scratch`-Stufe (Auslieferung, ~20 MB Zielgrösse).
4. Die öffentliche Schnittstelle `/api/v1` ist der **einzige** Datenweg der Oberfläche — es
   gibt keine Next-Server-Komponenten mit eigenem Datenzugriff und keine Middleware, die am
   Kern vorbei auf Daten zugreift.

## Konsequenzen

**Positiv**

- Genau **ein** Abbild zu betreiben, zu versionieren und zu aktualisieren — kein Risiko
  auseinanderlaufender Versionsstände zwischen Kern und Oberfläche.
- Das fertige Abbild enthält **keine** Node-Laufzeitumgebung — Node wird ausschliesslich im
  Bauvorgang gebraucht, nicht zur Laufzeit. Das kommt der Zielumgebung (fremde, unter Umständen
  knappe Hardware) unmittelbar zugute.
- Die bestehenden Bestandswerkzeuge (harte Abdeckungs-Schranke, Sicherheits-Audit, Renovate,
  semantic-release mit Conventional Commits) sind für Go-Projekte bereits eingerichtet und
  lassen sich auf den Kern direkt anwenden, ohne eine zweite Werkzeugkette dafür aufzubauen.
- Weil `/api/v1` der einzige Datenweg der Oberfläche ist, kann sich keine fachliche Logik
  unbemerkt in Next-seitigen Server-Komponenten verstecken — jede Fähigkeit der Oberfläche muss
  über die versionierte, dokumentierte Schnittstelle laufen.

**Negativ**

- Zwei Sprachen im selben Repository bedeuten **zwei Paketmanager, zwei Lint-Ketten, zwei
  Testläufer** — mehr Pflegeaufwand als ein durchgängiger Ein-Sprachen-Stack, auch wenn beide
  am Ende in einem Abbild zusammenlaufen.
- `go:embed` bindet die Oberfläche **fest an den Go-Build-Zyklus**. Selbst eine reine
  Text- oder Übersetzungsänderung in der Oberfläche erfordert denselben vollständigen,
  dreistufigen Bau wie eine Änderung am Kern — es gibt keinen schnellen,
  oberflächen-only-Deploy-Pfad.
- **Nebeneffekt, im Entwurf selbst bereits benannt:** Weil die getrennte Oberfläche
  ausschliesslich über `/api/v1` mit dem Kern spricht, zwingt sie diese Schnittstelle in die
  Vollständigkeit — jede Fähigkeit, die die Oberfläche braucht, muss dort öffentlich und
  versioniert abgebildet sein. Das ist gewollt, erhöht aber die Tragweite jeder
  API-Änderung: Es gibt keinen informellen Nebenpfad (etwa eine interne, nicht versionierte
  Admin-Route), über den sich ein Sonderfall schnell lösen liesse.
- Für genau diese Kombination — Go-Kern, Next-Export, `go:embed` — gibt es **kein bekanntes
  Vorbild** im eigenen Bestand. Das ist strukturelles Neuland, das erst mit dem Gerüst (0.0.1)
  tatsächlich erprobt wird; unerwartete Reibung zwischen den beiden Bauwerkzeugen ist ein
  reales Risiko, das erst nach dem ersten vollständigen Bau ausgeschlossen werden kann.

**Wann diese Entscheidung neu zu bewerten ist**

- Wenn sich `go:embed` in der Praxis als so unhandlich erweist (z. B. durch sehr lange
  Bauzeiten bei jeder kleinen Oberflächenänderung), dass ein getrenntes Ausliefern von Kern und
  Oberfläche trotz der genannten Nachteile vorzuziehen wäre.

## Referenzen

| Dokument | Fundstelle |
|---|---|
| Entwurf | `docs/superpowers/specs/2026-08-13-tesserarr-design.md`, Abschnitte „Grundentscheidungen", „Oberfläche" und „Repository" |
| Bestandsnotiz zu Bun | Memory `feedback_bun_runtime_caution` — Bun instabil, Node 22 + pnpm bevorzugt |
