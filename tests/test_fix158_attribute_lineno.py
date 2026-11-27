r"""Fix 158 (CVE-2026-124479): attribute-block newline double counting.

t_php_COMMENT's '#[' attribute branch re-based t.value on the full
attribute block and added its whole newline count, even though the
initial regex match (first line + trailing newline) had already been
counted at the top of the rule — and for single-line attributes the
rewound lexpos re-lexed the trailing newline as whitespace. Every
attribute block therefore drifted t.lexer.lineno by +1 (openemr
DocumentRestController.php: 3 #[OA\Get] blocks reported
`new BinaryFileResponse` at line 164 instead of 161).
"""
from phply.phplex import lexer
from phply.phpparse import make_parser


def _call_lines(src, name='sqlStatement'):
    """Return linenos of FunctionCalls named *name* in *src*."""
    from phply import phpast
    parser = make_parser()
    tree = parser.parse(src, debug=False, lexer=lexer.clone(), tracking=True)
    seen, out = set(), []

    def walk(n):
        if id(n) in seen:
            return
        seen.add(id(n))
        if isinstance(n, phpast.Node):
            if getattr(n, 'name', None) == name:
                out.append(n.lineno)
            for c in vars(n).values():
                walk(c)
        elif isinstance(n, (list, tuple)):
            for x in n:
                walk(x)

    walk(tree)
    return out


def test_multiline_attribute_no_drift():
    src = (
        "<?php\n"
        "class C {\n"
        "    #[OA\\Get(\n"
        "        path: '/api/doc',\n"
        "        responses: [\n"
        "            new OA\\Response(response: '200'),\n"
        "        ]\n"
        "    )]\n"
        "    public function f($pid) {\n"
        "        sqlStatement($q);\n"
        "    }\n"
        "}\n"
    )
    assert _call_lines(src) == [10]


def test_singleline_attribute_followed_by_newline_no_drift():
    src = (
        "<?php\n"
        "#[Attribute]\n"
        "sqlStatement($q);\n"
    )
    assert _call_lines(src) == [3]


def test_singleline_inline_attribute_no_drift():
    src = (
        "<?php\n"
        "#[X] sqlStatement($q);\n"
    )
    assert _call_lines(src) == [2]


def test_three_attribute_blocks_cumulative_zero_drift():
    """The openemr mini scenario: 3 blocks must not accumulate +3."""
    block = (
        "    #[OA\\Get(\n"
        "        path: '/api/x',\n"
        "        security: [['auth' => []]]\n"
        "    )]\n"
    )
    src = (
        "<?php\nclass C {\n"
        + block + "    function a() { sqlStatement(1); }\n"
        + block + "    function b() { sqlStatement(2); }\n"
        + block + "    function c() { sqlStatement(3); }\n"
        "}\n"
    )
    assert _call_lines(src) == [7, 12, 17]


def test_attribute_nested_brackets_no_drift():
    src = (
        "<?php\n"
        "#[Route('/x', requirements: ['id' => '\\\\d+'], methods: ['GET'])]\n"
        "sqlStatement($q);\n"
    )
    assert _call_lines(src) == [3]


def test_comments_between_attributes_still_count():
    src = (
        "<?php\n"
        "// ordinary comment\n"
        "#[Attribute]\n"
        "/* block\n"
        "   comment */\n"
        "#[Attribute]\n"
        "sqlStatement($q);\n"
    )
    assert _call_lines(src) == [7]
