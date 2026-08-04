#!/usr/bin/env python3
"""Render the XLIFF files of d_translate from source.py + lang/*.py.

    python3 Build/i18n/build.py

Writes Resources/Private/Language/<file>.xlf (English source) and
<code>.<file>.xlf for every catalogue in lang/. A language may translate a
subset; missing ids simply keep the English source at runtime.
"""

from __future__ import annotations

import importlib.util
import pathlib
import sys
from xml.sax.saxutils import escape

HERE = pathlib.Path(__file__).resolve().parent
TARGET = HERE.parent.parent / 'Resources' / 'Private' / 'Language'
DATE = '2026-07-28T00:00:00Z'


def load(path: pathlib.Path, name: str):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def unit(id_: str, source: str, target: str | None) -> str:
    body = f'            <trans-unit id="{escape(id_)}" xml:space="preserve">\n'
    body += f'                <source>{escape(source)}</source>\n'
    if target is not None:
        body += f'                <target>{escape(target)}</target>\n'
    return body + '            </trans-unit>\n'


def render(filename: str, strings: dict[str, str], code: str | None, translations: dict[str, str]) -> str:
    attrs = f'source-language="en" datatype="plaintext" original="messages" date="{DATE}" product-name="d_translate"'
    if code:
        attrs = f'target-language="{code}" ' + attrs
    out = '<?xml version="1.0" encoding="UTF-8"?>\n<xliff version="1.0">\n'
    out += f'    <file {attrs}>\n        <header/>\n        <body>\n'
    for id_, source in strings.items():
        target = translations.get(id_) if code else None
        if code and target is None:
            continue
        out += unit(id_, source, target)
    return out + '        </body>\n    </file>\n</xliff>\n'


def main() -> int:
    source = load(HERE / 'source.py', 'dtranslate_source')
    TARGET.mkdir(parents=True, exist_ok=True)
    written = []

    for filename, strings in source.FILES.items():
        (TARGET / filename).write_text(render(filename, strings, None, {}), encoding='utf-8')
        written.append(filename)

    for path in sorted((HERE / 'lang').glob('*.py')):
        if path.name.startswith('_'):
            continue
        code = path.stem
        catalogue = load(path, f'dtranslate_lang_{code}').MESSAGES
        unknown = set(catalogue) - {k for s in source.FILES.values() for k in s}
        if unknown:
            print(f'  ! {code}: unknown ids {sorted(unknown)}', file=sys.stderr)
        for filename, strings in source.FILES.items():
            translated = {k: v for k, v in catalogue.items() if k in strings}
            if not translated:
                continue
            name = f'{code}.{filename}'
            (TARGET / name).write_text(render(filename, strings, code, translated), encoding='utf-8')
            written.append(name)
        missing = len({k for s in source.FILES.values() for k in s}) - len(catalogue)
        if missing:
            print(f'  · {code}: {missing} string(s) not translated, English is used')

    print(f'{len(written)} file(s) written to {TARGET}')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
