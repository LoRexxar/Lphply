"""Frozen-corpus parse gate — the structural fix for recurring "syntax
breakage" incidents (user's design question, 2026-11).

Why this exists
---------------
Every grammar gap so far (Fix 52/53/54: 30+ syntax holes) was found
*after release*, when a CVE corpus died silently:
  - CVE-2026-118755 (PMS): closure return types       → Fix 53
  - CVE-2026-121429 (AzuraCast): 356/1149 files dead → Fix 54
    (indented heredoc/nowdoc, reserved-word statics, property hooks,
    yield from, 0o octal, closure use-order, ...)
lphply's unit tests only cover shapes we have already seen. This gate
freezes a multi-framework corpus (17 sources, classic CMS → PHP 8.4)
and asserts 100% parse — so the *next* grammar gap fails CI before it
ships, not when a CVE target goes dark.

If this test fails
------------------
DO NOT relax the assertion. Minimize the failing file (binary-search
statements), fix the grammar, add a unit test, bump the version.
"""
import os
import pytest
from phply.phpparse import make_parser
from phply import phplex

CORPUS_DIR = os.path.join(os.path.dirname(__file__), 'corpus', 'php')


def _corpus_files():
    if not os.path.isdir(CORPUS_DIR):
        return []
    return sorted(
        os.path.join(CORPUS_DIR, f)
        for f in os.listdir(CORPUS_DIR)
        if f.endswith(('.php', '.phtml', '.inc'))
    )


def _parse_file(path):
    with open(path, encoding='utf-8', errors='ignore') as fh:
        src = fh.read()
    parser = make_parser()
    return parser.parse(src, debug=False, lexer=phplex.lexer.clone(),
                        tracking=True)


@pytest.mark.skipif(not _corpus_files(),
                    reason='corpus/php is empty (submodule not checked out)')
def test_frozen_corpus_100_percent_parse():
    files = _corpus_files()
    assert len(files) >= 200, (
        f'corpus shrank: {len(files)} files (expected >=200) — '
        'someone deleted corpus entries; restore them')
    failures = []
    for path in files:
        try:
            _parse_file(path)
        except Exception as e:
            failures.append(f'{os.path.basename(path)}: {type(e).__name__}: {e}')
    assert not failures, (
        f'{len(failures)}/{len(files)} corpus files failed to parse '
        f'({100.0 * len(failures) / len(files):.1f}% corpus loss):\n'
        + '\n'.join(failures[:20])
        + ('\n... (truncated)' if len(failures) > 20 else '')
        + '\n→ minimize the first failure, fix the grammar, add a unit '
          'test. DO NOT delete the corpus file.')
