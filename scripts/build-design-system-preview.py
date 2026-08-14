#!/usr/bin/env python3
"""Erzeugt die Vorschau-Seiten für das Tesserarr-Design-System-Projekt.

Die Seiten werden AUS globals.css erzeugt, nicht daneben gepflegt: Der
komplette Stylesheet-Inhalt wird eingebettet, und der Dunkelmodus-Block wird
zusätzlich auf `.mode-dark` umgeschrieben, damit beide Modi nebeneinander
gezeigt werden können — unabhängig davon, in welchem Modus jemand die Seite
betrachtet. Handkopierte Farbwerte würden von der Quelle wegdriften; genau das
soll ein Design-System verhindern.
"""

import pathlib
import re
import sys

CSS_PATH = pathlib.Path(__file__).resolve().parent.parent / "web/src/app/globals.css"
OUT = pathlib.Path(sys.argv[1] if len(sys.argv) > 1 else "bundle")


def dark_declarations(css: str) -> str:
    """Holt die Deklarationen aus dem Dunkelmodus-`:root`-Block."""
    media = css.index("@media (prefers-color-scheme: dark)")
    start = css.index(":root {", media) + len(":root {")
    end = css.index("}", start)
    return css[start:end].strip()


def page(marker_group: str, title: str, lede: str, body: str, css: str) -> str:
    dark = dark_declarations(css)
    return f"""<!-- @dsCard group="{marker_group}" -->
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{title} — Tesserarr Werkstatt</title>
<style>
{css}

/* --- Nur für diese Vorschau: beide Modi nebeneinander zeigen. Die
   Dunkelwerte stammen aus demselben Stylesheet und werden hier lediglich
   auf eine Klasse umgehängt, statt abgeschrieben zu werden. --- */
.mode-dark {{
{dark}
  background: var(--color-bg);
  color: var(--color-fg);
}}
.mode-light {{
  background: var(--color-bg);
  color: var(--color-fg);
}}
.dsx-page {{
  font-family: var(--font-sans);
  color: var(--color-fg);
  background: var(--color-bg);
  padding: var(--space-6) var(--space-4);
}}
.dsx-wrap {{ width: min(100%, 60rem); margin-inline: auto; }}
.dsx-lede {{ color: var(--color-fg-muted); max-width: 42rem; }}
.dsx-modes {{ display: grid; gap: var(--space-4); grid-template-columns: 1fr; margin-top: var(--space-5); }}
@media (min-width: 52rem) {{ .dsx-modes {{ grid-template-columns: 1fr 1fr; }} }}
.dsx-frame {{ border: 1px solid var(--color-border); border-radius: var(--radius); overflow: hidden; }}
.dsx-frame > div {{ padding: var(--space-4); }}
.dsx-label {{
  font-size: 0.75rem; letter-spacing: 0.08em; text-transform: uppercase;
  color: var(--color-fg-muted); margin: 0 0 var(--space-2);
}}
.dsx-note {{
  border-left: 2px solid var(--color-border); padding-left: var(--space-3);
  color: var(--color-fg-muted); font-size: 0.875rem; margin-top: var(--space-5);
}}
h1 {{ font-size: 1.25rem; letter-spacing: -0.01em; margin: 0 0 var(--space-2); }}
</style>
</head>
<body>
<div class="dsx-page">
  <div class="dsx-wrap">
    <h1>{title}</h1>
    <p class="dsx-lede">{lede}</p>
    <div class="dsx-modes">
      <div>
        <p class="dsx-label">Hell</p>
        <div class="dsx-frame"><div class="mode-light">{body}</div></div>
      </div>
      <div>
        <p class="dsx-label">Navy</p>
        <div class="dsx-frame"><div class="mode-dark">{body}</div></div>
      </div>
    </div>
    <p class="dsx-note">Erzeugt aus <code>web/src/app/globals.css</code> des Repos
      <code>strausmann/tesserarr</code> — die Werte stehen hier nicht doppelt, sondern
      stammen aus derselben Datei, die die Anwendung ausliefert. Die Monospace wird in
      dieser Vorschau durch eine Systemschrift ersetzt, weil die Schriftdatei nur in der
      Anwendung unter <code>/fonts/</code> liegt.</p>
  </div>
</div>
</body>
</html>
"""


SHELL = """
<div class="shell__bar"><span class="shell__brand">Tesserarr</span><span class="shell__where">Account</span></div>
<div class="shell__main" style="padding-block:var(--space-4)">
  <div class="section"><span class="section__label">Ein Abschnitt</span>
  <p style="margin:0">Der Inhalt sitzt auf höchstens 64 rem, mittig. Keine Seitenleiste.</p></div>
</div>
"""

SECTION_ROW = """
<div class="section">
  <span class="section__label">API tokens</span>
  <div>
    <div class="row"><div><div class="row__name">sonarr</div><div class="row__meta">2026-08-12 · no expiry</div></div><button class="btn btn--danger">Revoke</button></div>
    <div class="row"><div><div class="row__name">radarr</div><div class="row__meta">2026-08-03 · 2026-11-03</div></div><button class="btn btn--danger">Revoke</button></div>
    <div class="row"><div><div class="row__name">prowlarr</div><div class="row__meta">2026-07-28 · no expiry</div></div><button class="btn btn--danger">Revoke</button></div>
  </div>
</div>
"""

FIELD_BUTTONS = """
<div class="section">
  <div class="field">
    <span class="field__label">Email for notifications</span>
    <div class="field__row">
      <input class="field__input" value="bjoern@example.org" readonly>
      <button class="btn btn--primary">Save</button>
    </div>
  </div>
  <div style="display:flex;gap:var(--space-2);flex-wrap:wrap;margin-top:var(--space-3)">
    <button class="btn btn--primary">Primär</button>
    <button class="btn btn--secondary">Sekundär</button>
    <button class="btn btn--danger">Gefährlich</button>
  </div>
</div>
"""

CALLOUT_BADGE = """
<div class="section">
  <div class="callout">
    <div class="callout__head">New token — copy it now</div>
    <code class="callout__code">tsr_8f3c1a94e07b25d6a1f0c4b8</code>
  </div>
  <div style="display:flex;gap:var(--space-2);margin-top:var(--space-4)">
    <span class="badge badge--ok">aktiv</span>
    <span class="badge badge--warn">abgelaufen</span>
  </div>
</div>
"""

EMPTY_ERROR = """
<div class="section">
  <span class="section__label">API tokens</span>
  <p class="empty">Noch kein Token erzeugt. Sonarr und Radarr brauchen eines, um Anfragen zu stellen.</p>
  <button class="btn btn--primary">Create token</button>
</div>
<div class="section" style="margin-top:var(--space-5)">
  <div class="field">
    <span class="field__label">Email for notifications</span>
    <div class="field__row"><input class="field__input" value="nicht-gueltig" readonly><button class="btn btn--primary">Save</button></div>
    <p class="form-error">That does not look like an email address.</p>
  </div>
</div>
"""

TOKENS = """
<div class="section">
  <span class="section__label">Flächen und Schrift</span>
  <div>
    <div class="row"><div class="row__name">Grund</div><div class="row__meta">--color-bg</div></div>
    <div class="row"><div class="row__name">Fläche</div><div class="row__meta">--color-surface</div></div>
    <div class="row"><div class="row__name">Schrift</div><div class="row__meta">--color-fg</div></div>
    <div class="row"><div class="row__name">Gedämpft</div><div class="row__meta">--color-fg-muted</div></div>
  </div>
</div>
<div class="section" style="margin-top:var(--space-4)">
  <span class="section__label">Linien</span>
  <div>
    <div class="row"><div class="row__name">Haarlinie</div><div class="row__meta">--color-hairline</div></div>
    <div class="row"><div class="row__name">Rahmen</div><div class="row__meta">--color-border</div></div>
    <div class="row"><div class="row__name">Eingabe-Rahmen</div><div class="row__meta">--color-input-border · 3:1, nie --color-border</div></div>
  </div>
</div>
<div class="section" style="margin-top:var(--space-4)">
  <span class="section__label">Handlung und Zustand — getrennt</span>
  <div>
    <div class="row"><div class="row__name" style="color:var(--color-accent)">Akzent</div><div class="row__meta">--color-accent · nur Handlungen</div></div>
    <div class="row"><div class="row__name" style="color:var(--color-ok)">In Ordnung</div><div class="row__meta">--color-ok</div></div>
    <div class="row"><div class="row__name" style="color:var(--color-warn)">Achtung</div><div class="row__meta">--color-warn</div></div>
    <div class="row"><div class="row__name" style="color:var(--color-danger)">Fehler</div><div class="row__meta">--color-danger</div></div>
  </div>
</div>
"""

PAGES = [
    ("tokens/index.html", "Foundations", "Tokens",
     "Farbe, Abstand und Schrift. Der Akzent ist ausschliesslich Handlungen vorbehalten — Zustand spricht nie in Akzentfarbe.", TOKENS),
    ("components/shell/index.html", "Layout", "Shell",
     "Kopfleiste mit Marke links und Ort rechts, Inhalt auf 64 rem begrenzt. Keine Seitenleiste.", SHELL),
    ("components/section-row/index.html", "Data", "Section und Row",
     "Mikro-Etikett über einer Haarlinie, darunter Zeilen. Haarlinien zwischen den Zeilen, keine um die Liste — das ist der Unterschied zum kartenbasierten Look.", SECTION_ROW),
    ("components/field-buttons/index.html", "Forms", "Field und Buttons",
     "Eingabe mit eigenem Rahmen-Token, weil der Rahmen das Erkennungsmerkmal eines Felds ist. Drei Knopf-Rollen.", FIELD_BUTTONS),
    ("components/callout-badge/index.html", "Feedback", "Callout und Badge",
     "Die einzige Karte im ganzen System, reserviert für das genau einmal sichtbare Geheimnis. Abzeichen mit fester Breite.", CALLOUT_BADGE),
    ("components/empty-error/index.html", "States", "Leerzustand und Fehler",
     "Ein Satz plus der Knopf, der ihn behebt. Fehler unter dem betroffenen Feld, nie als Blase.", EMPTY_ERROR),
]


def main() -> None:
    css = CSS_PATH.read_text(encoding="utf-8")
    for rel, group, title, lede, body in PAGES:
        target = OUT / rel
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(page(group, title, lede, body, css), encoding="utf-8")
        print(f"{target}  {target.stat().st_size} Bytes")


if __name__ == "__main__":
    main()
