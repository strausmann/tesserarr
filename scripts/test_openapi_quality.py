# SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Unit tests for scripts/openapi_quality.py.

The fixture fixtures/openapi-probe.json is a real Scramble export of three
documented probe operations (tests/Support/OpenApi/ProbeController.php). Two set
their error responses with attributes, the third (POST /_probe/archive) leaves
the 403 and 422 to Scramble, which writes them as references into
components/responses. Each test breaks exactly one rule in a copy of it.

Run with: python -m pytest scripts/
"""

from __future__ import annotations

import copy
import io
import json
import sys
import tempfile
import unittest
from contextlib import redirect_stderr, redirect_stdout
from pathlib import Path
from typing import Any

sys.path.insert(0, str(Path(__file__).resolve().parent))

import openapi_quality as oq

FIXTURE = Path(__file__).resolve().parent / "fixtures" / "openapi-probe.json"


def probe() -> dict[str, Any]:
    return json.loads(FIXTURE.read_text(encoding="utf-8"))


def get_op(doc: dict[str, Any]) -> dict[str, Any]:
    return doc["paths"]["/_probe"]["get"]


def put_op(doc: dict[str, Any]) -> dict[str, Any]:
    return doc["paths"]["/_probe"]["put"]


def archive_op(doc: dict[str, Any]) -> dict[str, Any]:
    return doc["paths"]["/_probe/archive"]["post"]


class ProblemsTest(unittest.TestCase):
    def assert_problem(self, doc: dict[str, Any], fragment: str) -> None:
        problems = oq.problems(doc)
        self.assertTrue(any(fragment in p for p in problems), f"no problem with {fragment!r} in {problems}")

    def test_accepts_the_documented_probe(self) -> None:
        self.assertEqual(oq.problems(probe()), [])

    def test_accepts_a_document_without_operations(self) -> None:
        doc = probe()
        del doc["paths"]
        self.assertEqual(oq.problems(doc), [])

    def test_fails_when_an_operation_id_is_missing(self) -> None:
        doc = probe()
        del get_op(doc)["operationId"]
        self.assert_problem(doc, "GET /_probe: no operationId")

    def test_fails_when_an_operation_id_is_duplicated(self) -> None:
        doc = probe()
        put_op(doc)["operationId"] = "probe.show"
        self.assert_problem(doc, "PUT /_probe: operationId probe.show is also used by GET /_probe")

    def test_fails_when_an_operation_has_no_summary(self) -> None:
        doc = probe()
        get_op(doc)["summary"] = "  "
        self.assert_problem(doc, "GET /_probe: no summary")

    def test_fails_when_an_operation_has_no_description(self) -> None:
        doc = probe()
        del put_op(doc)["description"]
        self.assert_problem(doc, "PUT /_probe: no description")

    def test_fails_when_a_parameter_has_no_description(self) -> None:
        doc = probe()
        get_op(doc)["parameters"] = [{"name": "page", "in": "query", "schema": {"type": "integer"}}]
        self.assert_problem(doc, "GET /_probe: parameter page (query) has no description")

    def test_fails_when_a_path_level_parameter_has_no_description(self) -> None:
        doc = probe()
        doc["paths"]["/_probe"]["parameters"] = [{"name": "probe", "in": "path", "required": True}]
        self.assert_problem(doc, "GET /_probe: parameter probe (path) has no description")

    def test_fails_when_the_request_body_has_no_example(self) -> None:
        doc = probe()
        del put_op(doc)["requestBody"]["content"]["application/json"]["schema"]["properties"]["name"]["examples"]
        self.assert_problem(doc, "PUT /_probe: request body application/json has no example")

    def test_takes_a_media_type_example_for_the_request_body(self) -> None:
        doc = probe()
        media = put_op(doc)["requestBody"]["content"]["application/json"]
        del media["schema"]["properties"]["name"]["examples"]
        media["example"] = {"name": "renamed"}
        self.assertEqual(oq.problems(doc), [])

    def test_resolves_a_schema_reference_for_the_example(self) -> None:
        doc = probe()
        media = get_op(doc)["responses"]["200"]["content"]["application/json"]
        doc["components"]["schemas"] = {"Probe": media["schema"]}
        media["schema"] = {"$ref": "#/components/schemas/Probe"}
        self.assertEqual(oq.problems(doc), [])

    def test_fails_when_a_success_response_has_no_example(self) -> None:
        doc = probe()
        del get_op(doc)["responses"]["200"]["content"]["application/json"]["schema"]["examples"]
        self.assert_problem(doc, "GET /_probe: response 200 application/json has no example")

    def test_fails_when_there_is_no_success_response(self) -> None:
        doc = probe()
        del get_op(doc)["responses"]["200"]
        self.assert_problem(doc, "GET /_probe: no 2xx response")

    def test_fails_when_there_is_no_error_response(self) -> None:
        doc = probe()
        del get_op(doc)["responses"]["403"]
        self.assert_problem(doc, "GET /_probe: no error response (4xx)")

    def test_fails_when_an_error_response_has_no_meaning(self) -> None:
        doc = probe()
        put_op(doc)["responses"]["422"]["description"] = ""
        self.assert_problem(doc, "PUT /_probe: response 422 has no description")

    def test_fails_when_an_operation_names_no_token_ability(self) -> None:
        doc = probe()
        del get_op(doc)["security"]
        self.assert_problem(doc, "GET /_probe: no token ability")

    def test_fails_when_the_token_ability_is_empty(self) -> None:
        doc = probe()
        put_op(doc)["security"] = [{"apiToken": []}]
        self.assert_problem(doc, "PUT /_probe: no token ability")

    def test_fails_when_the_ability_names_an_unknown_scheme(self) -> None:
        doc = probe()
        get_op(doc)["security"] = [{"basicAuth": ["catalog.browse"]}]
        self.assert_problem(doc, "GET /_probe: no token ability")


class ReferenceTest(unittest.TestCase):
    def assert_problem(self, doc: dict[str, Any], fragment: str) -> None:
        problems = oq.problems(doc)
        self.assertTrue(any(fragment in p for p in problems), f"no problem with {fragment!r} in {problems}")

    def test_the_inferred_error_responses_are_references(self) -> None:
        responses = archive_op(probe())["responses"]
        self.assertEqual(responses["403"], {"$ref": "#/components/responses/AuthorizationException"})
        self.assertEqual(responses["422"], {"$ref": "#/components/responses/ValidationException"})

    def test_accepts_error_responses_inferred_by_scramble(self) -> None:
        self.assertEqual([p for p in oq.problems(probe()) if "/_probe/archive" in p], [])

    def test_fails_when_a_referenced_error_response_has_no_meaning(self) -> None:
        doc = probe()
        doc["components"]["responses"]["ValidationException"]["description"] = ""
        self.assert_problem(doc, "POST /_probe/archive: response 422 has no description")

    def test_fails_on_a_reference_that_resolves_to_nothing(self) -> None:
        doc = probe()
        archive_op(doc)["responses"]["403"] = {"$ref": "#/components/responses/Missing"}
        self.assert_problem(doc, "POST /_probe/archive: response 403 has no description")

    def test_a_referenced_success_response_needs_an_example(self) -> None:
        doc = probe()
        response = archive_op(doc)["responses"]["200"]
        del response["content"]["application/json"]["schema"]["examples"]
        doc["components"]["responses"]["Archived"] = response
        archive_op(doc)["responses"]["200"] = {"$ref": "#/components/responses/Archived"}
        self.assert_problem(doc, "POST /_probe/archive: response 200 application/json has no example")

    def test_resolves_a_referenced_request_body(self) -> None:
        doc = probe()
        doc["components"]["requestBodies"] = {"Archive": archive_op(doc)["requestBody"]}
        archive_op(doc)["requestBody"] = {"$ref": "#/components/requestBodies/Archive"}
        self.assertEqual(oq.problems(doc), [])
        del doc["components"]["requestBodies"]["Archive"]["content"]["application/json"]["schema"]["properties"]["reason"]["examples"]
        self.assert_problem(doc, "POST /_probe/archive: request body application/json has no example")

    def test_resolves_a_referenced_parameter(self) -> None:
        doc = probe()
        doc["components"]["parameters"] = {"Page": {"name": "page", "in": "query", "schema": {"type": "integer"}}}
        get_op(doc)["parameters"] = [{"$ref": "#/components/parameters/Page"}]
        self.assert_problem(doc, "GET /_probe: parameter page (query) has no description")
        doc["components"]["parameters"]["Page"]["description"] = "The page"
        self.assertEqual(oq.problems(doc), [])

    def test_resolves_a_referenced_example(self) -> None:
        doc = probe()
        media = get_op(doc)["responses"]["200"]["content"]["application/json"]
        del media["schema"]["examples"]
        media["examples"] = {"probe": {"$ref": "#/components/examples/Probe"}}
        self.assert_problem(doc, "GET /_probe: response 200 application/json has no example")
        doc["components"]["examples"] = {"Probe": {"value": {"name": "probe"}}}
        self.assertEqual(oq.problems(doc), [])


class LoopholeTest(unittest.TestCase):
    def assert_problem(self, doc: dict[str, Any], fragment: str) -> None:
        problems = oq.problems(doc)
        self.assertTrue(any(fragment in p for p in problems), f"no problem with {fragment!r} in {problems}")

    def test_operation_ids_differing_only_in_case_are_duplicates(self) -> None:
        doc = probe()
        put_op(doc)["operationId"] = "Probe.Show"
        self.assert_problem(doc, "PUT /_probe: operationId Probe.Show is also used by GET /_probe")

    def test_a_description_of_zero_width_characters_is_empty(self) -> None:
        doc = probe()
        get_op(doc)["description"] = "\u200b\u00a0\ufeff"
        self.assert_problem(doc, "GET /_probe: no description")

    def test_an_anonymous_security_alternative_fails(self) -> None:
        doc = probe()
        get_op(doc)["security"] = [{"apiToken": ["catalog.browse"]}, {}]
        self.assert_problem(doc, "GET /_probe: an empty security requirement lets anonymous callers in")

    def test_a_scope_outside_the_permission_catalogue_fails(self) -> None:
        doc = probe()
        put_op(doc)["security"] = [{"apiToken": ["update"]}]
        problems = oq.problems(doc, permissions={"catalog.browse", "catalog.manage"})
        self.assertIn("PUT /_probe: token ability update is not in the permission catalogue", problems)

    def test_the_catalogue_check_passes_known_permissions(self) -> None:
        self.assertEqual(oq.problems(probe(), permissions={"catalog.browse", "catalog.manage"}), [])

    def test_without_a_catalogue_scopes_are_not_compared(self) -> None:
        doc = probe()
        put_op(doc)["security"] = [{"apiToken": ["update"]}]
        self.assertEqual(oq.problems(doc), [])


class MainTest(unittest.TestCase):
    def run_main(self, doc: dict[str, Any], permissions: list[str] | None = None) -> tuple[int, str, str]:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "openapi.json"
            path.write_text(json.dumps(doc), encoding="utf-8")
            args = [str(path)]
            if permissions is not None:
                catalogue = Path(tmp) / "permissions.json"
                catalogue.write_text(json.dumps(permissions), encoding="utf-8")
                args += ["--permissions", str(catalogue)]
            out, err = io.StringIO(), io.StringIO()
            with redirect_stdout(out), redirect_stderr(err):
                code = oq.main(args)
        return code, out.getvalue(), err.getvalue()

    def test_reads_the_permission_catalogue_from_a_file(self) -> None:
        code, _, err = self.run_main(probe(), permissions=["catalog.browse"])
        self.assertEqual(code, 1)
        self.assertIn("token ability catalog.manage is not in the permission catalogue", err)

    def test_exits_0_and_counts_the_operations(self) -> None:
        code, out, _ = self.run_main(probe())
        self.assertEqual(code, 0)
        self.assertIn("3 operations", out)

    def test_exits_1_and_names_the_operation_without_a_description(self) -> None:
        doc = copy.deepcopy(probe())
        del get_op(doc)["description"]
        code, _, err = self.run_main(doc)
        self.assertEqual(code, 1)
        self.assertIn("::error::GET /_probe: no description", err)

    def test_exits_1_on_a_file_that_is_not_json(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "openapi.json"
            path.write_text("{", encoding="utf-8")
            err = io.StringIO()
            with redirect_stderr(err), redirect_stdout(io.StringIO()):
                code = oq.main([str(path)])
        self.assertEqual(code, 1)
        self.assertIn("not JSON", err.getvalue())


if __name__ == "__main__":
    unittest.main()
