from pathlib import Path

from scripts.export_openapi import build_spec, render

DOCS = Path(__file__).resolve().parent.parent / "docs"


def test_specifica_openapi_aggiornata():
    """Se fallisce: `python scripts/export_openapi.py` e committare i file."""
    as_json, as_yaml = render(build_spec())
    assert (DOCS / "openapi.json").read_text(encoding="utf-8") == as_json
    assert (DOCS / "openapi.yaml").read_text(encoding="utf-8") == as_yaml


def test_tutte_le_rotte_v1_sono_protette():
    spec = build_spec()
    assert set(spec["components"]["securitySchemes"]) == {"AppCheck", "ApiKey"}
    for path, operations in spec["paths"].items():
        for op in operations.values():
            if path.startswith("/v1/"):
                # Due requisiti alternativi: basta uno dei due header.
                assert op["security"] == [{"AppCheck": []}, {"ApiKey": []}], path
            else:
                assert "security" not in op, path


def test_errori_documentati_come_problem_json():
    spec = build_spec()
    op = spec["paths"]["/v1/stations/{station_id}"]["get"]
    assert "application/problem+json" in op["responses"]["404"]["content"]
