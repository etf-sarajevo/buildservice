buildservice
=============

buildservice is a tool written in PHP for automated compiling, executing, debugging, testing and profiling a possibly large number of programs in various programming languages. Primary use cases for buildservice is automated evaluation of code in educational context such as homework grading, programming competitions, webide etc.

buildservice consists of two components that work together:

PUSH.PHP is the server side of buildservice. It implements a REST+JSON web service that represents a task dispatcher. It handles requests (programs with test specification) and dispatches them to possibly many worker nodes. A client can poll this web service to verify if the task is completed and fetch results in JSON format.

PULL.PHP is a standalone command-line PHP script that connects to push.php, fetches next task, runs all the specified tests and submits the results back to push.php. This way the web service serves as manager for running multiple instances of buildservice on many hosts. For added security these hosts can be protected via firewall, run inside virtual machine etc.

buildservice is used for several years on Faculty of Electrical Engineering Sarajevo for evaluating student programs in introductory programming courses.

System requirements
===================

* A contemporary Linux distribution
  - tested on: Ubuntu 12.04, 14.04, 16.04, Centos 5.4, 6.0, 7.0
  - should work on all *nix systems but not tested
  - it should be possible to port to Windows (mostly dir separator should be detected)
* PHP command-line interpreter (package php5-cli or php7-cli on Ubuntu/Debian)
* Some compilers, debuggers etc. that you want to use - see list of supported tools below.
* In order to detect crashes, your system must be configured to create core dump files. buildservice expects the file to be located in current execution directory and be named core.PID. Sadly, there is no other way to reliably detect if a program had crashed.
  - This site explains how to configure kernel core pattern: https://sigquit.wordpress.com/2009/03/13/the-core-pattern/ Set it to: core.%p
* buildservice uses ulimit to control the resources that a program can consume. Make sure that the user you ran buildservice as has permissions to use ulimit.

Language and tool support matrix
================================

Programming Language | Compiler | Executor | Debugger | Profiler | Notes
---------------------|----------|----------|----------|----------|----------
C                    | gcc (clang)      | /        | gdb      | valgrind | Supported (*)
C++                  | g++ (clang++)    | /       | gdb       | valgrind | Supported (*)
Python               | python3 -m py_compile | python3 | ? | ? | Under development
Java                 | javac (JDK)  | java     | ?  | ? | Under development
Pascal               | fpc (FreePascal)    | /        | gdb     | valgrind | Planned
PHP                  | php       | php     | /       | /        | Under development
HTML                 | w3c validator | screenshot | / | / | Planned - Started
CSS                  | w3c css validator | / | / | / | Planned - started
BASIC                | fbc (FreeBASIC) | / | ? | ? | Planned

(*) clang and clang++ compilers were tested and existing gcc parser works fine.

Getting started
===============

1. Set up a web site that handles user submissions (programs), gives an UI to specify tasks etc.
2. This web site should respond to REST requests (docs under construction). A sample web app is under construction.
3. Check-out buildservice into a folder on some computer.
4. Rename config.php.default to config.php - most importantly you should enter some unique ID for your PC (hostname?), URL to web service, optionally username and password if required.
5. Type "php pull.php" - it will attempt to grab all untested projects from your web service and perform whatever tasks neccessary.


Comparison with programming contest graders
===========================================

There is a number of softwares that implement a programming contest management system (a.k.a judge). One of the better known systems is [Contest Management System](https://cms-dev.github.io/) that is used for organizing International Olymiad in Informatics. Buildservice roughly fullfills the role of "grader" component of such systems. However, these systems are implemented by the competitive programming community that is often insensitive to the needs of beginner programmers. Buildservice implements a number of features that are currently not and likely never will be implemented in judge graders, since their developers find such features irrelevant for their particular use-case. In many cases even adding such features in the form of patches is impossible due to grader architecture.

Below is a short list of features found in buildservice that are missing in most graders known to us.

* For each test, buildservice returns program output that user can compare to the expected output. We find this critical for early learners in programming. While such feature would be trivial to add to existing graders, their developers often refuse to do so saying that this is incompatible with contest rules. We believe that a grader should provide program output, and then this output can be hidden by contest management frontend.
* Most graders fail to reliably detect crashes and/or unhandled exceptions, usually just returning "wrong output" status. Buildservice detects both situations and even uses gdb to provide a backtrace.
* Most graders fail to detect non-critical runtime errors such as undefined behaviors (memory leaks, out-of-bound access, uninitialized reads etc.) Rationale given is that most contests don't specifically prohibit those behaviors. However, given that in C and C++ this can randomly cause crashes and wrong output, combined with our first point this causes huge frustration. We believe that information on runtime errors should at least be shown to programmer, and use valgrind for dynamic run-time analysis.
* Most graders support only two ways of evaluating program output: exact string match or custom grader script. In addition to these, buildservice supports fuzzy output matching, including advanced whitespace handling, substring and regex matching, and also supports "autotests". Autotest is a form of unit test performed by patching the submitted source code which works even with no programmer foreknowledge. This gives unprecedented ability to develop custom graders, and also to grade programs based on their structure and object design rather then just their output.
* Buildservice allows to require or forbid certain programming constructs e.g. use of sort() function and its derivatives can be forbidden. While contests usually don't have such limits in their rules (mostly because they lack the software support to enforce such a rule), this feature is critical in educational context where teachers want to specifically focus on teaching certain constructs. Test developers can require certain functions and classes along with their prototypes to teach e.g passing by value vs. passing by reference. Judging programs solely by their output is in many cases opposed to programming best practices and educational goals.
* Most graders use specially developed, highly complex tools to ensure grading fairness and prevent cheating. An example of such tool is [Isolate](https://github.com/cms-dev/isolate). [This paper](http://mj.ucw.cz/papers/isolate.pdf) gives a nice summary of issues that such tools attempt to solve.
  - While developing isolation tools is certainly fun, we find that most of the problems they are trying to prevent are solved simply by running workers on separate hosts. Meanwhile, complexity of these tools often causes performance issues such as unexpected stalls in batch processing that requires manual server restart. Also, various compatibility problems require constant patching of these tools to support latest Linux distros.
  - With buildservice, worker nodes (pull.php) are run on separate machines that communicate exclusively via http, which prevents hacks such as changing SQL tables to improve ones ranking, and also prevents a submitted program that uses a lot of resources to DoS main contest server. Network hacks are prevented by running these machines on a separate subnet with a firewall and/or proxy that limits all access except push-pull. Local root exploits and running dangerous binaries are controlled by using a very stripped down and hardened Linux OS (or even OpenBSD!) that you normally wouldn't use e.g. for production web server. In addition, buildservice uses rlimit, a POSIX standard mechanism, to control program execution time, CPU and memory usage. Even if a particularly skilled hacker managed to compromise buildservice, the most they can do is take out one of many worker nodes before they're noticed. All this gives a very simple, reliable and compatible system, that is secured using standard principles of solid system administration rather then some magical binary that "does security".
  - Cited paper implies that running worker nodes inside virtual machines can jeopardize contest fairnes due to large variance in execution times. Over the several years of using this system in production we've found this effect to be negligible. Anyway, in case of high profile contests where even this theoretical variance should be avoided, it's possible to procure a large number of lightweight worker machines such as a cluster Raspberry Pi nodes.
* We find buildservice much easier to setup and use. Also, well documented REST+JSON interface makes it easy to interface with various other systems such as LMS, webide etc.


Comparison with CI tools
========================

A number of people have noticed that buildservice essentially does the role of continuous integration (CI) tool. In general this is true. However CI and buildservice target completely different communities, have different goals and their features are incompatible. A professional CI tool such as [Travis](https://travis-ci.org/) has an enormous list of features not found in buildservice, yet simply integrating Travis in a contest management system or homework submission system / LMS would require scripts of complexity far greater than current buildservice. Below is a short list of problems with "just use a CI".

* A fully fledged CI tool is huge, heavy and slow. In some situations there might not be sufficient server resources to use them. Also tests might take a while to complete. Even though real-time results are not a hard requirement, buildservice and other graders are usually near-real-time unless under heavy load. Also, sometimes all submissions need to be regraded which requires running hundreds of test tasks. CI tool must complete those in a reasonable time.
* Most CI tools work by updating a git (or svn...) tree periodically, then retesting everything when it changes. This is not how contest/homework grading is usually done. User submissions are usually small but numerous and CI tools don't scale well for this particular usage scenario. While some tools provide an API that allows to run tests at arbitrary time on arbitrary sources, such APIs are not easy to use.
* CI tools usually have no concept of security. It's assumed that programmers will use it in good faith, something that contest managers and educational institutions sadly can't do. 
* CI tools require that projects include build systems (at least makefiles). These can be generated automatically, but such requirement significantly raises complexity of the frontend and we have to wonder if the whole "CI integration" part doesn't become more complex than buildservice.
* CI tools are usually written around unit testing frameworks such as junit, nunit etc. There are [similar frameworks](http://cunit.sourceforge.net/) for C and C++ but CI tools usually don't support them or the corresponding plugins are broken. Such support could be easy to add, but there is another problem: due to design of C language, unit testing uses shims in code that require rather advanced knowledge of programming. For these reasons we have created *autotest* framework that works by patching the code so that code can be written without knowledge of unit testing. We have looked at porting this framework to work with a CI tool, but that would require such extensive rewrite that it would probably be easier to create it from scratch.
* Buildservice detects crashes and provides a backtrace via gdb. It also uses valgrind to detect several classes of run-time errors. This is possible on Travis CI but not very simple, and the results are not parsed / human readable (maybe this is outdated?)
