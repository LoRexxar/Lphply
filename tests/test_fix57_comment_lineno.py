"""Fix 57 (CVE-2026-126483): comment newline counting.

t_php_COMMENT used to count newlines only in the '#[' attribute branch,
so every ordinary `//`, `#`, and non-doc ``/* */`` comment lost its
newlines and t.lexer.lineno drifted from the real file line as parsing
progressed (openemr ippf_statistics.php: a call at real line 1460 was
reported at line 1310).
"""
from phply.phplex import lexer
from phply.phpparse import make_parser


def _call_lines(src):
    """Return linenos of sqlStatement FunctionCalls in *src*."""
    from phply import phpast
    parser = make_parser()
    tree = parser.parse(src, debug=False, lexer=lexer.clone(), tracking=True)
    seen, out = set(), []

    def walk(n):
        if id(n) in seen:
            return
        seen.add(id(n))
        if isinstance(n, phpast.Node):
            if getattr(n, 'name', None) == 'sqlStatement':
                out.append(n.lineno)
            for c in vars(n).values():
                walk(c)
        elif isinstance(n, (list, tuple)):
            for x in n:
                walk(x)

    walk(tree)
    return out


def test_line_comment_advances_lineno():
    src = (
        "<?php\n"
        "line1();\n"
        "// a comment\n"
        "//\n"
        "sqlStatement($q);\n"
    )
    assert _call_lines(src) == [5]


def test_hash_comment_advances_lineno():
    src = (
        "<?php\n"
        "# comment\n"
        "sqlStatement($q);\n"
    )
    assert _call_lines(src) == [3]


def test_block_comment_advances_lineno():
    src = (
        "<?php\n"
        "/* multi\n"
        "   line\n"
        "   block */\n"
        "sqlStatement($q);\n"
    )
    assert _call_lines(src) == [5]


def test_doc_comment_still_counts():
    src = (
        "<?php\n"
        "/**\n"
        " * doc\n"
        " */\n"
        "sqlStatement($q);\n"
    )
    assert _call_lines(src) == [5]


def test_openemr_drift_repro():
    """Cumulative drift: 5 leading //-comment lines must not shrink the
    reported line of a later call."""
    src = "<?php\n" + "// c\n" * 5 + "line1();\n" + "// c\n" * 3 + "sqlStatement($q);\n"
    # 1 open tag, 5 comments, 1 call, 3 comments => call at line 11
    assert _call_lines(src) == [11]


def test_inline_html_unaffected():
    src = (
        "<?php\n"
        "line1();\n"
        "?>\n"
        "<p>html</p>\n"
        "<?php\n"
        "// note\n"
        "sqlStatement($q);\n"
    )
    assert _call_lines(src) == [7]
