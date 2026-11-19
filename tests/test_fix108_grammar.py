"""Fix 108 (CVE-2026-44381 MISP corpus) — grammar gaps regression tests.

Families (each was a hard parse failure on the MISP / CakePHP 2.x corpus):
  A ::-access on object chains      $this->User::CONST, $this->A->B::FOO,
                                    $o::FOO, $this->A::$stat
  B soft-reserved words as names    `public function from(...)`,
                                    `const NAMESPACE = ...`, `$x->from()`
  C array()-literal subscript       array('a','b')[$i]
  D NEW on dynamic class chains     new $this->prop(),
                                    new $this->validFormats[$fmt][1](),
                                    plus the regressions the first WIP
                                    shape introduced: new CakeResponse()
                                    (LPAREN dead end) and `(1) + 2` static
                                    exprs (closure poisoning via
                                    class_constant -> variable_class_name
                                    -> member_chain -> function_call).
  E ::class on chains               $this->obj->prop::class (Fix 107 kept)
  F call-result chains              C::f()->g()[0] (codeigniter4 corpus 399)

Out of scope (zero corpus instances, PHP 8 only): `['a'=>1]['a']`
short-literal subscript and `$o->m()::CONST` call-result static access.
"""
import pytest

from phply.phpparse import make_parser
from phply import phplex, phpast


def parse(code):
    return make_parser().parse(code, debug=False, lexer=phplex.lexer.clone(),
                               tracking=True)


class TestChainStaticAccess:
    def test_prop_const(self):
        tree = parse("<?php\n$x = $this->User::PERIODIC_NOTIFICATIONS;\n")
        node = tree[0].expr
        assert isinstance(node, phpast.StaticProperty)
        assert isinstance(node.node, phpast.ObjectProperty)
        assert node.node.name == 'User'
        assert node.name == 'PERIODIC_NOTIFICATIONS'

    def test_two_step_chain_const(self):
        tree = parse("<?php\n$x = $this->A->B::FOO;\n")
        node = tree[0].expr
        assert isinstance(node, phpast.StaticProperty)
        assert isinstance(node.node, phpast.ObjectProperty)
        assert node.node.name == 'B'

    def test_plain_variable_const(self):
        tree = parse("<?php\n$x = $o::FOO;\n")
        assert isinstance(tree[0].expr, phpast.StaticProperty)

    def test_chain_static_prop(self):
        tree = parse("<?php\n$x = $this->A::$s;\n")
        node = tree[0].expr
        assert isinstance(node, phpast.StaticProperty)
        assert isinstance(node.name, phpast.Variable)

    def test_chain_const_deref(self):
        # UsersController:3304 — $this->User::HEARTBEAT_MESSAGES[rand(0, ...)]
        tree = parse("<?php\n$x = $this->User::MSG[rand(0, 2)];\n")
        assert isinstance(tree[0].expr, phpast.ArrayOffset)


class TestSoftReservedNames:
    def test_method_from(self):
        tree = parse("<?php\nclass C { public function from($x) { return 1; } }\n")
        assert isinstance(tree[0].nodes[0], phpast.Method)

    def test_method_match(self):
        tree = parse("<?php\nclass C { public function match($url) {} }\n")
        assert isinstance(tree[0].nodes[0], phpast.Method)

    def test_const_namespace(self):
        tree = parse("<?php\nclass C { const NAMESPACE = 'misp:benchmark:'; }\n")
        constants = tree[0].nodes[0]
        assert isinstance(constants, phpast.ClassConstants)
        assert isinstance(constants.nodes[0], phpast.ClassConstant)
        assert constants.nodes[0].name == 'NAMESPACE'

    def test_const_namespace_top(self):
        tree = parse("<?php\nconst NAMESPACE = 1;\n")
        decls = tree[0]
        assert isinstance(decls, phpast.ConstantDeclarations)
        assert isinstance(decls.nodes[0], phpast.ConstantDeclaration)
        assert decls.nodes[0].name == 'NAMESPACE'

    def test_member_call_reserved(self):
        tree = parse("<?php\nreturn $this->m->from('x');\n")
        node = tree[0].node  # phpast.Return carries .node
        assert isinstance(node, phpast.MethodCall)
        assert node.name == 'from'

    def test_static_enum_call(self):
        # Set::enum() — ENUM in a static-call name slot
        tree = parse("<?php\n$x = Set::enum($y);\n")
        assert isinstance(tree[0].expr, phpast.StaticMethodCall)
        assert tree[0].expr.name == 'enum'


class TestArrayLiteralSubscript:
    def test_array_call_deref(self):
        # FeedsController:1090 — array('disabled', 'enabled')[$enable]
        tree = parse("<?php\n$x = array('a', 'b')[$i];\n")
        node = tree[0].expr
        assert isinstance(node, phpast.ArrayOffset)
        assert isinstance(node.node, phpast.Array)


class TestNewDynamicChains:
    def test_new_prop_class(self):
        tree = parse("<?php\nclass C { protected $cc; function f() { $i = new $this->cc(); } }\n")
        method = [n for n in tree[0].nodes if isinstance(n, phpast.Method)][0]
        node = method.nodes[0].expr
        assert isinstance(node, phpast.New)
        assert isinstance(node.name, phpast.ObjectProperty)

    def test_new_dim_chain_class(self):
        # MispObject:1621 — new $this->validFormats[$returnFormat][1]()
        tree = parse("<?php\nfunction f($fmt) { $e = new $this->validFormats[$fmt][1](); }\n")
        node = tree[0].nodes[0].expr
        assert isinstance(node, phpast.New)
        assert isinstance(node.name, phpast.ArrayOffset)

    def test_new_plain_name_untouched(self):
        # regression guard: `new CakeResponse()` must not shift into the
        # function_call dead end (first WIP shape, D6)
        tree = parse("<?php\nfunction f() { $r = new CakeResponse(); }\n")
        node = tree[0].nodes[0].expr
        assert isinstance(node, phpast.New)
        assert node.name == 'CakeResponse'

    def test_new_plain_variable(self):
        tree = parse("<?php\nfunction f($tool) { $c = new $tool(); }\n")
        assert isinstance(tree[0].nodes[0].expr, phpast.New)


class TestStaticExpressionRegression:
    # The function_call base under member_chain poisons const/property
    # initializer closures — guard both shapes (azuracast / codeigniter4).
    def test_const_paren_binop(self):
        tree = parse("<?php\nconst T = (60 * 60 * 13) + 150;\n")
        decls = tree[0]
        assert isinstance(decls, phpast.ConstantDeclarations)
        decl = decls.nodes[0]
        assert isinstance(decl, phpast.ConstantDeclaration)
        assert decl.name == 'T'
        assert isinstance(decl.initial, phpast.BinaryOp)

    def test_typed_const_union_paren(self):
        tree = parse("<?php\nclass C { private const int|float T = (1) + 2; }\n")
        constants = tree[0].nodes[0]
        assert isinstance(constants, phpast.ClassConstants)
        assert isinstance(constants.nodes[0], phpast.ClassConstant)
        assert constants.nodes[0].name == 'T'

    def test_prop_paren_group(self):
        tree = parse("<?php\nclass C { public $t = (1 + 2); }\n")
        variables = tree[0].nodes[0]
        assert isinstance(variables, phpast.ClassVariables)
        assert isinstance(variables.nodes[0], phpast.ClassVariable)
        assert isinstance(variables.nodes[0].initial, phpast.BinaryOp)

    def test_prop_ternary_paren(self):
        tree = parse("<?php\nclass C { public $t = (X === 'p') ? 4 : 9; }\n")
        variables = tree[0].nodes[0]
        assert isinstance(variables, phpast.ClassVariables)
        assert isinstance(variables.nodes[0], phpast.ClassVariable)
        assert isinstance(variables.nodes[0].initial, phpast.TernaryOp)


class TestChainClassConstant:
    def test_chain_dunder_class(self):
        tree = parse("<?php\nfunction f() { $n = $this->obj->prop::class; }\n")
        node = tree[0].nodes[0].expr
        assert isinstance(node, phpast.MagicConstant)


class TestCallResultChains:
    def test_static_call_chain_deref(self):
        # codeigniter4 399 — static::autoloader()->getNamespace('X')[0]
        tree = parse("<?php\n$p = static::autoloader()->getNamespace('X')[0];\n")
        assert isinstance(tree[0].expr, phpast.ArrayOffset)

    def test_class_call_chain_deref(self):
        tree = parse("<?php\n$p = C::autoloader()->getNamespace('X')[0];\n")
        assert isinstance(tree[0].expr, phpast.ArrayOffset)

    def test_default_static_call_chain(self):
        # Fix 54 kept: $x = C::default()->getProfileName();
        tree = parse("<?php\n$x = C::default()->getProfileName();\n")
        assert isinstance(tree[0].expr, phpast.MethodCall)
