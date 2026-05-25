from __future__ import print_function

from phply import phplex
from phply.phpparse import make_parser
from phply.phpast import *

import pprint
import sys

parser = make_parser()

def eq_ast(input, expected, filename=None, with_top_lineno=False):
    lexer = phplex.lexer.clone()
    lexer.filename = filename
    output = parser.parse(input, lexer=lexer)
    resolve_magic_constants(output)

    print('Parser output:')
    pprint.pprint(output)
    print()

    print('Node by node:')
    for out, exp in zip(output, expected):
        print('\tgot:', out, '\texpected:', exp)
        assert out == exp

        # compare line numbers, but only for top elements
        if with_top_lineno:
            print('\tgot line:', out.lineno, '\texpected:', exp.lineno)
            assert out.lineno == exp.lineno

    assert len(output) == len(expected), \
           'output length was %d, expected %s' % (len(output), len(expected))

def test_inline_html():
    input = 'html <?php // php ?> more html'
    expected = [InlineHTML('html '), InlineHTML(' more html')]
    eq_ast(input, expected)

def test_echo():
    input = '<?php echo "hello, world!"; ?>'
    expected = [Echo(["hello, world!"])]
    eq_ast(input, expected)

def test_open_tag_with_echo():
    input = '<?= "hello, world!" ?><?= "test"; EXTRA; ?>'
    expected = [
        Echo(["hello, world!"]),
        Echo(["test"]),
        Constant('EXTRA'),
    ]
    eq_ast(input, expected)

def test_exit():
    input = '<?php exit; exit(); exit(123); die; die(); die(456); ?>'
    expected = [
        Exit(None, 'exit'), Exit(None, 'exit'), Exit(123, 'exit'),
        Exit(None, 'die'), Exit(None, 'die'), Exit(456, 'die'),
    ]
    eq_ast(input, expected)

def test_isset():
    input = r"""<?php
        isset($a);
        isset($b->c);
        isset($d['e']);
        isset($f, $g);
        isset($h->m()['i1']['i2']);
    ?>"""
    expected = [
        IsSet([Variable('$a')]),
        IsSet([ObjectProperty(Variable('$b'), 'c')]),
        IsSet([ArrayOffset(Variable('$d'), 'e')]),
        IsSet([Variable('$f'), Variable('$g')]),
        IsSet([ArrayOffset(ArrayOffset(MethodCall(Variable('$h'), 'm', []), 'i1'), 'i2')]),
    ]
    eq_ast(input, expected)

def test_namespace_names():
    input = r"""<?php
        foo;
        bar\baz;
        one\too\tree;
        \top;
        \top\level;
        namespace\level;
    ?>"""
    expected = [
        Constant(r'foo'),
        Constant(r'bar\baz'),
        Constant(r'one\too\tree'),
        Constant(r'\top'),
        Constant(r'\top\level'),
        Constant(r'namespace\level'),
    ]
    eq_ast(input, expected)

def test_unary_ops():
    input = r"""<?
        $a = -5;
        $b = +6;
        $c = !$d;
        $e = ~$f;
    ?>"""
    expected = [
        Assignment(Variable('$a'), UnaryOp('-', 5), False),
        Assignment(Variable('$b'), UnaryOp('+', 6), False),
        Assignment(Variable('$c'), UnaryOp('!', Variable('$d')), False),
        Assignment(Variable('$e'), UnaryOp('~', Variable('$f')), False),
    ]
    eq_ast(input, expected)

def test_assignment_ops():
    input = r"""<?
        $a += 5;
        $b -= 6;
        $c .= $d;
        $e ^= $f;
    ?>"""
    expected = [
        AssignOp('+=', Variable('$a'), 5),
        AssignOp('-=', Variable('$b'), 6),
        AssignOp('.=', Variable('$c'), Variable('$d')),
        AssignOp('^=', Variable('$e'), Variable('$f')),
    ]
    eq_ast(input, expected)

def test_object_properties():
    input = r"""<?
        $object->property;
        $object->foreach;
        $object->$variable;
        $object->$variable->schmariable;
        $object->$variable->$schmariable;
    ?>"""
    expected = [
        ObjectProperty(Variable('$object'), 'property'),
        ObjectProperty(Variable('$object'), 'foreach'),
        ObjectProperty(Variable('$object'), Variable('$variable')),
        ObjectProperty(ObjectProperty(Variable('$object'), Variable('$variable')),
                       'schmariable'),
        ObjectProperty(ObjectProperty(Variable('$object'), Variable('$variable')),
                       Variable('$schmariable')),
    ]
    eq_ast(input, expected)

def test_string_unescape():
    input = r"""<?
        '\r\n\t\\\'';
        "\r\n\t\\\"";
    ?>"""
    # TODO: "\x97\x[0-9]";
    expected = [
        r"\r\n\t\'",
        "\r\n\t\\\"",
    ]
    eq_ast(input, expected)

def test_string_offset_lookups():
    input = r"""<?
        "$array[offset]";
        "$array[42]";
        "$array[$variable]";
        "${curly['offset']}";
        "$too[many][offsets]";
        "$next[to]$array";
        "$object->property";
        "$too->many->properties";
        "$adjacent->object$lookup";
        "$two->$variables";
        "stray -> [ ]";
        "not[array]";
        "non->object";
    ?>"""
    expected = [
        ArrayOffset(Variable('$array'), 'offset'),
        ArrayOffset(Variable('$array'), 42),
        ArrayOffset(Variable('$array'), Variable('$variable')),
        ArrayOffset(Variable('$curly'), 'offset'),
        BinaryOp('.', ArrayOffset(Variable('$too'), 'many'), '[offsets]'),
        BinaryOp('.', ArrayOffset(Variable('$next'), 'to'), Variable('$array')),
        ObjectProperty(Variable('$object'), 'property'),
        BinaryOp('.', ObjectProperty(Variable('$too'), 'many'), '->properties'),
        BinaryOp('.', ObjectProperty(Variable('$adjacent'), 'object'), Variable('$lookup')),
        BinaryOp('.', BinaryOp('.', Variable('$two'), '->'), Variable('$variables')),
        'stray -> [ ]',
        'not[array]',
        'non->object',
    ]
    eq_ast(input, expected)

def test_string_curly_dollar_expressions():
    input = r"""<?
        "a${dollar_curly}b";
        "c{$curly_dollar}d";
        "e${$dollar_curly_dollar}f";
        "{$array[0][1]}";
        "{$array['two'][3]}";
        "{$object->items[4]->five}";
        "{${$nasty}}";
        "{${funcall()}}";
        "{${$object->method()}}";
        "{$object->$variable}";
        "{$object->$variable[1]}";
        "{${static_class::constant}}";
        "{${static_class::$variable}}";
    ?>"""
    expected = [
        BinaryOp('.', BinaryOp('.', 'a', Variable('$dollar_curly')), 'b'),
        BinaryOp('.', BinaryOp('.', 'c', Variable('$curly_dollar')), 'd'),
        BinaryOp('.', BinaryOp('.', 'e', Variable('$dollar_curly_dollar')), 'f'),
        ArrayOffset(ArrayOffset(Variable('$array'), 0), 1),
        ArrayOffset(ArrayOffset(Variable('$array'), 'two'), 3),
        ObjectProperty(ArrayOffset(ObjectProperty(Variable('$object'), 'items'), 4), 'five'),
        Variable(Variable('$nasty')),
        Variable(FunctionCall('funcall', [])),
        Variable(MethodCall(Variable('$object'), 'method', [])),
        ObjectProperty(Variable('$object'), Variable('$variable')),
        ObjectProperty(Variable('$object'), ArrayOffset(Variable('$variable'), 1)),
        Variable(StaticProperty('static_class', 'constant')),
        Variable(StaticProperty('static_class', Variable('$variable'))),
    ]
    eq_ast(input, expected)

def test_heredoc():
    input = r"""<?
        echo <<<EOT
This is a "$heredoc" with some $embedded->variables.
This is not the EOT; this is:
EOT;
    ?>"""
    expected = [
        Echo([BinaryOp('.',
                       BinaryOp('.',
                                BinaryOp('.',
                                         BinaryOp('.',
                                                  'This is a "',
                                                  Variable('$heredoc')),
                                         '" with some '),
                                ObjectProperty(Variable('$embedded'),
                                               'variables')),
                       '.\nThis is not the EOT; this is:')]),
    ]
    eq_ast(input, expected)
    if sys.version_info[0] < 3:
        eq_ast(input.decode('utf-8'), expected)

def test_heredoc_no_var():
    input = r"""<?
        echo <<<EOT
This is a long
heredoc without
any variable.
EOT;
    ?>"""
    expected = [
        Echo(['This is a long\nheredoc without\nany variable.'])
    ]
    eq_ast(input, expected)
    if sys.version_info[0] < 3:
        eq_ast(input.decode('utf-8'), expected)

def test_function_calls():
    input = r"""<?
        f();
        doit($arg1, &$arg2, 3 + 4);
        name\spaced();
        \name\spaced();
        namespace\d();
    ?>"""
    expected = [
        FunctionCall('f', []),
        FunctionCall('doit',
                     [Parameter(Variable('$arg1'), False),
                      Parameter(Variable('$arg2'), True),
                      Parameter(BinaryOp('+', 3, 4), False)]),
        FunctionCall('name\\spaced', []),
        FunctionCall('\\name\\spaced', []),
        FunctionCall('namespace\\d', []),
    ]
    eq_ast(input, expected)                   

def test_method_calls():
    input = r"""<?
        $obj->meth($a, &$b, $c . $d);
        $chain->one($x)->two(&$y);
    ?>"""
    expected = [
        MethodCall(Variable('$obj'), 'meth',
                   [Parameter(Variable('$a'), False),
                    Parameter(Variable('$b'), True),
                    Parameter(BinaryOp('.', Variable('$c'), Variable('$d')), False)]),
        MethodCall(MethodCall(Variable('$chain'),
                              'one', [Parameter(Variable('$x'), False)]),
                   'two', [Parameter(Variable('$y'), True)]),
    ]
    eq_ast(input, expected)

def test_if():
    input = r"""<?
        if (1)
            if (2)
                echo 3;
            else
                echo 4;
        else
            echo 5;
        if ($a < $b) {
            return -1;
        } elseif ($a > $b) {
            return 1;
        } elseif ($a == $b) {
            return 0;
        } else {
            return 'firetruck';
        }
        if ($if):
            echo 'a';
        elseif ($elseif):
            echo 'b';
        else:
            echo 'c';
        endif;
    ?>"""
    expected = [
        If(1,
           If(2,
              Echo([3]),
              [],
              Else(Echo([4]))),
           [],
           Else(Echo([5]))),
        If(BinaryOp('<', Variable('$a'), Variable('$b')),
           Block([Return(UnaryOp('-', 1))]),
           [ElseIf(BinaryOp('>', Variable('$a'), Variable('$b')),
                   Block([Return(1)])),
            ElseIf(BinaryOp('==', Variable('$a'), Variable('$b')),
                   Block([Return(0)]))],
           Else(Block([Return('firetruck')]))),
        If(Variable('$if'),
           Block([Echo(['a'])]),
           [ElseIf(Variable('$elseif'),
                   Block([Echo(['b'])]))],
           Else(Block([Echo(['c'])]))),
    ]
    eq_ast(input, expected)

def test_foreach():
    input = r"""<?
        foreach ($foo as $bar) {
            echo $bar;
        }
        foreach ($spam as $ham => $eggs) {
            echo "$ham: $eggs";
        }
        foreach (complex($expression) as &$ref)
            $ref++;
        foreach ($what as $de => &$dealy):
            yo();
            yo();
        endforeach;
        foreach ($foo as $bar[0]) {}
    ?>"""
    expected = [
        Foreach(Variable('$foo'), None, ForeachVariable(Variable('$bar'), False),
                Block([Echo([Variable('$bar')])])),
        Foreach(Variable('$spam'),
                Variable('$ham'),
                ForeachVariable(Variable('$eggs'), False),
                Block([Echo([BinaryOp('.',
                                      BinaryOp('.', Variable('$ham'), ': '),
                                      Variable('$eggs'))])])),
        Foreach(FunctionCall('complex', [Parameter(Variable('$expression'),
                                                   False)]),
                None, ForeachVariable(Variable('$ref'), True),
                PostIncDecOp('++', Variable('$ref'))),
        Foreach(Variable('$what'),
                Variable('$de'),
                ForeachVariable(Variable('$dealy'), True),
                Block([FunctionCall('yo', []),
                       FunctionCall('yo', [])])),
        Foreach(Variable('$foo'),
                None,
                ForeachVariable(ArrayOffset(Variable('$bar'), 0), False),
                Block([])),
    ]
    eq_ast(input, expected)

def test_foreach_with_lists():
    input = r"""<?
        foreach ($foo as list($bar, $baz)) {}
        foreach ($foo as $k => list($bar, $baz)) {}
    ?>"""
    expected = [
        Foreach(Variable('$foo'), None, ForeachVariable([Variable('$bar'), Variable('$baz')], False), Block([])),
        Foreach(Variable('$foo'), Variable('$k'), ForeachVariable([Variable('$bar'), Variable('$baz')], False), Block([])),
    ]
    eq_ast(input, expected)

def test_global_variables():
    input = r"""<?
        global $foo, $bar;
        global $$yo;
        global ${$dawg};
        global ${$obj->prop};
    ?>"""
    expected = [
        Global([Variable('$foo'), Variable('$bar')]),
        Global([Variable(Variable('$yo'))]),
        Global([Variable(Variable('$dawg'))]),
        Global([Variable(ObjectProperty(Variable('$obj'), 'prop'))]),
    ]
    eq_ast(input, expected)

def test_variable_variables():
    input = r"""<?
        $$a = $$b;
        $$a =& $$b;
        ${$a} = ${$b};
        ${$a} =& ${$b};
        $$a->b;
        $$$triple;
    ?>"""
    expected = [
        Assignment(Variable(Variable('$a')), Variable(Variable('$b')), False),
        Assignment(Variable(Variable('$a')), Variable(Variable('$b')), True),
        Assignment(Variable(Variable('$a')), Variable(Variable('$b')), False),
        Assignment(Variable(Variable('$a')), Variable(Variable('$b')), True),
        ObjectProperty(Variable(Variable('$a')), 'b'),
        Variable(Variable(Variable('$triple'))),
    ]
    eq_ast(input, expected)

def test_classes():
    input = r"""<?
        FINAL class Clown extends Unicycle implements RedNose, FacePaint {
            const the = 'only', constant = 'is';
            const change = 'chump';
            var $iable = 999, $nein;
            protected sTaTiC $x;
            public function conjunction_junction($arg1, $arg2) {
                return $arg1 . $arg2;
            }
        }
        class Stub {}
    ?>"""
    expected = [
        Class('Clown', 'final', 'Unicycle', ['RedNose', 'FacePaint'], [], [
            ClassConstants([ClassConstant('the', 'only'),
                            ClassConstant('constant', 'is')]),
            ClassConstants([ClassConstant('change', 'chump')]),
            ClassVariables([], [ClassVariable('$iable', 999),
                                ClassVariable('$nein', None)]),
            ClassVariables(['protected', 'static'],
                           [ClassVariable('$x', None)]),
            Method('conjunction_junction',
                   ['public'], 
                   [FormalParameter('$arg1', None, False, None),
                    FormalParameter('$arg2', None, False, None)],
                   [Return(BinaryOp('.', Variable('$arg1'), Variable('$arg2')))],
                   False),
        ]),
        Class('Stub', None, None, [], [], []),
    ]
    eq_ast(input, expected)

def test_new():
    input = r"""<?
        new Foo;
        new Foo();
        new Bar(1, 2, 3);
        $crusty =& new OldSyntax();
        new name\Spaced();
        new \name\Spaced();
        new namespace\D();
    ?>"""
    expected = [
        New('Foo', []),
        New('Foo', []),
        New('Bar', [Parameter(1, False),
                    Parameter(2, False),
                    Parameter(3, False)]),
        Assignment(Variable('$crusty'), New('OldSyntax', []), True),
        New('name\\Spaced', []),
        New('\\name\\Spaced', []),
        New('namespace\\D', []),
    ]
    eq_ast(input, expected)

def test_exceptions():
    input = r"""<?
        try {
            $a = $b + $c;
            throw new Food($a);
        } catch (Food $f) {
            echo "Received food: $f";
        } catch (\Bar\Food $f) {
            echo "Received bar food: $f";
        } catch (namespace\Food $f) {
            echo "Received namespace food: $f";
        } catch (Exception $e) {
            echo "Problem?";
        }
    ?>"""
    expected = [
        Try([
            Assignment(Variable('$a'),
                       BinaryOp('+', Variable('$b'), Variable('$c')),
                       False),
            Throw(New('Food', [Parameter(Variable('$a'), False)])),
        ], [
            Catch('Food', Variable('$f'), [
                Echo([BinaryOp('.', 'Received food: ', Variable('$f'))])
            ]),
            Catch('\\Bar\\Food', Variable('$f'), [
                Echo([BinaryOp('.', 'Received bar food: ', Variable('$f'))])
            ]),
            Catch('namespace\\Food', Variable('$f'), [
                Echo([BinaryOp('.', 'Received namespace food: ', Variable('$f'))])
            ]),
            Catch('Exception', Variable('$e'), [
                Echo(['Problem?']),
            ]),
        ],
        None)
    ]
    eq_ast(input, expected)

def test_catch_finally():
    input = r"""<?
        try {
            1;
        } catch (Exception $e) {
            2;
        } finally {
            3;
        }
    ?>"""
    expected = [
        Try([
            1
        ], [
            Catch('Exception', Variable('$e'), [
                2
            ]),
        ],
        Finally([3]))
    ]
    eq_ast(input, expected)

def test_just_finally():
    input = r"""<?
        try {
        } finally {
            1;
        }
    ?>"""
    expected = [
        Try([
        ], [],
        Finally([1]))
    ]
    eq_ast(input, expected)

def test_declare():
    input = r"""<?
        declare(ticks=1) {
            echo 'hi';
        }
        declare(ticks=2);
        declare(ticks=3):
        echo 'bye';
        enddeclare;
    ?>"""
    expected = [
        Declare([Directive('ticks', 1)], Block([
            Echo(['hi']),
        ])),
        Declare([Directive('ticks', 2)], None),
        Declare([Directive('ticks', 3)], Block([
            Echo(['bye']),
        ])),
    ]
    eq_ast(input, expected)

def test_instanceof():
    input = r"""<?
        if ($foo iNsTaNcEoF Bar) {
            echo '$foo is a bar';
        }
        $foo instanceof $bar;
        $foo instanceof static;
    ?>"""
    expected = [
        If(BinaryOp('instanceof', Variable('$foo'), Constant('Bar')),
           Block([Echo(['$foo is a bar'])]), [], None),
        BinaryOp('instanceof', Variable('$foo'), Variable('$bar')),
        BinaryOp('instanceof', Variable('$foo'), 'static'),
    ]
    eq_ast(input, expected)

def test_static_members():
    input = r"""<?
        Ztatic::constant;
        Ztatic::$variable;
        Ztatic::method();
        Ztatic::$variable_method();
        static::late_binding;
        STATIC::$late_binding;
        Static::late_binding();
    ?>"""
    expected = [
        StaticProperty('Ztatic', 'constant'),
        StaticProperty('Ztatic', Variable('$variable')),
        StaticMethodCall('Ztatic', 'method', []),
        StaticMethodCall('Ztatic', Variable('$variable_method'), []),
        StaticProperty('static', 'late_binding'),
        StaticProperty('static', Variable('$late_binding')),
        StaticMethodCall('static', 'late_binding', []),
    ]
    eq_ast(input, expected)

def test_casts():
    input = r"""<?
        (aRray) $x;
        (bOol) $x;
        (bOolean) $x;
        (rEal) $x;
        (dOuble) $x;
        (fLoat) $x;
        (iNt) $x;
        (iNteger) $x;
        (sTring) $x;
        (uNset) $x;
        (bInary) $x;
    ?>"""
    expected = [
        Cast('array', Variable('$x')),
        Cast('bool', Variable('$x')),
        Cast('bool', Variable('$x')),
        Cast('double', Variable('$x')),
        Cast('double', Variable('$x')),
        Cast('double', Variable('$x')),
        Cast('int', Variable('$x')),
        Cast('int', Variable('$x')),
        Cast('string', Variable('$x')),
        Cast('unset', Variable('$x')),
        Cast('binary', Variable('$x')),
    ]
    eq_ast(input, expected)

def test_namespaces():
    input = r"""<?
        namespace my\name;
        namespace my\name {
            foo();
            bar();
        }
        namespace {
            foo();
            bar();
        }
    ?>"""
    expected = [
        Namespace('my\\name', []),
        Namespace('my\\name', [FunctionCall('foo', []),
                               FunctionCall('bar', [])]),
        Namespace(None, [FunctionCall('foo', []),
                         FunctionCall('bar', [])]),
    ]
    eq_ast(input, expected)

def test_use_declarations():
    input = r"""<?
        use me;
        use \me;
        use \me\please;
        use my\name as foo;
        use a, b;
        use a as b, \c\d\e as f;
    ?>"""
    expected = [
        UseDeclarations([UseDeclaration('me', None)]),
        UseDeclarations([UseDeclaration('\\me', None)]),
        UseDeclarations([UseDeclaration('\\me\\please', None)]),
        UseDeclarations([UseDeclaration('my\\name', 'foo')]),
        UseDeclarations([UseDeclaration('a', None),
                         UseDeclaration('b', None)]),
        UseDeclarations([UseDeclaration('a', 'b'),
                         UseDeclaration('\\c\\d\\e', 'f')]),
    ]
    eq_ast(input, expected)

def test_constant_declarations():
    input = r"""<?
        const foo = 42;
        const bar = 'baz', wat = \DOO;
        const ant = namespace\level;
        const dq1 = "";
        const dq2 = "nothing fancy";
    ?>"""
    expected = [
        ConstantDeclarations([ConstantDeclaration('foo', 42)]),
        ConstantDeclarations([ConstantDeclaration('bar', 'baz'),
                              ConstantDeclaration('wat', Constant('\\DOO'))]),
        ConstantDeclarations([ConstantDeclaration('ant', Constant('namespace\\level'))]),
        ConstantDeclarations([ConstantDeclaration('dq1', '')]),
        ConstantDeclarations([ConstantDeclaration('dq2', 'nothing fancy')]),
    ]
    eq_ast(input, expected)

def test_closures():
    input = r"""<?
        $greet = function($name) {
            printf("Hello %s\r\n", $name);
        };
        $greet('World');
        $cb = function&($a, &$b) use ($c, &$d) {};
    ?>"""
    expected = [
        Assignment(Variable('$greet'),
                   Closure([FormalParameter('$name', None, False, None)],
                           [],
                           [FunctionCall('printf',
                                         [Parameter('Hello %s\r\n', False),
                                          Parameter(Variable('$name'), False)])],
                           False),
                   False),
        FunctionCall(Variable('$greet'), [Parameter('World', False)]),
        Assignment(Variable('$cb'),
                   Closure([FormalParameter('$a', None, False, None),
                            FormalParameter('$b', None, True, None)],
                           [LexicalVariable('$c', False),
                            LexicalVariable('$d', True)],
                           [],
                           True),
                   False),
    ]
    eq_ast(input, expected)

def test_magic_constants():
    input = r"""<?
        namespace Shmamespace;

        function p($x) {
            echo __FUNCTION__ . ': ' . $x . "\n";
        }

        class Bar {
            function __construct() {
                p(__LINE__);
                p(__DIR__);
                p(__FILE__);
                p(__NAMESPACE__);
                p(__CLASS__);
                p(__METHOD__);
            }
        }

        new Bar();
    ?>"""
    expected = [
        Namespace('Shmamespace', []),
        Function('p', [FormalParameter('$x', None, False, None)], [
            Echo([BinaryOp('.', BinaryOp('.', BinaryOp('.',
                MagicConstant('__FUNCTION__', 'Shmamespace\\p'), ': '),
                Variable('$x')), '\n')])
        ], False),
        Class('Bar', None, None, [], [],
              [Method('__construct', [], [],
                      [FunctionCall('p', [Parameter(MagicConstant('__LINE__', 10), False)]),
                       FunctionCall('p', [Parameter(MagicConstant('__DIR__', '/my/dir'), False)]),
                       FunctionCall('p', [Parameter(MagicConstant('__FILE__', '/my/dir/file.php'), False)]),
                       FunctionCall('p', [Parameter(MagicConstant('__NAMESPACE__', 'Shmamespace'), False)]),
                       FunctionCall('p', [Parameter(MagicConstant('__CLASS__', 'Shmamespace\\Bar'), False)]),
                       FunctionCall('p', [Parameter(MagicConstant('__METHOD__', 'Shmamespace\\Bar::__construct'), False)])], False)]),
        New('Bar', []),
    ]
    eq_ast(input, expected, filename='/my/dir/file.php')

def test_type_hinting():
    input = r"""<?
    function foo(Foo $var1, Bar $var2=1, Quux &$var3, Corge &$var4=1, array &$var5=array()) {
    }
    ?>""";
    expected = [
        Function('foo', 
            [FormalParameter('$var1', None, False, 'Foo'),
             FormalParameter('$var2', 1, False, 'Bar'),
             FormalParameter('$var3', None, True, 'Quux'),
             FormalParameter('$var4', 1, True, 'Corge'),
             FormalParameter('$var5', Array([]), True, 'array')],
            [],
            False)]
    eq_ast(input, expected)

def test_static_scalar_class_constants():
    input = r"""<?
    class A { public $b = self::C; function d($var1=self::C) {} }
    ?>"""
    expected = [
        Class('A', None, None, [], [],
            [ClassVariables(['public'], [ClassVariable('$b', StaticProperty('self', 'C'))]),
             Method('d', [], [FormalParameter('$var1', StaticProperty('self', 'C'), False, None)], [], False)
            ])]
    eq_ast(input, expected)

def test_backtick_shell_exec():
    input = '<? `$cmd` . `date`; `echo $line`; ?>'
    expected = [
        BinaryOp('.',
            FunctionCall('shell_exec', [Parameter(Variable('$cmd'), False)]),
            FunctionCall('shell_exec', [Parameter('date', False)])
        ),
        FunctionCall('shell_exec', [Parameter(BinaryOp('.', 'echo ', Variable('$line')), False)])
    ]
    eq_ast(input, expected)

def test_open_close_tags_ignore():
    # The filtered lexer should correctly interpret ?><?
    input = '<? if (1): if (2) 3; ?><? else: 0; endif;'
    expected = [
        If(1, Block([If(2, 3, [], None), None]), [], Else(Block([0])))
    ]
    eq_ast(input, expected)

def test_ternary():
    input = '<? 1 ? 2 : 3; 4 ? : 5;'
    expected = [
        TernaryOp(1, 2, 3),
        TernaryOp(4, 4, 5),
    ]
    eq_ast(input, expected)

def test_array_dereferencing():
    input = '<? $a->method()[0]; func()[1];'
    expected = [
        ArrayOffset(MethodCall(Variable('$a'), 'method', []), 0),
        ArrayOffset(FunctionCall('func', []), 1)
    ]
    eq_ast(input, expected)

def test_array_literal():
    input = '<? [1,2]; [];'
    expected = [
        Array([ArrayElement(None, 1, False), ArrayElement(None, 2, False)]),
        Array([]),
    ]
    eq_ast(input, expected)

def test_array_in_default_arg():
    input = '<? function f($a=[]){} function g($a=array()){}'
    expected = [
        Function('f', [FormalParameter('$a', Array([]), False, None)], [], False),
        Function('g', [FormalParameter('$a', Array([]), False, None)], [], False),
    ]
    eq_ast(input, expected)

def test_const_heredoc():
    input = '''<?
    const X = <<<HERE
text
HERE;'''
    expected = [
        ConstantDeclarations([ConstantDeclaration('X', 'text')])
    ]
    eq_ast(input, expected)

def test_object_property_on_expr():
    input = '''<? ($a->m1())->m2(); ($a->m1())->m2;'''
    expected = [
        MethodCall(MethodCall(Variable('$a'), 'm1', []), 'm2', []),
        ObjectProperty(MethodCall(Variable('$a'), 'm1', []), 'm2'),
    ]
    eq_ast(input, expected)

def test_binary_string():
    input = '''<? b"abc"; b'abc';'''
    expected = [
        "abc",
        "abc",
    ]
    eq_ast(input, expected)

def test_class_trait_use():
    input = '''<? class A { use B; }'''
    expected = [
        Class('A', None, None, [], [TraitUse('B', [])], []),
    ]
    eq_ast(input, expected)

def test_trait():
    input = '''<? trait A { } trait B { use A; } trait C { function f(){} }
                  trait D { protected $v; }'''
    expected = [
        Trait('A', [], []),
        Trait('B', [TraitUse('A', [])], []),
        Trait('C', [], [Method('f', [], [], [], False)]),
        Trait('D', [], [ClassVariables(['protected'], [ClassVariable('$v', None)])]),
    ]
    eq_ast(input, expected)

def test_trait_renames():
    input = '''<? trait A { use T {X as Y;} }
                  class B { use T {X as Y;} }
                  trait C { use T {X as public Y;} }
                  trait D { use T {X as public;} }
                  trait E { use T {X::m as Y;} }'''
    expected = [
        Trait('A', [TraitUse('T', [TraitModifier('X', 'Y', None)])], []),
        Class('B', None, None, [], [TraitUse('T', [TraitModifier('X', 'Y', None)])], []),
        Trait('C', [TraitUse('T', [TraitModifier('X', 'Y', 'public')])], []),
        Trait('D', [TraitUse('T', [TraitModifier('X', None, 'public')])], []),
        Trait('E', [TraitUse('T', [TraitModifier(StaticProperty('X', 'm'), 'Y', None)])], []),
    ]
    eq_ast(input, expected)

def test_class_name_as_string():
    input = '''<? A::class; const C = A::class;'''
    expected = [
        'A',
        ConstantDeclarations([ConstantDeclaration('C', 'A')]),
    ]
    eq_ast(input, expected)

def test_static_expressions():
    input = '''<? const C = 1+2; const C = 1+(2+3); const C = "a"."b";'''
    expected = [
        ConstantDeclarations([ConstantDeclaration('C', BinaryOp('+', 1, 2))]),
        ConstantDeclarations([ConstantDeclaration('C', BinaryOp('+', 1, BinaryOp('+', 2, 3)))]),
        ConstantDeclarations([ConstantDeclaration('C', BinaryOp('.', 'a', 'b'))]),
    ]
    eq_ast(input, expected)

def test_const_arrays():
    input = '''<? const C = array(1+2);'''
    expected = [
        ConstantDeclarations([ConstantDeclaration('C', Array([ArrayElement(None, BinaryOp('+', 1, 2), False)]))]),
    ]
    eq_ast(input, expected)

def test_numbers():
    input = '''<? 10; 010; 0x10; 0b10;'''
    expected = [
        10,
        0o10,
        0x10,
        2,
    ]
    eq_ast(input, expected)

def test_result_multiple_offsets():
    input = '''<? $o->m()[1][2]; $o->m(){1}{2}; '''
    expected = [
        ArrayOffset(ArrayOffset(MethodCall(Variable('$o'), 'm', []), 1), 2),
        StringOffset(StringOffset(MethodCall(Variable('$o'), 'm', []), 1), 2),
    ]
    eq_ast(input, expected)

def test_yield():
    input = '''<? function f() { yield; yield 1; }'''
    expected = [
        Function('f', [], [
            Yield(None),
            Yield(1),
        ], False),
    ]
    eq_ast(input, expected)

def test_static_property_dynamic_access():
    input = '''<? $o::{$prop};'''
    expected = [
        StaticProperty(Variable('$o'), Variable('$prop')),
    ]
    eq_ast(input, expected)

def test_static_property_dynamic_call():
    input = '''<? $o::{$prop}();'''
    expected = [
        StaticMethodCall(Variable('$o'), Variable('$prop'), []),
    ]
    eq_ast(input, expected)

def test_nowdoc():
    input = r"""<?
        echo <<<'EOT'
disregard $all {$crazy} ${stuff}->f();
and `this`
EOT;
    ?>"""
    expected = [
        Echo(['disregard $all {$crazy} ${stuff}->f();\nand `this`'])
    ]
    eq_ast(input, expected)

def test_exit_loc():
    input = '''<?
               exit(1); '''
    expected = [
        Exit(1, 'exit', lineno=2)
    ]
    eq_ast(input, expected, with_top_lineno=True)

# PHP 8.0 tests

def test_nullsafe_property():
    input = '''<? $x?->a; '''
    expected = [
        NullsafeProperty(Variable('$x'), 'a'),
    ]
    eq_ast(input, expected)

def test_nullsafe_method_call():
    input = '''<? $x?->a(); '''
    expected = [
        NullsafeMethodCall(Variable('$x'), 'a', []),
    ]
    eq_ast(input, expected)

def test_nullsafe_chain():
    input = '''<? $x?->a()->b; '''
    expected = [
        ObjectProperty(NullsafeMethodCall(Variable('$x'), 'a', []), 'b'),
    ]
    eq_ast(input, expected)

def test_named_arguments():
    input = '''<? array_map(callback: $fn, array: $arr); '''
    expected = [
        FunctionCall('array_map', [
            NamedParameter('callback', Variable('$fn'), False),
            NamedParameter('array', Variable('$arr'), False),
        ]),
    ]
    eq_ast(input, expected)

def test_match_expression():
    input = '''<? match($x) { 1 => 'a', 2, 3 => 'b', default => 'c' }; '''
    expected = [
        Match(Variable('$x'), [
            MatchArm([1], 'a'),
            MatchArm([2, 3], 'b'),
            MatchDefaultArm('c'),
        ]),
    ]
    eq_ast(input, expected)

def test_union_types():
    input = '''<? function f(int|string $x): int|bool {} '''
    expected = [
        Function('f', [FormalParameter('$x', None, False, 'int|string')], [],
                 False, return_type='int|bool'),
    ]
    eq_ast(input, expected)

def test_nullable_union_type():
    input = '''<? function f(?int|string $x): ?A|B {} '''
    expected = [
        Function('f', [FormalParameter('$x', None, False, '?int|string')], [],
                 False, return_type='?A|B'),
    ]
    eq_ast(input, expected)

def test_constructor_promotion():
    input = '''<? class A { public function __construct(public int $x, private string $y = 'a') {} } '''
    expected = [
        Class('A', None, None, [], [],
              [Method('__construct', ['public'],
                      [FormalParameter('$x', None, False, 'int'),
                       FormalParameter('$y', 'a', False, 'string')],
                      [], False)]),
    ]
    eq_ast(input, expected)

def test_static_return_type():
    input = '''<? function f(): static {} '''
    expected = [
        Function('f', [], [], False, return_type='static'),
    ]
    eq_ast(input, expected)

def test_mixed_type():
    input = '''<? function f(): mixed {} '''
    expected = [
        Function('f', [], [], False, return_type='mixed'),
    ]
    eq_ast(input, expected)

def test_throw_expression():
    input = '''<? $x ?? throw new Exception(); '''
    expected = [
        BinaryOp('??', Variable('$x'),
                 Throw(New('Exception', []), lineno=1), lineno=1),
    ]
    eq_ast(input, expected)

def test_non_capturing_catch():
    input = '''<? try {} catch (Exception) {} '''
    expected = [
        Try([], [
            Catch('Exception', None, []),
        ], None),
    ]
    eq_ast(input, expected)

def test_trailing_comma_params():
    input = '''<? function f($a, $b,) {} '''
    expected = [
        Function('f', [FormalParameter('$a', None, False, None),
                       FormalParameter('$b', None, False, None)], [], False),
    ]
    eq_ast(input, expected)

# PHP 8.1 tests

def test_enum_basic():
    input = '''<? enum Status: string { case Active = 'active'; case Inactive = 'inactive'; } '''
    expected = [
        Enum('Status', 'string', [], [
            EnumCase('Active', 'active'),
            EnumCase('Inactive', 'inactive'),
        ]),
    ]
    eq_ast(input, expected)

def test_enum_without_backing():
    input = '''<? enum Color { case Red; case Blue; } '''
    expected = [
        Enum('Color', None, [], [
            EnumCase('Red', None),
            EnumCase('Blue', None),
        ]),
    ]
    eq_ast(input, expected)

def test_readonly_property():
    input = '''<? class A { public readonly int $x; } '''
    expected = [
        Class('A', None, None, [], [],
              [ClassVariables(['public', 'readonly'],
                              [ClassVariable('$x', None)],
                              property_type='int')]),
    ]
    eq_ast(input, expected)

def test_never_return_type():
    input = '''<? function f(): never { die(); } '''
    expected = [
        Function('f', [], [Exit(None, 'die')], False, return_type='never'),
    ]
    eq_ast(input, expected)

def test_first_class_callable():
    input = '''<? $f = strlen(...); '''
    expected = [
        Assignment(Variable('$f'), FirstClassCallable('strlen'), False),
    ]
    eq_ast(input, expected)

def test_intersection_types():
    input = '''<? function f(Countable&Iterator $x) {} '''
    expected = [
        Function('f', [FormalParameter('$x', None, False, 'Countable&Iterator')], [],
                 False),
    ]
    eq_ast(input, expected)

def test_final_class_constant():
    input = '''<? class A { final const X = 1; } '''
    expected = [
        Class('A', None, None, [], [],
              [ClassConstants([ClassConstant('X', 1)])]),
    ]
    eq_ast(input, expected)

# PHP 8.2 tests

def test_readonly_class():
    input = '''<? readonly class Foo { public int $x = 1; } '''
    expected = [
        Class('Foo', 'readonly', None, [], [],
              [ClassVariables(['public'], [ClassVariable('$x', 1)], property_type='int')]),
    ]
    eq_ast(input, expected)

def test_trait_constants():
    input = '''<? trait Foo { public const BAR = 'baz'; } '''
    expected = [
        Trait('Foo', [], [ClassConstants([ClassConstant('BAR', 'baz')])]),
    ]
    eq_ast(input, expected)

def test_true_return_type():
    input = '''<? function foo(): true {} '''
    expected = [
        Function('foo', [], [], False, return_type='true'),
    ]
    eq_ast(input, expected)

def test_true_param_type():
    input = '''<? function bar(true $val): void {} '''
    expected = [
        Function('bar', [FormalParameter('$val', None, False, 'true')], [],
                 False, return_type='void'),
    ]
    eq_ast(input, expected)

# PHP 8.3 tests

def test_typed_class_constant():
    input = '''<? class Foo { public const int MAX_SIZE = 100; } '''
    expected = [
        Class('Foo', None, None, [], [],
              [ClassConstants([ClassConstant('MAX_SIZE', 100, const_type='int')])]),
    ]
    eq_ast(input, expected)

def test_typed_class_constant_no_modifier():
    input = '''<? class Foo { const string NAME = 'test'; } '''
    expected = [
        Class('Foo', None, None, [], [],
              [ClassConstants([ClassConstant('NAME', 'test', const_type='string')])]),
    ]
    eq_ast(input, expected)

def test_dynamic_class_constant_fetch():
    input = '''<? echo Foo::{$var}; '''
    expected = [
        Echo([StaticProperty('Foo', Variable('$var'))]),
    ]
    eq_ast(input, expected)

def test_dynamic_class_constant_fetch_expr():
    input = '''<? echo $obj::{$arr['key']}; '''
    expected = [
        Echo([StaticProperty(Variable('$obj'), ArrayOffset(Variable('$arr'), 'key'))]),
    ]
    eq_ast(input, expected)

def test_dnf_type_simple():
    """PHP 8.2: DNF type (A&B)|C"""
    input = '''<? function foo((A&B)|C $param): (A&B)|C {} '''
    expected = [
        Function('foo',
                 [FormalParameter('$param', None, False, '(A&B)|C')],
                 [],
                 False, return_type='(A&B)|C'),
    ]
    eq_ast(input, expected)

def test_dnf_type_complex():
    """PHP 8.2: DNF type (A&B)|(C&D)"""
    input = '''<? function bar((A&B)|(C&D) $p): (A&B)|(C&D)|null {} '''
    expected = [
        Function('bar',
                 [FormalParameter('$p', None, False, '(A&B)|(C&D)')],
                 [],
                 False, return_type='(A&B)|(C&D)|null'),
    ]
    eq_ast(input, expected)

def test_dnf_type_nullable():
    """PHP 8.2: Nullable DNF type ?(A&B)"""
    input = '''<? function baz(): ?(A&B) {} '''
    expected = [
        Function('baz', [], [],
                 False, return_type='?(A&B)'),
    ]
    eq_ast(input, expected)

# PHP 8.4 tests

def test_new_without_parens_static_method():
    """PHP 8.4: new Foo()::method()"""
    input = '''<? new Foo()::method(); '''
    expected = [
        StaticMethodCall(New('Foo', []), 'method', []),
    ]
    eq_ast(input, expected)

def test_new_without_parens_const():
    """PHP 8.4: new Foo()::BAR"""
    input = '''<? echo new Foo()::BAR; '''
    expected = [
        Echo([StaticProperty(New('Foo', []), 'BAR')]),
    ]
    eq_ast(input, expected)

def test_new_without_parens_dynamic():
    """PHP 8.4: new Foo()::{$var}"""
    input = '''<? echo new Foo()::{$var}; '''
    expected = [
        Echo([StaticProperty(New('Foo', []), Variable('$var'))]),
    ]
    eq_ast(input, expected)

def test_new_without_parens_chain():
    """PHP 8.4: new Foo()::method()->bar()"""
    input = '''<? new Foo()::method()->bar(); '''
    expected = [
        MethodCall(StaticMethodCall(New('Foo', []), 'method', []), 'bar', []),
    ]
    eq_ast(input, expected)

def test_new_without_parens_with_args():
    """PHP 8.4: new Foo($x)::method()"""
    input = '''<? new Foo($x)::method(); '''
    expected = [
        StaticMethodCall(New('Foo', [Parameter(Variable('$x'), False)]), 'method', []),
    ]
    eq_ast(input, expected)

def test_property_hook_get_short():
    """PHP 8.4: Property hook with short get"""
    input = '''<? class Foo { public string $name { get => strtoupper($this->_name); } } '''
    expected = [
        Class('Foo', None, None, [], [],
              [ClassVariables(['public'], [ClassVariable('$name', None)],
                              property_type='string',
                              hooks=[PropertyHook('get', None,
                                                  FunctionCall('strtoupper', [Parameter(ObjectProperty(Variable('$this'), '_name'), False)]),
                                                  None, True)])]),
    ]
    eq_ast(input, expected)

def test_property_hook_get_set_short():
    """PHP 8.4: Property hooks with get and set (short form)"""
    input = '''<? class Foo { public int $x { get => $this->_x * 2; set => $this->_x = $value; } } '''
    expected = [
        Class('Foo', None, None, [], [],
              [ClassVariables(['public'], [ClassVariable('$x', None)],
                              property_type='int',
                              hooks=[PropertyHook('get', None,
                                                  BinaryOp('*', ObjectProperty(Variable('$this'), '_x'), 2),
                                                  None, True),
                                     PropertyHook('set', None,
                                                  Assignment(ObjectProperty(Variable('$this'), '_x'), Variable('$value'), False),
                                                  None, True)])]),
    ]
    eq_ast(input, expected)

def test_property_hook_get_body():
    """PHP 8.4: Property hook with body form get"""
    input = '''<? class Foo { public string $email { get { return $this->_email; } } } '''
    expected = [
        Class('Foo', None, None, [], [],
              [ClassVariables(['public'], [ClassVariable('$email', None)],
                              property_type='string',
                              hooks=[PropertyHook('get', None,
                                                  [Return(ObjectProperty(Variable('$this'), '_email'))],
                                                  None, False)])]),
    ]
    eq_ast(input, expected)

def test_property_hook_set_with_param():
    """PHP 8.4: Property hook with set having typed parameter"""
    input = '''<? class Foo { public string $name { set(string $value) { $this->_name = strtolower($value); } } } '''
    expected = [
        Class('Foo', None, None, [], [],
              [ClassVariables(['public'], [ClassVariable('$name', None)],
                              property_type='string',
                              hooks=[PropertyHook('set',
                                                  [FormalParameter('$value', None, False, 'string')],
                                                  [Assignment(ObjectProperty(Variable('$this'), '_name'),
                                                              FunctionCall('strtolower', [Parameter(Variable('$value'), False)]),
                                                              False)],
                                                  None, False)])]),
    ]
    eq_ast(input, expected)

# PHP 8.5 tests

def test_pipe_operator():
    """PHP 8.5: Pipe operator |>"""
    input = """<? $result = $x |> trim(...) |> strtolower(...);"""
    eq_ast(
        input,
        [Assignment(Variable('$result'),
                    Pipe(Pipe(Variable('$x'), FirstClassCallable('trim')),
                         FirstClassCallable('strtolower')), False)]
    )

def test_pipe_with_closure():
    """PHP 8.5: Pipe with arrow function"""
    input = """<? $x |> (fn($v) => $v * 2) |> (fn($v) => $v + 1);"""
    eq_ast(
        input,
        [Pipe(Pipe(Variable('$x'),
                   ArrowFunction([FormalParameter('$v', None, False, None)],
                                 BinaryOp('*', Variable('$v'), 2), None, False)),
              ArrowFunction([FormalParameter('$v', None, False, None)],
                            BinaryOp('+', Variable('$v'), 1), None, False))]
    )

def test_clone_with():
    """PHP 8.5: clone() with properties"""
    input = """<? $new = clone($obj, ['key' => $val]);"""
    eq_ast(
        input,
        [Assignment(Variable('$new'),
                    CloneWith(Variable('$obj'),
                              Array([ArrayElement('key', Variable('$val'), False)])),
                    False)]
    )

def test_clone_with_multiple():
    """PHP 8.5: clone() with multiple properties"""
    input = """<? $new = clone($this, ['alpha' => 128, 'red' => 255]);"""
    eq_ast(
        input,
        [Assignment(Variable('$new'),
                    CloneWith(Variable('$this'),
                              Array([ArrayElement('alpha', 128, False),
                                     ArrayElement('red', 255, False)])),
                    False)]
    )

def test_void_cast():
    """PHP 8.5: (void) cast"""
    input = """<? (void)$x;"""
    eq_ast(input, [VoidCast(Variable('$x'))])

def test_void_cast_function_call():
    """PHP 8.5: (void) cast on function call to discard result"""
    input = """<? (void)getPhpVersion();"""
    eq_ast(
        input,
        [VoidCast(FunctionCall('getPhpVersion', []))]
    )

# Issue fix tests

def test_issue_61_arrow_function_return_type():
    """Issue #61: Arrow function with return type"""
    eq_ast(
        '<?php $fn = fn(int $x): int => $x * 2;',
        [Assignment(Variable('$fn'),
         ArrowFunction([FormalParameter('$x', None, False, 'int')],
                       BinaryOp('*', Variable('$x'), 2), 'int', False),
         False)]
    )

def test_issue_61_arrow_function_nullable_return():
    """Issue #61: Arrow function with nullable return type"""
    eq_ast(
        '<?php $fn = fn(): ?int => null;',
        [Assignment(Variable('$fn'),
         ArrowFunction([], Constant('null'), '?int', False),
         False)]
    )

def test_issue_54_static_scalar_concat():
    """Issue #54: Concat in static scalar (class variable default)"""
    eq_ast(
        '<?php class Example { private static $var = \'test\' . \'ing\'; }',
        [Class('Example', None, None, [], [],
         [ClassVariables(['private', 'static'],
          [ClassVariable('$var', BinaryOp('.', 'test', 'ing'))],
          None, None)])]
    )

def test_issue_54_static_scalar_concat_multi():
    """Issue #54: Multiple concat in static scalar"""
    eq_ast(
        '<?php $x = \'a\' . \'b\' . \'c\';',
        [Assignment(Variable('$x'),
         BinaryOp('.', BinaryOp('.', 'a', 'b'), 'c'),
         False)]
    )

def test_issue_52_variadic_parameter():
    """Issue #52/PHP 5.6: Variadic parameter ...$args"""
    eq_ast(
        '<?php function foo(...$args) {}',
        [Function('foo', [FormalParameter('$args', None, False, None)],
         [], False, None)]
    )

def test_issue_52_typed_variadic_parameter():
    """Issue #52: Typed variadic parameter int ...$nums"""
    eq_ast(
        '<?php function bar(int ...$nums) {}',
        [Function('bar', [FormalParameter('$nums', None, False, 'int')],
         [], False, None)]
    )

def test_issue_21_invalid_octal():
    """Issue #21: Invalid octal notation (0987) should parse as 0"""
    eq_ast(
        '<?php 0987;',
        [0]
    )

def test_issue_21_valid_octal():
    """Issue #21: Valid octal notation"""
    eq_ast(
        '<?php 0777;',
        [511]
    )

def test_issue_21_hex_uppercase():
    """Issue #21: Hex with uppercase 0X prefix"""
    eq_ast(
        '<?php 0XFF;',
        [255]
    )

def test_issue_21_binary_uppercase():
    """Issue #21: Binary with uppercase 0B prefix"""
    eq_ast(
        '<?php 0B1010;',
        [10]
    )

def test_issue_7_end_lineno():
    """Issue #7: end_lineno support on AST nodes"""
    from phply import phpast
    node = phpast.Function('test', [], [], False, None, lineno=5, end_lineno=10)
    assert node.lineno == 5
    assert node.end_lineno == 10
    g = node.generic(with_lineno=True)
    assert g[1]['lineno'] == 5
    assert g[1]['end_lineno'] == 10
