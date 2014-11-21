buildservice
=============

buildservice is a tool written in PHP for automated compiling, executing, debugging, testing and profiling a possibly large number of programs in various programming languages. Primary use cases for buildservice is automated evaluation of code in educational context such as homework grading, programming competitions, webide etc.

buildservice can be used in two basic ways:

PULL.PHP - buildhost connects to some external REST+JSON web service to grab program, runs all the tests specified and submits results to the same web service. This way the web service serves as manager for running multiple instances of buildservice on many hosts. For added security these hosts can be protected via firewall, run inside virtual machine etc.

PUSH.PHP - buildservice runs on a web server where it accepts tasks (under development). This will also allow interactive execution.

buildservice is used for several years on Faculty of Electrical Engineering Sarajevo for evaluating student programs in introductory programming courses.

System requirements
===================

* A contemporary Linux distribution
  - tested on: Ubuntu 12.04, 14.04, Centos 5.4, 6.0
  - should work on all *nix systems but not tested
  - it should be fairly easy to port to Windows (mostly dir separator should be detected)
* PHP command-line interpreter (package php5-cli on Ubuntu/Debian)
* Some compilers, debuggers etc. that you wan't to use - see list of supported tools below.

Language and tool support matrix
================================

Programming Language | Compiler | Executor | Debugger | Profiler | Notes
---------------------|----------|----------|----------|----------|----------
C                    | gcc (clang)      | /        | gdb      | valgrind | Supported (*)
C++                  | g++ (clang++)    | /       | gdb       | valgrind | Supported (*)
Python               | python3 -m py_compile | python3 | / | / | Under development
Java                 | javac (JDK)  | java     | /  | / | Under development
Pascal               | fpc (FreePascal)    | /        | gdb     | valgrind | Planned
PHP                  | php       | php     | /        | /        | Under development
HTML                 | w3c validator | screenshot | / | / | Planned - Started
CSS                  | w3c css validator | / | / | / | Planned - started
BASIC                | fbc (FreeBASIC) | / | / | / Planned

(*) clang and clang++ compilers were tested and work but no parser is currently available.

Getting started
===============

1. Set up a web site that handles user submissions (programs), gives an UI to specify tasks etc.
2. This web site should respond to REST requests (docs under construction).
3. A sample web app is under construction.
4. Check-out buildservice into a folder on some computer.
5. Edit config.php - most importantly you should enter some unique ID for your PC (hostname?), URL to web service, optionally username and password if required.
6. Type "php pull.php" - it will attempt to grab all untested projects from your web service and perform whatever tasks neccessary.

