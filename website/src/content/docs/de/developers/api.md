---
title: API-Dokumentation
description: Wie ein Endpunkt der Token-API sich selbst dokumentiert, und die zwei Tore, die das Dokument ehrlich halten.
---

Das OpenAPI-Dokument der Token-API wird von [Scramble](https://scramble.dedoc.co/) aus dem Code erzeugt. Darin stehen nur
Routen unter `/api/v1`; die Sitzungs-API der Weboberfläche unter `/api/v1/app` nie. Das Dokument liegt als
`website/openapi/openapi.json` im Repository und wird zweimal dargestellt: in der [API-Referenz](/tesserarr/de/reference/api/)
dieser Seite und in der App unter `/docs/api` (Scalar, für angemeldete Nutzer).

## Was eine Operation braucht

| Angabe | Kommt aus |
| --- | --- |
| `operationId`, eindeutig | dem Routennamen oder `#[Endpoint(operationId: …)]` |
| Zusammenfassung und Beschreibung | der ersten Zeile und dem Rest des PHPDoc der Controller-Methode, oder `#[Endpoint(title: …, description: …)]` |
| Eine Beschreibung je Parameter | Validierungsregeln mit Kommentar, oder `#[QueryParameter]`, `#[PathParameter]`, `#[BodyParameter]` |
| Beispiele für den Anfragekörper und jede 2xx-Antwort | `example:` der Parameter-Attribute, `#[Response(…, examples: […])]` |
| Mindestens eine Fehlerantwort mit ihrer Bedeutung | `#[Response(403, 'The token lacks …')]` und die, die Scramble selbst erkennt (Validierung, Autorisierung) |
| Das Token-Recht | der Middleware `can:<recht>` der Route, derselben, die es prüft |

## Die zwei Tore

1. **API spec is up to date.** Die CI führt `php artisan scramble:export` aus und schlägt fehl, wenn das eingecheckte
   Dokument abweicht, fehlt oder neu ist. Führe den Export aus und checke die Datei mit der Änderung der Route ein.
2. **API-Qualitätstor.** `scripts/openapi_quality.py` liest das exportierte Dokument und schlägt bei jeder Operation fehl,
   der eine Angabe aus der Tabelle oben fehlt. Lokal: `python3 scripts/openapi_quality.py`.
