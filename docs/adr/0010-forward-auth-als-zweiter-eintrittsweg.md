# ADR-0010: Forward-Auth als zweiter Eintrittsweg

**Status:** accepted
**Datum:** 2026-08-14
**Ersetzt:** —
**Ersetzt durch:** —
**Überarbeitet:** —
**Überarbeitet durch:** —
**Verwandt:** [ADR-0004](0004-einladung-zwingend-anmeldedienst-liefert-nur-identitaet.md), [ADR-0007](0007-anmeldung-sitzung-oberflaeche-token-fremdsysteme-getrennte-pfade.md)

## Kontext

Mehrere der vorgesehenen Betreiber betreiben bereits einen vorgeschalteten Anmelde-Wächter
(Pangolin, Authentik, tinyauth, Authelia, oauth2-proxy) vor ihren Diensten. Ohne einen zweiten
Weg zur Anmeldung müsste jeder von ihnen zusätzlich einen eigenen OIDC-Client für Tesserarr
anlegen und pflegen — obwohl der Wächter die Identität der Person bereits geprüft hat, bevor die
Anfrage Tesserarr überhaupt erreicht.

Zur Wahl standen:

1. **Nur OIDC**, wie bisher. Verworfen — erzwingt eine doppelte Anmeldung für jeden Betreiber,
   der bereits einen Wächter einsetzt, ohne Sicherheitsgewinn: der Wächter hat die Identität
   bereits geprüft.
2. **Die vom Wächter gesetzten Kopfzeilen ungeprüft als Identität übernehmen**, sobald eine
   Anfrage sie mitbringt. Verworfen — im selben Docker-Netz erreicht jeder Nachbar-Container
   Tesserarr direkt und kann sich beliebige Kopfzeilen ausdenken; der Wächter selbst schützt nur
   den Weg DURCH ihn, nicht das interne Netz dahinter.
3. **Kopfzeilen-Vertrauen zweistufig** — Quell-IP UND ein gemeinsames, nur Wächter und Tesserarr
   bekanntes Geheimnis. Gewählt: schliesst die Lücke aus Option 2, ohne eine externe
   JWT-Prüfung (wie bei Cloudflare Access) zu benötigen, die bei den fünf primär adressierten
   Wächtern gar nicht existiert.
4. **Die Kopfzeile direkt eine dauerhafte Sitzung erzeugen lassen, mit derselben Rotation wie ein
   OIDC-Rückruf** vs. **ein eigenes, drittes Anmeldemodell neben Sitzung/Token einführen**.
   Gewählt: ersteres — ADR-0007s Kern ist „kein Pfad akzeptiert zwei Arten von Berechtigung",
   nicht „es gibt nur einen Weg, an eine Sitzung zu kommen". Ein zweiter Weg, AN eine Sitzung zu
   kommen, ändert nichts daran, WIE eine Sitzung anschliessend vorgezeigt wird.

## Entscheidung

1. Ein neuer, öffentlicher Eintrittspfad (`GET /api/v1/app/login/forward-auth`) prüft **zuerst**
   Vertrauen (Quell-IP aus `TESSERARR_TRUSTED_PROXIES` UND ein gemeinsames Geheimnis in der
   Kopfzeile `X-Tesserarr-Forward-Auth-Secret`) — ohne beides wird abgelehnt, ohne die
   Identitäts-Kopfzeilen auch nur anzusehen.
2. Bei bekannter Identität entsteht eine Sitzung sofort, mit derselben Rotation wie beim
   OIDC-Rückruf (`SessionStore.Rotate`) — kein separates Sitzungsmodell.
3. Bei unbekannter Identität entsteht ein Konto **ausschliesslich** über ein gültiges,
   unlösgelöstes Einladungs-Kennzeichen, in derselben Transaktion wie die Kontoanlage — exakt die
   Regel aus ADR-0004, jetzt auch für diesen zweiten Anmeldeweg durchgesetzt. Das
   Einladungs-Kennzeichen reist als `?invite=<token>`-Anfrageparameter auf demselben Pfad, nicht
   über einen zweiten, wie es `/invite/{token}` für OIDC tut — damit bleibt es EIN Eintrittspfad
   mit einem Eintrag im Pfad-Inventar.
4. OIDC bleibt vollständig bestehen und gleichzeitig nutzbar — kein Betreiber muss migrieren.
5. Ein Rollen-System wird eingeführt (vorher gab es keines): Kopfzeilen-Namen und
   Rollen-Zuordnung sind konfigurierbar, mit Voreinstellungen je Wächter. Bei Wächtern ohne
   stabile Kennung (Authelia, oauth2-proxy) verweigert der Dienst den Start, solange keine
   Schlüssel-Kopfzeile ausdrücklich gesetzt ist.

## Konsequenzen

**Positiv**

- Betreiber mit vorhandenem Wächter brauchen keinen zweiten, separat gepflegten OIDC-Client.
- Die Vertrauensprüfung ist zweistufig und schliesst die Lücke, die reine
  Kopfzeilen-Übernahme im internen Netz offen liesse.
- ADR-0004 (Einladung als alleiniges Zutrittskriterium) und ADR-0007 (getrennte Prüfpfade für
  Sitzung und Token) bleiben **unangetastet gültig** — dieser Weg fügt nur eine zweite Art hinzu,
  AN eine Sitzung zu kommen, keine dritte Art, sie VORZUZEIGEN.

**Negativ**

- Eine zusätzliche, sicherheitskritische Prüffläche (Vertrauensprüfung, Rollen-Auflösung) mit
  eigenem Pflegeaufwand.
- Bei Wächtern ohne stabile Kennung (Authelia, oauth2-proxy) führt eine Umbenennung im Wächter zu
  einem verwaisten Konto — abgefangen durch die Einladungspflicht (kein Fremd-Zugriff), aber
  lästig für den Betroffenen (`tesserarr user remap` nötig).
- Das gemeinsame Geheimnis ist die EINZIGE Schranke zwischen „kann Kopfzeilen setzen" und
  „ist Administrator", sobald eine Rollen-Kopfzeile Administrator-Rechte vergeben darf — ein
  Verlust dieses Geheimnisses wiegt entsprechend schwer.

**Wann diese Entscheidung neu zu bewerten ist**

- Wenn Cloudflare Access (JWT-basiert, andere Bauart) als sechster Wächter unterstützt werden
  soll.
- Wenn sich zeigt, dass die zweistufige Vertrauensprüfung in der Praxis zu Fehlkonfigurationen
  führt, die ein drittes, robusteres Modell nahelegen.

## Referenzen

| Dokument | Fundstelle |
|---|---|
| Entwurf | `docs/superpowers/specs/2026-08-14-tesserarr-forward-auth-design.md` (im `homelab-management`-Repo) |
| Recherche | `docs/research/2026-08-14-forward-auth-portale.md` (im `homelab-management`-Repo) |
| Umsetzungsplan | `docs/superpowers/plans/2026-08-14-tesserarr-forward-auth.md` (im `homelab-management`-Repo) |
