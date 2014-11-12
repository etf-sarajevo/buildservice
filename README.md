buildservice
=============

buildservice is a tool written in PHP for automated compiling, executing, debugging, testing and profiling a possibly large number of programs in various programming languages. Primary use cases for buildservice is automated evaluation of code in educational context such as homework grading, programming competitions, webide etc.

buildservice can be used in two basic ways:

PULL.PHP - buildhost connects to some external REST+JSON web service to grab program, runs all the tests specified and submits results to the same web service. This way the web service serves as manager for running multiple instances of buildservice on many hosts. For added security these hosts can be protected via firewall, run inside virtual machine etc.

PUSH.PHP - buildservice runs on a web server where it accepts tasks (under development). This will also allow interactive execution.

buildservice is used for several years on Faculty of Electrical Engineering Sarajevo for evaluating student programs in introductory programming courses.

