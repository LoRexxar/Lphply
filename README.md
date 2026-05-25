# phply

Lexer and parser for PHP source code implemented in Python using [PLY](https://github.com/dabeaz/ply) (Python Lex-Yacc).

Supports PHP **5.6 through 8.5** syntax.

## Installation

```bash
pip install lphply
```

> **注意：** 由于 `lphply` 的内部包名仍为 `phply`，与上游 `viraptor/phply` 的 PyPI 包冲突。如果之前安装过 `phply`，请先卸载：`pip uninstall phply && pip install lphply`。

## Usage

### Lexer

```python
from phply.phplex import lexer

lexer.input('<?php echo "Hello, World!"; ?>')
for token in lexer:
    print(token)
```

### Parser

```python
from phply.phplex import lexer
from phply.phpparse import make_parser

parser = make_parser()
with open('example.php', 'r') as f:
    code = f.read()
result = parser.parse(code, lexer=lexer)
```

### Command-line tools

```bash
# Parse a PHP file
phpparse file.php

# Lex a PHP file
phplex file.php
```

## Development

Clone the repository and install in development mode:

```bash
git clone https://github.com/LoRexxar/Lphply.git
cd Lphply
pip install -e ".[test]"
```

### Running tests

```bash
pytest
```

## License

BSD-3-Clause. See [LICENSE](LICENSE) for details.

## Credits

Fork of [viraptor/phply](https://github.com/viraptor/phply), originally by Dave Benjamin.
