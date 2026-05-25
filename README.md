# phply

[![PyPI version](https://img.shields.io/pypi/v/lphply.svg)](https://pypi.org/project/lphply/)
[![PyPI - Python Version](https://img.shields.io/pypi/pyversions/lphply.svg)](https://pypi.org/project/lphply/)
[![GitHub release](https://img.shields.io/github/v/release/LoRexxar/Lphply.svg)](https://github.com/LoRexxar/Lphply/releases)
[![License](https://img.shields.io/github/license/LoRexxar/Lphply.svg)](https://github.com/LoRexxar/Lphply/blob/master/LICENSE)

> Enhanced fork of phply — fixes core AST bugs, supports PHP **5.6 through 8.5** syntax.

## Installation

```bash
pip install lphply
```

> **Note:** Since the internal package name of `lphply` is still `phply`, it conflicts with the upstream `viraptor/phply` PyPI package. If you have previously installed `phply`, please uninstall it first: `pip uninstall phply && pip install lphply`.

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
