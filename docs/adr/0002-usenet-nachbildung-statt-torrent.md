# ADR-0002: Usenet-Nachbildung statt Torrent

**Status:** accepted
**Datum:** 2026-08-13
**Ersetzt:** —
**Ersetzt durch:** —
**Überarbeitet:** —
**Überarbeitet durch:** —
**Verwandt:** [ADR-0004](0004-einladung-zwingend-anmeldedienst-liefert-nur-identitaet.md), [ADR-0005](0005-go-kern-getrennte-oberflaeche-gemeinsames-abbild.md), [ADR-0007](0007-anmeldung-sitzung-oberflaeche-token-fremdsysteme-getrennte-pfade.md)

## Kontext

Sonarr und Radarr — die Arr-Suite auf Empfängerseite — kennen zwei etablierte Bezugsmodelle, um
an Mediendateien zu kommen: **Torrent** und **Usenet**. Beide bestehen aus einem Such-Baustein
(Indexer) und einem Bezugs-Baustein (Download-Client), die über feste, dokumentierte
Schnittstellen mit der Arr-Suite sprechen. Damit Tesserarr von einer bestehenden Arr-Suite als
Bezugsquelle akzeptiert wird, muss es sich als eines dieser beiden Modelle ausgeben — ein
drittes, eigenes Modell kennt die Arr-Suite nicht.

Zusätzlich zur Wahl zwischen Torrent und Usenet stand die Frage, ob eine Nachbildung des
**Indexers allein** reicht, oder ob auch der **Bezugsdienst** nachgebildet werden muss:

**Nur Indexer nachbilden.** Tesserarr träte ausschliesslich als Suchquelle auf; für den
eigentlichen Bezug würde die Arr-Suite einen ohnehin vorhandenen, echten Download-Client
ansprechen. Das scheitert an der Grundlage des gesamten Vorhabens: Es gibt keinen echten
Torrent- oder Usenet-Bezug — die Datei liegt bereits beim Anbieter und muss nur noch über
Syncthing übertragen werden. Ein echter Download-Client hätte nichts, was er herunterladen
könnte.

**Torrent nachbilden** (qBittorrent- oder Transmission-kompatible Schnittstelle,
Magnet-Verweis als Bezeichner). Torrent bringt eine Begriffswelt mit, die für Tesserarrs Modell
keine Entsprechung hat: ein Magnet-Verweis identifiziert Inhalt über eine Prüfsumme, die bei
einer bereits vorhandenen, nicht aus einem Schwarm zusammengesetzten Datei sinnlos ist; das
Verhältnis von Hoch- zu Herunterladen (Ratio) setzt ein Schwarmverhalten voraus, das hier nicht
existiert; Weiterverteilen (Seeding) ist ein Konzept ohne Gegenstück, wenn die Quelle eine
einzelne, autoritative Kopie beim Anbieter ist.

**Usenet nachbilden** (Newznab-kompatible Suche, SABnzbd-kompatibler Auftragsstatus). Usenet
kennt genau das Modell, das Tesserarr tatsächlich abbildet: ein Auftrag wird angenommen, macht
Fortschritt, ist irgendwann fertig, und liegt danach in einem Pfad. Kein Schwarm, keine
Prüfsummen-Identität, kein Verhältnis von Geben und Nehmen.

## Entscheidung

1. Tesserarr bildet **beide** Bausteine nach: einen Newznab-kompatiblen Indexer für die Suche
   (Baustein `indexer`) und einen SABnzbd-kompatiblen Download-Client für den Auftragsstatus
   (Baustein `downloader`). Eine Nachbildung allein reicht nicht — die Arr-Suite braucht für
   einen vollständigen Bezugsweg beide.
2. Es wird **Usenet**, nicht Torrent, nachgebildet — aus dem oben genannten Grund der
   begrifflichen Passung.
3. Die Suche liefert **ausschliesslich** Treffer aus den für den anfragenden Nutzer
   freigegebenen Bibliotheken (Verzahnung mit `grant` und der Identität aus
   [ADR-0004](0004-einladung-zwingend-anmeldedienst-liefert-nur-identitaet.md)). Nicht
   freigegebene Inhalte sind über die Suche unauffindbar.
4. Der Baustein `release` erzeugt aus Metadaten, Datei- und Qualitätseigenschaften einen
   Veröffentlichungsnamen, den Sonarr/Radarr an ihrer eigenen Namenskonvention erkennen — ohne
   diesen Namen würde die Arr-Suite jeden Treffer verwerfen, unabhängig von Indexer oder
   Download-Client.

## Konsequenzen

**Positiv**

- Die Arr-Suite braucht **keine Sonderbehandlung** — Prowlarr, Sonarr und Radarr werden über
  ihre vorhandene, dokumentierte Newznab-/SABnzbd-Konfiguration angebunden, kein Fork, kein
  Plugin, kein Zusatzcode auf Empfängerseite.
- Der Empfänger braucht **keinen** Torrent-Client und muss keine Portweiterleitung oder
  Firewall-Regel dafür einrichten.
- Die interne Zustandslogik (`request`) bildet exakt das Modell ab, das Usenet-Begriffe ohnehin
  beschreiben: ein Auftrag, ein Fortschritt, ein Abschluss — kein Übersetzungsaufwand zwischen
  Torrent-Begriffen und dem tatsächlichen Ablauf.

**Negativ**

- Die konkrete Ausgestaltung der Newznab- und SABnzbd-Schnittstellen ist zum Zeitpunkt dieser
  Entscheidung **nicht** vollständig verifiziert — die Servarr-Wiki lädt ihre Inhalte
  clientseitig nach, ein einfacher Abruf lieferte nur den Seitentitel (siehe Entwurf, Abschnitt
  „Offene Punkte"). Diese beiden Bausteine sind damit die Stellen mit dem grössten
  Nacharbeitsrisiko nach dem ersten Einsatz gegen eine echte Arr-Suite-Instanz.
- Die Nachbildung ist ein **Kunstgriff**: Tesserarr muss Antworten synthetisieren, die wie echte
  Usenet-Suchergebnisse und -Auftragsstände aussehen, obwohl nie ein echter Usenet-Artikel
  existiert hat. Randfälle, die das echte Usenet-Ökosystem gelöst hat — mehrteilige Downloads,
  Sample-Dateien, kennwortgeschützte Archive, fehlgeschlagene Segmente — haben in Tesserarrs
  Modell keine natürliche Entsprechung und müssten bei Bedarf eigens nachgebildet werden, ohne
  dass ein Vorbild aus dem echten Protokoll übertragbar wäre.
- Terminologischer Bruch: Wer Protokolle oder die SABnzbd-kompatible Oberfläche liest, sieht
  Usenet-Begriffe (Segment, NZB, Warteschlange) für einen Dienst, der kein Usenet ist. Das ist
  eine Quelle für Missverständnisse bei künftigen Mitwirkenden und gehört in die Dokumentation
  ausdrücklich erklärt, nicht nur stillschweigend hingenommen.
- Der `release`-Baustein — die Namenszusammensetzung — ist laut Entwurf selbst „der schwierigste
  Baustein" und der wahrscheinlichste Ort für Nacharbeit; diese Entscheidung erzeugt also von
  vornherein einen bekannten Schuldenposten.

**Wann diese Entscheidung neu zu bewerten ist**

- Wenn sich die Newznab-/SABnzbd-Nachbildung bei der ersten echten Erprobung als so fragil
  erweist, dass eine Torrent-Nachbildung (mit einer qBittorrent-kompatiblen Schnittstelle) trotz
  der begrifflichen Unpassung praktikabler wäre.

## Referenzen

| Dokument | Fundstelle |
|---|---|
| Entwurf | `docs/superpowers/specs/2026-08-13-tesserarr-design.md`, Abschnitte „Grundentscheidungen", „Bausteine" (`release`), „Arr-Anbindung" und „Offene Punkte" |
