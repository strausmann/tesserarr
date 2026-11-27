#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

"""API quality gate over the exported OpenAPI document.

Runs in CI after the export gate ("API spec is up to date", .github/workflows/
ci.yml) on website/openapi/openapi.json, the document Scramble generates from
the token API under /api/v1. The document has to be good enough for generated
clients and a future MCP server, so every operation needs:

* an ``operationId``, unique in the document, also when compared without case
  (generated clients turn ``probe.show`` and ``Probe.Show`` into one method);
* a summary and a description, and a description for every parameter
  (path-level parameters included);
* an example for every request body and every 2xx response with content
  (``example``/``examples`` on the media type, on the schema, or on every
  property of an object schema);
* at least one 2xx response and at least one 4xx response, and a description
  (the meaning) for every 4xx and 5xx response;
* the token ability it needs: a security requirement of the ``apiToken``
  scheme with at least one scope (App\\OpenApi\\TokenAbilityExtension writes it
  from the route's ``can:`` middleware), and no empty requirement ``{}``,
  which would let anonymous callers in. A self-service operation
  (``x-self-service: true``, written for routes marked #[SelfService]) needs
  the ``apiToken`` requirement without a scope instead. With ``--permissions <file>`` (a JSON
  list) every scope must be a permission of that catalogue.

What is checked is the content, not the form: a ``$ref`` into
``components/{schemas,responses,requestBodies,parameters,examples}`` is resolved
first. Scramble writes the responses it infers from ``validate()`` and
``abort(403)``/``authorize()`` that way. Text that consists only of white space
or invisible characters (zero-width space, byte order mark) counts as empty.

A document without operations passes: there is nothing to check yet. Prints
each problem as a GitHub annotation and exits 1 when there is one.

Standard library only. Values from the document are data: printed, never run.
"""

from __future__ import annotations

import argparse
import json
import sys
import unicodedata
from pathlib import Path
from typing import Any

METHODS = ("get", "put", "post", "delete", "options", "head", "patch", "trace")
TOKEN_SCHEME = "apiToken"


def _text(value: Any) -> bool:
    """Non-empty text: something else than white space and invisible format characters."""
    if not isinstance(value, str):
        return False
    return any(not ch.isspace() and unicodedata.category(ch) not in ("Cf", "Cc", "Zs", "Zl", "Zp") for ch in value)


REF_KINDS = ("schemas", "responses", "requestBodies", "parameters", "examples")


def _resolve(doc: dict[str, Any], item: Any) -> Any:
    """Follow ``#/components/<kind>/<name>`` references, a few levels deep; a dangling one is empty."""
    for _ in range(5):
        if not (isinstance(item, dict) and isinstance(item.get("$ref"), str)):
            return item
        parts = item["$ref"].split("/")
        if len(parts) != 4 or parts[:2] != ["#", "components"] or parts[2] not in REF_KINDS:
            return {}
        item = doc.get("components", {}).get(parts[2], {}).get(parts[3], {})
    return {}


def _has_example(doc: dict[str, Any], media: Any) -> bool:
    if not isinstance(media, dict):
        return False
    if "example" in media:
        return True
    examples = media.get("examples")
    if isinstance(examples, dict) and any(
        isinstance(e := _resolve(doc, ex), dict) and ("value" in e or "externalValue" in e) for ex in examples.values()
    ):
        return True
    schema = _resolve(doc, media.get("schema"))
    if not isinstance(schema, dict):
        return False
    if "example" in schema or schema.get("examples"):
        return True
    properties = schema.get("properties")
    if isinstance(properties, dict) and properties:
        return all(
            isinstance(p := _resolve(doc, prop), dict) and ("example" in p or bool(p.get("examples")))
            for prop in properties.values()
        )
    return False


def _operations(doc: dict[str, Any]) -> list[tuple[str, str, dict[str, Any], list[Any]]]:
    out = []
    paths = doc.get("paths") or {}
    for path, item in paths.items():
        if not isinstance(item, dict):
            continue
        shared = item.get("parameters") or []
        for method in METHODS:
            op = item.get(method)
            if isinstance(op, dict):
                out.append((method.upper(), path, op, shared))
    return out


def problems(doc: dict[str, Any], permissions: set[str] | None = None) -> list[str]:
    """Every problem of the document. ``permissions``: the permission catalogue, if known."""
    # TODO(task 8): CI passes the catalogue (App\Authorization\Permission) with --permissions
    # once it exists; until then the scopes are only checked for being there.
    found: list[str] = []
    seen_ids: dict[str, str] = {}
    for method, path, op, shared in _operations(doc):
        where = f"{method} {path}"

        operation_id = op.get("operationId")
        if not _text(operation_id):
            found.append(f"{where}: no operationId")
        elif operation_id.casefold() in seen_ids:
            found.append(f"{where}: operationId {operation_id} is also used by {seen_ids[operation_id.casefold()]}")
        else:
            seen_ids[operation_id.casefold()] = where

        if not _text(op.get("summary")):
            found.append(f"{where}: no summary")
        if not _text(op.get("description")):
            found.append(f"{where}: no description")

        for param in [_resolve(doc, p) for p in [*shared, *(op.get("parameters") or [])]]:
            if isinstance(param, dict) and not _text(param.get("description")):
                found.append(f"{where}: parameter {param.get('name', '?')} ({param.get('in', '?')}) has no description")

        body = _resolve(doc, op.get("requestBody"))
        if isinstance(body, dict):
            for media_type, media in (body.get("content") or {}).items():
                if not _has_example(doc, media):
                    found.append(f"{where}: request body {media_type} has no example")

        responses = op.get("responses") or {}
        statuses = [str(s) for s in responses]
        if not any(s.startswith("2") for s in statuses):
            found.append(f"{where}: no 2xx response")
        if not any(s.startswith("4") for s in statuses):
            found.append(f"{where}: no error response (4xx)")
        for status, response in responses.items():
            status = str(status)
            response = _resolve(doc, response)
            if not isinstance(response, dict):
                continue
            if status[:1] in ("4", "5") and not _text(response.get("description")):
                found.append(f"{where}: response {status} has no description")
            if status.startswith("2"):
                for media_type, media in (response.get("content") or {}).items():
                    if not _has_example(doc, media):
                        found.append(f"{where}: response {status} {media_type} has no example")

        security = op.get("security")
        requirements = security if isinstance(security, list) else []
        if any(r == {} for r in requirements):
            found.append(f"{where}: an empty security requirement lets anonymous callers in")
        abilities = [
            scope
            for requirement in requirements
            if isinstance(requirement, dict)
            for scope in (requirement.get(TOKEN_SCHEME) or [])
            if _text(scope)
        ]
        if op.get("x-self-service") is True:
            # Self-service (#[SelfService], e.g. GET /me): any valid token, no
            # permission. It still needs the token scheme, without a scope.
            if not any(isinstance(r, dict) and TOKEN_SCHEME in r for r in requirements):
                found.append(f"{where}: self-service without the {TOKEN_SCHEME} requirement")
        elif not abilities:
            found.append(f"{where}: no token ability (security requirement {TOKEN_SCHEME} with a scope)")
        if permissions is not None:
            found += [
                f"{where}: token ability {scope} is not in the permission catalogue"
                for scope in abilities
                if scope not in permissions
            ]
    return found


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("document", type=Path, nargs="?", default=Path("website/openapi/openapi.json"))
    parser.add_argument("--permissions", type=Path, help="JSON list of the permission catalogue; every scope must be in it")
    args = parser.parse_args(argv)

    permissions: set[str] | None = None
    if args.permissions:
        try:
            catalogue = json.loads(args.permissions.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError) as exc:
            print(f"::error::{args.permissions}: not JSON or not readable ({exc})", file=sys.stderr)
            return 1
        if not isinstance(catalogue, list) or not all(isinstance(p, str) for p in catalogue):
            print(f"::error::{args.permissions}: not a JSON list of permission names", file=sys.stderr)
            return 1
        permissions = set(catalogue)

    try:
        doc = json.loads(args.document.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        print(f"::error::{args.document}: not JSON or not readable ({exc})", file=sys.stderr)
        return 1
    if not isinstance(doc, dict):
        print(f"::error::{args.document}: not an OpenAPI document", file=sys.stderr)
        return 1

    found = problems(doc, permissions)
    for problem in found:
        print(f"::error::{problem}", file=sys.stderr)
    if found:
        print(f"{len(found)} problems; see CONTRIBUTING.md, 'API documentation'", file=sys.stderr)
        return 1
    print(f"api quality: {len(_operations(doc))} operations checked, no problems")
    return 0


if __name__ == "__main__":
    sys.exit(main())
