# Changelog

All notable changes to this project will be documented in this file.

## [Unreleased] - PHP 8.2-8.5 语法支持

### PHP 8.5 (commit 5eebb56)

**新增特性：**

- **Pipe Operator (`|>`)**: 支持管道操作符，链式调用函数
  ```php
  $result = $x |> trim(...) |> strtolower(...);
  $result = $x |> (fn($v) => $v * 2) |> (fn($v) => $v + 1);
  ```
  - 新增 `PIPE` lexer token 和 `Pipe` AST 节点
  - 优先级介于比较运算符和移位运算符之间，左结合

- **Clone With**: 支持在克隆时修改属性
  ```php
  $new = clone($obj, ['key' => $val]);
  $transparentBlue = clone($color, ['alpha' => 128, 'red' => 255]);
  ```
  - 扩展 `p_expr_clone` 规则，新增 `CloneWith` AST 节点

- **Void Cast (`(void)`)**: 支持显式丢弃返回值
  ```php
  (void)$x;
  (void)getPhpVersion();
  ```
  - 新增 `VOID_CAST` lexer token 和 `VoidCast` AST 节点

**测试：** 105/105 通过（+6 新测试）

---

### PHP 8.4 (commit 50dd60c)

**新增特性：**

- **New Without Parentheses**: 支持无括号实例化后的链式调用
  ```php
  new Foo()::BAR;
  new Foo()::method();
  new Foo()::{$var}();
  new Foo()::method()->bar();
  ```
  - 通过 `variable_class_name : NEW class_name_reference ctor_arguments` 实现
  - 注意：`(new Foo())::BAR` 暂不支持（PLY 语法限制）

- **Property Hooks**: 支持属性钩子（get/set）
  ```php
  // 短箭头形式
  public string $name { get => $this->_name; }
  // 体形式
  public string $email { get { return $this->_email; } }
  // 带参数 set
  public string $name { set(string $value) { $this->_name = $value; } }
  // 可见性修饰
  public string $name { get => $this->_name; private set => $value; }
  ```
  - 新增 `PropertyHook` AST 节点，扩展 `ClassVariables` 添加 `hooks` 字段
  - `get`/`set` 不作为 lexer 保留字（上下文敏感关键字）

- **Variable Class Constant Fetch**: `$obj::{$name}` 已隐式支持（无需改动）

**测试：** 99/99 通过（+9 新测试）

---

### PHP 8.3 (commit 6513808)

**新增特性：**

- **Typed Class Constants**: 支持类常量类型声明
  ```php
  class Foo {
      const string NAME = 'foo';
      const int VERSION = 1;
      protected array CACHE = [];
  }
  ```

- **Dynamic Class Constant Fetch**: `Foo::{$var}` 已通过 `static_member` 规则隐式支持
  ```php
  $const = Foo::{$name};
  ```

---

### PHP 8.2 (commit 6513808)

**新增特性：**

- **Readonly Classes**: 支持只读类
  ```php
  readonly class Foo {
      public int $x = 1;
  }
  ```

- **Constants in Traits**: 支持 trait 中定义常量
  ```php
  trait MyTrait {
      public const FLAG = 1;
  }
  ```

- **`true` Type**: 支持 `true` 作为类型
  ```php
  function alwaysTrue(): true { return true; }
  ```

- **DNF Types (Disjunctive Normal Form)**: 支持交集类型的组合
  ```php
  function foo((A&B)|C $param): void {}
  function bar(?(A&B)|C $param): void {}
  ```
  - 保持扁平 `type_expr` 结构 + `LPAREN type_expr RPAREN` 规则，避免 80 个 reduce/reduce 冲突

---

### PHP 8.0-8.1 (commit 13a8b36)

**PHP 8.1 新增：**

- **Enums**: 枚举类型（含 backed enums 和枚举方法）
- **Readonly Properties**: 只读属性
- **Never Return Type**: `never` 返回类型
- **First-Class Callables**: `foo(...)` 语法
- **Intersection Types**: `A&B` 交集类型
- **Fibers**: `Fiber` 相关语法

**PHP 8.0 新增：**

- **Named Arguments**: `foo(name: $value)`
- **Match Expression**: `match($x) { ... }`
- **Nullsafe Operator**: `$obj?->method()`
- **Union Types**: `int|string` 联合类型
- **Attributes**: `#[Attribute]`
- **Constructor Property Promotion**: 构造函数属性提升
- **Throw Expression**: `throw` 作为表达式

---

### PHP 7.0-7.4 (commit c015d4f)

- **PHP 7.0**: 返回类型声明、匿名类、太空船操作符 `<=>`、null 合并操作符 `??`
- **PHP 7.1**: 可空类型 `?int`、void 返回类型、iterable 伪类型、多 catch 异常
- **PHP 7.2**: object 类型提示
- **PHP 7.3**: 列表引用赋值、灵活的 Heredoc/Nowdoc
- **PHP 7.4**: 类型化属性、箭头函数 `fn() =>`、null 合并赋值 `??=`、数组展开运算符
