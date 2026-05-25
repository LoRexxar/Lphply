# coding: utf-8
from setuptools import setup, find_packages, Command

try:
    from setuptools.command.build import build as setuptools_build
except ImportError:
    from distutils.command.build import build as setuptools_build

# Override build sub_commands to generate parsetab before build
original_sub_commands = setuptools_build.sub_commands[:]
setuptools_build.sub_commands = [('gen_parsetab', lambda _: True)] + original_sub_commands


class GenerateParsetab(Command):
    user_options = []
    def initialize_options(self):
        pass

    def finalize_options(self):
        pass

    def run(self):
        from phply.phpparse import make_parser
        make_parser(debug=False)


setup(name="phply",
      version="1.2.6",
      packages=find_packages(),
      include_package_data=True,
      author='Ramen',
      author_email='',
      maintainer='Stanisław Pitucha',
      maintainer_email='viraptor@gmail.com',
      description='Lexer and parser for PHP source implemented using PLY',
      zip_safe=False,
      platforms='any',
      license='BSD',
      url='https://github.com/viraptor/phply',

      classifiers=[
        'Development Status :: 5 - Production/Stable',
        'Environment :: Console',
        'Intended Audience :: Education',
        'License :: OSI Approved :: BSD License',
        'Programming Language :: Python :: 2',
        'Programming Language :: Python :: 3',
        'Programming Language :: PHP',
        'Operating System :: Unix',
        ],

      entry_points={
        'console_scripts': [
            'phpparse=phply.phpparse:main',
            'phplex=phply.phplex:run_on_argv1',
            ],
        },

      install_requires=[
        'ply',
        ],
      setup_requires=[
        'ply',
        ],
      extras_require={'test': ['pytest', 'tox']},

      cmdclass={
          'gen_parsetab': GenerateParsetab,
          'build': setuptools_build,
          }
      )
