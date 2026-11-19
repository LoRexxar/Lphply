"""Fix 121 grammar tests — CVE-2026-111215 (Netgate pfSense CE) corpus gaps.

Three PHP constructs admitted by Fix 121 (all found in src/etc/inc/*.inc,
exposed by Kunlun-M Fix 120's .inc library discovery):
1. Bare constant array dereference: `CONST['key']` / `index_mark['a']['b']`
   (in isset(), call args, array-literal keys, nested bareword chains).
2. Static-local-var initializers as full expressions:
   `static $g = get_gateways(GW_CACHE_ALL);`
3. Parameter defaults widened static_scalar→static_expr:
   `function msort($a, $f = SORT_NATURAL | SORT_FLAG_CASE)`.
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


def test_const_subscript_assign():
    t = _parse("<?php $x = PFCONFIG_OPTION_LIMITS['id'];")
    assign = next(n for n in _walk(t) if isinstance(n, phpast.Assignment))
    off = assign.expr
    assert isinstance(off, phpast.ArrayOffset)
    assert isinstance(off.node, phpast.Constant) and off.node.name == 'PFCONFIG_OPTION_LIMITS'
    assert off.expr == 'id'


def test_const_subscript_in_isset():
    t = _parse("<?php if (isset(PFCONFIG_CATEGORIES[$rule['force_category']])) { return 1; }")
    issets = [n for n in _walk(t) if isinstance(n, phpast.IsSet)]
    assert len(issets) == 1
    off = issets[0].nodes[0]
    assert isinstance(off, phpast.ArrayOffset)
    assert isinstance(off.node, phpast.Constant) and off.node.name == 'PFCONFIG_CATEGORIES'


def test_const_subscript_as_call_arg():
    t = _parse("<?php $o->set_config(PFCONFIG_ETHERNET_CAPTIVE_PORTAL['id'], $v);")
    calls = [n for n in _walk(t) if isinstance(n, phpast.MethodCall)]
    arg0 = calls[0].params[0]
    if isinstance(arg0, phpast.Parameter):
        arg0 = arg0.node
    assert calls and isinstance(arg0, phpast.ArrayOffset)


def test_bareword_nested_subscript():
    t = _parse("<?php $m['last'] = index_mark['utf8']['last'];")
    assign = next(n for n in _walk(t) if isinstance(n, phpast.Assignment))
    outer = assign.expr
    assert isinstance(outer, phpast.ArrayOffset)
    inner = outer.node
    assert isinstance(inner, phpast.ArrayOffset)
    assert isinstance(inner.node, phpast.Constant) and inner.node.name == 'index_mark'
    assert inner.expr == 'utf8' and outer.expr == 'last'


def test_const_subscript_array_literal_key():
    t = _parse("<?php define('X', [PFCONFIG_OPTION_LIMITS['id'] => 'PFCONFIG_OPTION_LIMITS']);")
    arr = next(n for n in _walk(t) if isinstance(n, phpast.Array))
    key = arr.nodes[0].key
    assert isinstance(key, phpast.ArrayOffset)


def test_static_var_function_call_init():
    t = _parse("<?php function f() { static $g = get_gateways(1); return $g; }")
    sv = next(n for n in _walk(t) if isinstance(n, phpast.StaticVariable))
    assert sv.name == '$g'
    assert isinstance(sv.initial, phpast.FunctionCall)
    assert sv.initial.name == 'get_gateways'


def test_static_var_method_flip_init():
    # filter.inc: `static $order = array_flip(array_keys($this->order));`
    t = _parse("<?php function f() { static $order = array_flip(array_keys($this->order)); }")
    sv = next(n for n in _walk(t) if isinstance(n, phpast.StaticVariable))
    assert isinstance(sv.initial, phpast.FunctionCall)
    assert sv.initial.name == 'array_flip'
    inner = sv.initial.params[0]
    if isinstance(inner, phpast.Parameter):
        inner = inner.node
    assert isinstance(inner, phpast.FunctionCall)


def test_static_var_plain_scalar_still_ok():
    t = _parse("<?php function f() { static $n = 0; static $s = 'x', $t2; }")
    svs = [n for n in _walk(t) if isinstance(n, phpast.StaticVariable)]
    assert [sv.name for sv in svs] == ['$n', '$s', '$t2']
    assert svs[0].initial == 0 and svs[1].initial == 'x' and svs[2].initial is None


def test_param_default_bitwise_or():
    # util.inc msort(): `$sort_flags = SORT_NATURAL | SORT_FLAG_CASE`
    t = _parse("<?php function msort($array, $id = 'id', $sort_flags = SORT_NATURAL | SORT_FLAG_CASE) {}")
    fps = [n for n in _walk(t) if isinstance(n, phpast.FormalParameter)]
    assert len(fps) == 3
    default = fps[2].default
    assert isinstance(default, phpast.BinaryOp)
    assert default.op == '|'
    assert default.left.name == 'SORT_NATURAL' and default.right.name == 'SORT_FLAG_CASE'


def test_param_default_promoted_visibility():
    # promoted-constructor arms were widened the same way
    t = _parse("<?php class C { public function __construct(private int $n = 1 | 2) {} }")
    fps = [n for n in _walk(t) if isinstance(n, phpast.FormalParameter)]
    assert fps and isinstance(fps[0].default, phpast.BinaryOp)
