---
title: API documentation
description: How an endpoint of the token API documents itself, and the two gates that keep the document honest.
---

The OpenAPI document of the token API is generated from the code by [Scramble](https://scramble.dedoc.co/). Only routes
under `/api/v1` are in it; the session API of the web interface under `/api/v1/app` never is. The document is committed
as `website/openapi/openapi.json` and rendered twice: in the [API reference](/tesserarr/reference/api/) of this site, and
inside the app at `/docs/api` (Scalar, for signed-in users).

## What an operation needs

| Item | Comes from |
| --- | --- |
| `operationId`, unique | the route name, or `#[Endpoint(operationId: …)]` |
| Summary and description | the first line and the rest of the controller method's PHPDoc, or `#[Endpoint(title: …, description: …)]` |
| A description for each parameter | a PHPDoc comment on the validation rule, or `#[QueryParameter]`, `#[PathParameter]`, `#[BodyParameter]` |
| Examples for the request body and every 2xx response | `@example` in that PHPDoc comment, `example:` of the parameter attributes, `#[Response(…, examples: […])]` |
| At least one error response with its meaning | `#[Response(403, 'The token lacks …')]` and the ones Scramble infers from `validate()` and `authorize()` |
| The token ability | the route's `can:<permission>` middleware, the same one that checks it. `authorize()` in the controller alone is not enough: the export cannot see it |

## The two gates

1. **API spec is up to date.** CI runs `php artisan scramble:export` and fails when the committed document differs, is
   missing or is new. Run the export and commit the file with the change of the route.
2. **API quality gate.** `scripts/openapi_quality.py` reads the exported document and fails on any operation without
   the items in the table above. Run it locally with `python3 scripts/openapi_quality.py`.
