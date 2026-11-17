"""Fix 107 grammar tests — CVE-2026-126482 (OpenEMR) corpus gaps.

Four PHP constructs admitted by Fix 107:
1. Grouped use statements (PHP 7): `use A\B\{C, D as E, F\G,};`
2. `class:` as a named-argument label (PHP 8.0 keyword labels).
3. `->prop::class` — dynamic class resolution on a member chain (PHP 8.0).
4. `die`/`exit` as method/function names (DIE/EXIT lex to dedicated tokens).
"""
import phply.phpast as phpast


def _parse(src):
    from phply.phpparse import make_parser
    from phply import phplex
    return make_parser().parse(src, lexer=phplex.lexer.clone(), tracking=True, debug=False)


def test_grouped_use_flat():
    t = _parse("<?php use OpenEMR\\Common\\{Acl\\AclMain, Session\\SWF as SWF2,};")
    uses = [u for u in t if isinstance(u, phpast.UseDeclarations)]
    assert len(uses) == 1
    decls = uses[0].nodes
    assert [d.name for d in decls] == [
        'OpenEMR\\Common\\Acl\\AclMain', 'OpenEMR\\Common\\Session\\SWF']
    assert decls[1].alias == 'SWF2'


def test_grouped_use_leading_ns_separator():
    t = _parse("<?php use \\OpenEMR\\BC\\{ServiceContainer, DatabaseConnectionFactory};")
    uses = [u for u in t if isinstance(u, phpast.UseDeclarations)]
    assert [d.name for d in uses[0].nodes] == [
        '\\OpenEMR\\BC\\ServiceContainer', '\\OpenEMR\\BC\\DatabaseConnectionFactory']


def test_grouped_use_ungrouped_shape_parity():
    a = _parse("<?php use OpenEMR\\Common\\Acl\\AclMain;")
    b = _parse("<?php use OpenEMR\\Common\\{Acl\\AclMain};")
    da, db = a[0].nodes[0], b[0].nodes[0]
    assert da.name == db.name and da.alias == db.alias


def test_named_arg_class_label():
    t = _parse("<?php $x = getDetailLine(class: 'olddetail', invnumber: $inv);")
    # no exception == admitted; find the NamedParameter with label 'class'
    from phply.phpast import Node
    found = []

    def walk(n):
        if isinstance(n, Node):
            if type(n).__name__ == 'NamedParameter' and getattr(n, 'name', None) == 'class':
                found.append(n)
            for v in vars(n).values():
                walk(v)
        elif isinstance(n, (list, tuple)):
            for v in n:
                walk(v)
    walk(t)
    assert found, 'NamedParameter(class) not produced'


def test_member_chain_class_constant():
    t = _parse("<?php $y = strtolower($p['s']->dataSourceClass::class);")
    assert t  # parses; MagicConstant('::class', chain) produced by p_variable


def test_die_exit_method_names():
    t = _parse(
        "<?php class I { protected function die(string $m): never { error_log($m); "
        "exit(1); } public function exit(): void {} }")
    classes = [n for n in t if isinstance(n, phpast.Class)]
    assert classes, 'class declaration failed'
    method_names = [type(n).__name__ for n in classes[0].nodes]
    assert 'Method' in method_names, 'die/exit methods not admitted'
