"""Fix 52 grammar regression tests (PHP 7.x/8.x syntax gaps).

These productions were previously patched only in the scan venv's
site-packages (violating the release-chain rule); they are now part of
the package proper (lphply 2.2.2, Kunlun-M Fix 52 / CVE-2026-110185):
- 52-3: closures with return type  function (): void {}
- 52-4/4b: 'match' as method/member name (Route::match, $x->match)
- 52-6: destructuring holes  [$a, , $b]
- 52b: trailing comma in call args  f($a, $b,)
NOTE: the p_variable_class_constant_offset rule (Class::CONST['key']) is
kept for parity with the deployed venv state but is dead code — yacc
resolves its production conflict away and it never reduces. Not tested.
"""
import unittest
from phply import phplex
from phply.phpparse import make_parser


def parse(code):
    parser = make_parser()
    return parser.parse(code, lexer=phplex.lexer.clone())


class Fix52GrammarTest(unittest.TestCase):
    def test_closure_return_type(self):
        tree = parse('<?php $f = function (): void { return; };')
        self.assertTrue(len(tree) > 0)

    def test_static_closure_return_type(self):
        tree = parse('<?php $f = static function (): int { return 1; };')
        self.assertTrue(len(tree) > 0)

    def test_match_as_method_name(self):
        tree = parse('<?php Route::match("get", "/x", $h);')
        self.assertTrue(len(tree) > 0)

    def test_match_as_member_access(self):
        tree = parse('<?php $x = $obj->match;')
        self.assertTrue(len(tree) > 0)

    def test_class_match_static_member(self):
        tree = parse('<?php $x = SomeClass::match;')
        self.assertTrue(len(tree) > 0)

    def test_destructuring_hole(self):
        tree = parse('<?php [$a, , $b] = $arr;')
        self.assertTrue(len(tree) > 0)

    def test_trailing_comma_call(self):
        tree = parse('<?php f($a, $b,);')
        self.assertTrue(len(tree) > 0)


if __name__ == '__main__':
    unittest.main()
