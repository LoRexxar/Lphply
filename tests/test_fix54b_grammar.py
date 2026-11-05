"""Fix 54 grammar tests, part 2 — the long tail cleared for a 100% parse
rate on the CVE-2026-121429 AzuraCast 0.23.8 corpus (1149/1149 files,
up from 793 on the previous release).
"""
import pytest
from phply.phpparse import make_parser
from phply import phplex, phpast


def parse(code):
    return make_parser().parse(code, debug=False, lexer=phplex.lexer.clone(),
                               tracking=True)


class TestClosureUseThenReturnType:
    """PHP order: function () use ($x): T {} — return type AFTER use."""

    def test_use_then_ret(self):
        tree = parse("<?php\n$f = function () use ($c): void { return; };\n")
        assert isinstance(tree[0].expr, phpast.Closure)

    def test_static_closure_use_ret(self):
        tree = parse("<?php\n$f = static function ($v) use ($c): void { return; };\n")
        assert isinstance(tree[0].expr, phpast.Closure)


class TestShortListDestructForeach:
    def test_foreach_as_list(self):
        tree = parse("<?php\nforeach ($rows as [$a, $b]) { }\n")
        assert isinstance(tree[0], phpast.Foreach)


class TestPropertyHooks:
    def test_hook_with_default_value(self):
        tree = parse("<?php\nclass A { public string $n = '' { get => strtoupper($this->n); } }\n")
        cls = tree[0]
        assert cls.nodes  # parsed body

    def test_typed_param_short_hook(self):
        # The source-entity shape of CVE-2026-121429 itself.
        tree = parse("<?php\nclass A { public ?string $p = null {\n  set (?string $value) => Types::stringOrNull($value, true);\n} }\n")
        assert tree[0].nodes

    def test_abstract_hook_semi(self):
        tree = parse("<?php\ninterface I { public string $path { get; } }\n")
        assert tree[0].nodes

    def test_trait_property_hooks(self):
        tree = parse("<?php\ntrait T {\n  #[A, B(x: 1)]\n  public protected(set) string $a;\n  #[A]\n  public ?string $b = null { get => $this->b; }\n}\n")
        assert tree[0].nodes

    def test_ctor_promotion_with_hooks(self):
        tree = parse("<?php\nclass A { function __construct(public int $b, public ?X $s = null { get => $this->s; }) {} }\n")
        assert tree[0].nodes


class TestReservedWordNames:
    def test_visibility_enum_member(self):
        # `Visibility::PUBLIC` — enum case named after a keyword.
        tree = parse("<?php\n$v = (new C(a: Visibility::PUBLIC))->m();\n")
        assert isinstance(tree[0], phpast.MethodCall)

    def test_class_colon_class_still_bare(self):
        tree = parse("<? A::class;")
        assert tree[0] == 'A'

    def test_types_array_static_call(self):
        tree = parse("<?php\n$x = $y ?? Types::array($b);\n")
        assert isinstance(tree[0].expr, phpast.BinaryOp)


class TestFirstClassCallableVar:
    def test_bare_variable_fcc(self):
        tree = parse("<?php\n$this->x = $c(...);\n")
        # Assignment RHS is a FunctionCall wrapping the FCC form.
        assert isinstance(tree[0].expr, (phpast.FirstClassCallable, phpast.FunctionCall))


class TestAnonymousClassWithArgsAndHeritage:
    def test_new_class_args_extends(self):
        tree = parse("<?php\nreturn new class ($a, $this) extends P implements I {};\n")
        assert isinstance(tree[0], phpast.Return)


class TestConstExprEnumProp:
    def test_const_enum_arrow(self):
        tree = parse("<?php\nclass A { public int $l = Level::Info->value; }\n")
        assert tree[0].nodes


class TestExplicitOctal:
    def test_0o777(self):
        tree = parse("<?php\nchmod($f, 0o777);\n")
        call = tree[0]
        assert call.params[1].node == 511  # 0o777


class TestCallResultSubscript:
    def test_paren_subscript(self):
        tree = parse("<?php\nclass A { public string $t { get => (f($x))[0]; } }\n")
        assert tree[0].nodes


class TestFinalReadonlyClass:
    def test_final_readonly(self):
        tree = parse("<?php\nfinal readonly class B {}\n")
        assert isinstance(tree[0], phpast.Class)


class TestUnaryInConstExpr:
    def test_tilde(self):
        tree = parse("<?php\nclass A { const C = E_ALL & ~E_DEPRECATED; }\n")
        assert tree[0].nodes
