"""Fix 122 grammar tests — CVE-2026-69137 (SuiteCRM 7.15.1) corpus gap.

PHP 8.1 "new in initializers": a `new` expression as the default value of a
parameter (plain or promoted, readonly or not), class property default,
and const/enum-case initializer. Found in the SuiteCRM CalendarSync corpus
(CalendarSync.php / AbstractCalendarProvider.php / CalendarProviderRegistry.php
promoted constructor defaults like
`protected readonly CalendarProviderRegistry $providerRegistry = new CalendarProviderRegistry()`).

Fix: `static_expr : NEW class_name_reference ctor_arguments` — one rule covers
all initializer sites that share static_expr (params, properties, consts,
enum cases); ctor_arguments already has an empty arm for the PHP 8.4
paren-less `new Foo` form.
"""
import phply.phpast as phpast


def _parse(src):
    from phply.phpparse import make_parser
    from phply import phplex
    return make_parser().parse(src, lexer=phplex.lexer.clone(), tracking=True, debug=False)


def _walk(n):
    if isinstance(n, phpast.Node):
        yield n
        for v in vars(n).values():
            yield from _walk(v)
    elif isinstance(n, (list, tuple)):
        for v in n:
            yield from _walk(v)


def _params(cls_src):
    ast = _parse(cls_src)
    cls = ast[0]
    meth = [m for m in cls.nodes if getattr(m, 'name', '') == '__construct'][0]
    params = meth.params
    return list(params.nodes if hasattr(params, 'nodes') else params)


def _promoted_ctor(body):
    return '<?php class C { public function __construct(%s) {} }' % body


def test_promoted_new_default():
    params = _params(_promoted_ctor('protected Foo $x = new Foo()'))
    assert isinstance(params[0].default, phpast.New)
    assert params[0].default.name == 'Foo'
    assert params[0].default.params == []


def test_promoted_readonly_new_default():
    # CalendarSync.php:70 shape — `protected readonly Foo $x = new Foo()`
    params = _params(_promoted_ctor('protected readonly Foo $x = new Foo()'))
    assert isinstance(params[0].default, phpast.New)
    assert params[0].default.name == 'Foo'


def test_promoted_new_default_with_args():
    params = _params(_promoted_ctor('protected Foo $x = new Foo(1, 2)'))
    assert isinstance(params[0].default, phpast.New)
    assert [p.node for p in params[0].default.params] == [1, 2]


def test_nullable_new_default():
    # AbstractCalendarProvider.php:52 shape — `?Foo $x = new Foo()`
    params = _params(_promoted_ctor('?Foo $x = new Foo()'))
    assert isinstance(params[0].default, phpast.New)


def test_plain_function_new_default():
    ast = _parse('<?php function f(Foo $x = new Foo()) {}')
    fn = [n for n in ast if isinstance(n, phpast.Function)][0]
    params = fn.params
    prm = list(params.nodes if hasattr(params, 'nodes') else params)[0]
    assert isinstance(prm.default, phpast.New)


def test_new_without_parens_default():
    # PHP 8.4 paren-less new as initializer
    params = _params(_promoted_ctor('protected Foo $x = new Foo'))
    assert isinstance(params[0].default, phpast.New)


def test_property_new_default():
    ast = _parse('<?php class C { protected Foo $x = new Foo(); }')
    news = [n for n in _walk(ast) if isinstance(n, phpast.New)]
    assert len(news) == 1
    assert news[0].name == 'Foo'


def test_const_new_initializer():
    ast = _parse('<?php const X = new Foo();')
    assert [n for n in _walk(ast) if isinstance(n, phpast.New)]


def test_lineno_tracked():
    params = _params(_promoted_ctor("protected Foo $x = new Foo()\n"))
    assert params[0].default.lineno >= 1
