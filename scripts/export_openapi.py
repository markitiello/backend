"""Rigenera docs/openapi.json e docs/openapi.yaml dal codice.

Uso: `python scripts/export_openapi.py` (il test test_openapi.py verifica che
i file nel repository siano aggiornati).
"""

import json
from pathlib import Path

import yaml
from sqlalchemy import create_engine

from benzina_api.config import Settings
from benzina_api.main import create_app

DOCS = Path(__file__).resolve().parent.parent / "docs"


def build_spec() -> dict:
    app = create_app(
        Settings(database_url="sqlite://", _env_file=None), engine=create_engine("sqlite://")
    )
    return app.openapi()


def render(spec: dict) -> tuple[str, str]:
    return (
        json.dumps(spec, indent=2, ensure_ascii=False) + "\n",
        yaml.safe_dump(spec, allow_unicode=True, sort_keys=False),
    )


def main() -> None:
    as_json, as_yaml = render(build_spec())
    (DOCS / "openapi.json").write_text(as_json, encoding="utf-8")
    (DOCS / "openapi.yaml").write_text(as_yaml, encoding="utf-8")
    print("docs/openapi.json e docs/openapi.yaml aggiornati")


if __name__ == "__main__":
    main()
