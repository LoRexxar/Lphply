"""Fix 53 grammar regression tests (PHP 8.x gaps found via CVE-2026-118755
Volmarg PMS v2.1.0 corpus — 124/365 files previously failed to parse).

Covers four syntax gaps (all previously masked behind earlier-first errors):
- ctor promoted params with `readonly` (private readonly T $x), both modifier
  orders and bare readonly promotion (readonly T $x)
- soft-reserved words as namespace segments (namespace App\\Enum, use ...\\Trait\\X)
- reserved words as method/function names (public function new(), echo, print)
- multi-line PHP 8 attributes #[AsCommand(...)] (lexed as a comment block with
  balanced-bracket consumption; single-line attrs were previously swallowed by
  the '#' comment rule by accident)
"""
import unittest
from phply import phplex, phpast
from phply.phpparse import make_parser


def parse(code):
    if not code.lstrip().startswith('<?php'):
        code = '<?php ' + code
    return make_parser().parse(code, lexer=phplex.lexer.clone())


class TestReadonlyPromotion:
    def test_promo_readonly_typed(self):
        tree = parse('class A { public function __construct(private readonly string $x) {} }')
        cls = tree[0]
        ctor = [n for n in cls.nodes if isinstance(n, phpast.Method)][0]
        assert ctor.name == '__construct'
        assert len(ctor.params) == 1

    def test_readonly_before_visibility(self):
        tree = parse('class A { public function __construct(readonly private string $x) {} }')
        assert tree

    def test_bare_readonly_promo(self):
        tree = parse('class A { public function __construct(readonly ParameterBagInterface $bag) {} }')
        assert tree

    def test_readonly_promo_default(self):
        parse('class A { public function __construct(private readonly string $x = "v") {} }')

    def test_readonly_nullable_promo(self):
        parse('class A { public function __construct(private readonly ?string $x = null) {} }')

    def test_mixed_promo_list(self):
        parse('''class A {
    public function __construct(
        private readonly Repo $r,
        readonly ParameterBagInterface $bag,
    ) {} }''')


class TestSoftReservedNamespace:
    def test_namespace_enum(self):
        parse('namespace App\\Enum;')

    def test_namespace_trait(self):
        parse('namespace App\\Entity\\Trait;')

    def test_use_trait_segment(self):
        parse('use App\\Entity\\Trait\\SoftDeleteAwareTrait;')

    def test_namespace_match(self):
        parse('namespace App\\Match;')

    def test_enum_body_still_works(self):
        tree = parse('enum E: string { case A = "a"; }')
        assert tree


class TestReservedMethodNames:
    def test_method_new(self):
        tree = parse('class A { public function new(): void {} }')
        cls = tree[0]
        m = [n for n in cls.nodes if isinstance(n, phpast.Method)][0]
        assert m.name == 'new'

    def test_method_echo(self):
        parse('class A { public function echo(): void {} }')

    def test_method_print(self):
        parse('class A { public function print(): void {} }')

    def test_promo_then_method_new(self):
        # regression: class with promoted ctor followed by method named new()
        parse('class A { public function __construct(private Repo $r) {} public function new(): void {} }')


class TestAttributes:
    def test_single_line_attr(self):
        tree = parse('#[Route("/x")] class A {}')
        assert isinstance(tree[0], phpast.Class)

    def test_multiline_attr(self):
        parse('''#[AsCommand(
    "storage:upload",
    "Creates DB entries"
)]
class C {}''')

    def test_attr_named_args_and_array(self):
        parse('#[Route("", name: "new", methods: [Request::METHOD_POST])] class A {}')

    def test_two_attrs(self):
        parse('#[A("x")] #[B("y")] class C {}')

    def test_hash_comment_still_comment(self):
        tree = parse('$a = 1; # plain comment\n$b = 2;')
        assert tree

    def test_attr_multiline_does_not_eat_file(self):
        # regression for the lexpos bug: attribute swallow must not run to EOF
        code = '''<?php
#[Route("/module/payment/bills", name: "bills.")]
class BillsAction {
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    #[Route("", name: "new", methods: [Request::METHOD_POST])]
    public function new(Request $request): JsonResponse
    {
        return $this->createOrUpdate($request);
    }
}'''
        tree = parse(code)
        cls = tree[0]
        methods = [n.name for n in cls.nodes if isinstance(n, phpast.Method)]
        assert '__construct' in methods and 'new' in methods


class TestPmsRealShapes:
    def test_shell_abstract_service_ctor(self):
        parse('class S { public function __construct(\\n        private readonly LoggerInterface $logger,\\n    ) {} }'.replace('\\n', '\n'))

    def test_union_type_null_first(self):
        parse('function f(null|int|float $timeout = null) {}')
