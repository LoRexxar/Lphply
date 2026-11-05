"""Fix 54 grammar tests — PHP 7.3 indented heredoc + reserved-word static calls.

Both gaps surfaced by the CVE-2026-121429 AzuraCast 0.23.8 corpus
(ConfigWriter.php alone hit both; 356/1149 files failed to parse before).
"""
import pytest
from phply.phpparse import make_parser
from phply import phplex, phpast


def parse(code):
    return make_parser().parse(code, debug=False, lexer=phplex.lexer.clone(),
                               tracking=True)


class TestIndentedHeredoc:
    """PHP 7.3+ allows the closing identifier to be indented."""

    def test_indented_terminator_with_semicolon(self):
        tree = parse("<?php\n$s = <<<EOT\n  hi {$x} there\n  EOT;\necho $s;\n")
        assign = tree[0]
        assert isinstance(assign.expr, phpast.BinaryOp)
        assert assign.expr.left is not None  # heredoc joined with echo operand

    def test_indented_return_heredoc(self):
        tree = parse("<?php\nfunction f($v) {\n  return <<<LIQ\n  hello {$v} world\n  LIQ;\n}\n")
        fn = tree[0]
        ret = fn.nodes[0]
        assert isinstance(ret, phpast.Return)

    def test_flat_terminator_still_works(self):
        tree = parse("<?php\n$s = <<<EOT\nhi\nEOT;\necho $s;\n")
        assert len(tree) == 2

    def test_lookalike_label_stays_body(self):
        # EOTX inside the body must NOT terminate the heredoc.
        tree = parse("<?php\n$s = <<<EOT\n  EOTX\n  EOT;\necho $s;\n")
        assert len(tree) == 2

    def test_body_keeps_indentation_bytes(self):
        # Indent + lookalike identifier remain part of the body value.
        from phply import phplex as px
        ts = px.lexer.clone()
        ts.input("<?php\n$s = <<<EOT\n  EOTX y\n  EOT;\n")
        body = []
        while True:
            t = ts.token()
            if not t:
                break
            if t.type == 'ENCAPSED_AND_WHITESPACE':
                body.append(t.value)
        assert any('EOTX' in b for b in body)


class TestReservedWordStaticCall:
    """PHP 7.0+ reserved keywords are legal method names (enum factories)."""

    def test_class_default_call(self):
        tree = parse("<?php\n$x = C::default();\n")
        call = tree[0].expr
        assert isinstance(call, phpast.StaticMethodCall)
        assert call.name == 'default'

    def test_chained_after_default(self):
        tree = parse("<?php\n$x = C::default()->getProfileName();\n")
        call = tree[0].expr
        assert isinstance(call, phpast.MethodCall)

    def test_switch_default_unaffected(self):
        tree = parse("<?php\nswitch ($x) { default: break; }\n")
        assert isinstance(tree[0], phpast.Switch)

    def test_match_default_unaffected(self):
        tree = parse("<?php\n$r = match ($x) { default => 1 };\n")
        assert isinstance(tree[0].expr, phpast.Match)
