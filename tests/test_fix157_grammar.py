"""Fix 157 grammar tests (CVE-2026-124444, OpenEMR/Phreeze corpus).

157a: PHP 8.0 dynamic class-name resolution — any parenthesized
expression may be the base of `::`:
`($objectclass . "Map")::GetFieldMaps()` (Phreezer.php:708, the Phreeze
ORM hub whose SyntaxError silently excised the whole ORM from the graph).

157a2: paren groups in static initializers still parse after 157a pulled
`expr` into every static-initializer closure — the Fix 108
static-expression regression family (`const T = (60*60*13)+150`,
`public $t = (1+2)`, azuracast/codeigniter corpus files).

157c: PHP 8 constructor promotion modifier captured on FormalParameter
(`promoted` field) — evidence for the implicit `$this->x = $x` write.
"""
import phply.phplex as phplex
import phply.phpparse as pp
from phply.phpast import (
    Assignment, BinaryOp, ConstantDeclarations,
    ConstantDeclaration, FormalParameter, Method, Class, StaticMethodCall,
    StaticProperty, Variable,
)


def parse(code):
    return pp.make_parser().parse(
        code, debug=False, lexer=phplex.lexer.clone())


class TestDynamicClassName:
    """Fix 157a: (expr)::member forms."""

    def test_paren_expr_static_method_call(self):
        # Phreezer.php:708 verbatim shape
        tree = parse('<?php $x = ($objectclass . "Map")::GetFieldMaps();')
        (stmt,) = tree
        assert isinstance(stmt, Assignment)
        call = stmt.expr
        assert isinstance(call, StaticMethodCall)
        assert isinstance(call.class_, BinaryOp)
        assert call.name == 'GetFieldMaps'

    def test_paren_expr_class_constant(self):
        tree = parse('<?php $x = ($name)::FOO;')
        (stmt,) = tree
        assert isinstance(stmt.expr, StaticProperty)
        assert isinstance(stmt.expr.node, Variable) or stmt.expr.node is not None
        assert stmt.expr.name == 'FOO'

    def test_paren_expr_static_call_chained(self):
        tree = parse('<?php ($cls::PREFIX . "Sfx")::run();')
        (stmt,) = tree
        assert isinstance(stmt, StaticMethodCall)

    def test_new_paren_chain_still_works(self):
        # pre-existing arm: (new Foo())->bar — must not regress
        tree = parse('<?php (new Foo())->bar;')
        (stmt,) = tree
        assert stmt is not None


class TestStaticParenGroups:
    """Fix 157a2: static initializers keep their paren groups."""

    def test_const_paren_binop(self):
        # azuracast corpus verbatim
        tree = parse('<?php const T = (60 * 60 * 13) + 150;')
        (stmt,) = tree
        assert isinstance(stmt, ConstantDeclarations)
        (decl,) = stmt.nodes
        assert isinstance(decl, ConstantDeclaration)
        assert isinstance(decl.initial, BinaryOp)
        assert decl.initial.op == '+'

    def test_typed_const_union_paren(self):
        tree = parse('<?php class C { private const int|float T = (1) + 2; }')
        assert tree is not None

    def test_prop_paren_group(self):
        tree = parse('<?php class C { public $t = (1 + 2); }')
        assert tree is not None

    def test_prop_paren_ternary(self):
        # codeigniter4 corpus verbatim
        tree = parse(
            '<?php class C { public $threshold ='
            ' (ENVIRONMENT === "production") ? 4 : 9; }')
        assert tree is not None

    def test_const_paren_nested(self):
        tree = parse('<?php const C = 1+(2+3);')
        (stmt,) = tree
        (decl,) = stmt.nodes
        assert isinstance(decl.initial, BinaryOp)
        assert isinstance(decl.initial.right, BinaryOp)

    def test_static_expr_ternary_paren_shape(self):
        tree = parse('<?php class C { public $t = (A) ? 1 : 9; }')
        assert tree is not None


class TestConstructorPromotion:
    """Fix 157c: promotion modifier on FormalParameter."""

    def _ctor(self, code):
        (cls,) = parse(code)
        assert isinstance(cls, Class)
        (ctor,) = [n for n in cls.nodes if isinstance(n, Method)]
        return ctor

    def test_promoted_params_capture_modifier(self):
        ctor = self._ctor(
            '<?php class A { public function __construct('
            'public int $x, private string $y = \'a\') {} }')
        x, y = ctor.params
        assert x.promoted == 'public'
        assert y.promoted == 'private'

    def test_plain_params_stay_unpromoted(self):
        ctor = self._ctor(
            '<?php class A { public function __construct(int $z) {} }')
        (z,) = ctor.params
        assert z.promoted is None

    def test_promoted_param_ast_shape(self):
        ctor = self._ctor(
            '<?php class A { function __construct(protected $_sql) {} }')
        (p,) = ctor.params
        assert isinstance(p, FormalParameter)
        assert p.promoted == 'protected'
        assert p.name == '$_sql'


class TestPhreezeCorpusShape:
    """The exact corpus shapes that motivated 157a/157c."""

    def test_phreezer_dynamic_map(self):
        # Phreezer.php:708 shape: ($objectclass . "Map")::GetFieldMaps()
        tree = parse(
            '<?php $maps = ($this->objectclass . "Map")::GetFieldMaps();')
        assert tree is not None

    def test_dataset_promoted_ctor(self):
        # DataSet.php ctor shape: function __construct(&$preezer,
        # protected $_objectclass, private $_sql, ...)
        ctor = self._ctor_like(
            '<?php class DataSet { function __construct(&$p,'
            ' protected $_oc, private $_sql, $dd = null) {} }')
        oc, sql = ctor.params[1], ctor.params[2]
        assert oc.promoted == 'protected'
        assert sql.promoted == 'private'
        assert ctor.params[0].promoted is None
        assert ctor.params[3].promoted is None

    @staticmethod
    def _ctor_like(code):
        (cls,) = parse(code)
        (ctor,) = [n for n in cls.nodes if isinstance(n, Method)]
        return ctor
