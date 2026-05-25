import os
import sys
from setuptools import setup
from setuptools.command.build_py import build_py


class BuildPyCommand(build_py):
    """Generate parsetab.py during build by importing phpparse directly."""

    def run(self):
        # Add the source tree to sys.path so we can import phply.phpparse
        # even in an isolated build environment
        src_dir = os.path.abspath(os.path.dirname(__file__))
        if src_dir not in sys.path:
            sys.path.insert(0, src_dir)
        from phply.phpparse import make_parser
        make_parser(debug=False)
        super().run()


setup(
    cmdclass={
        'build_py': BuildPyCommand,
    },
)
