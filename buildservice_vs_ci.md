Comparison with CI tools
========================

People who are not accustomed to automated evaluation of programs in the context of programming education often ask questions like "Why do you reinvent continuous integration?" and "What's wrong with ordinary unit tests?"

These solutions are industry standards used by professional programmers worldwide, but simply are unsuitable for programming education, especially at introductory levels.

Unit tests
==========

The principal difference between unit tests and buildservice "autotests" is that the former are part of the project that students work on, they reside in the same codebase, while the latter are patched into code during testing. Having homework evaluation tool in the same project as homework itself creates various real-life problems, such as these:
* The whole concept of "test tree", "test classes" etc. might be highly abstract to beginner programmers. Providing students with skeleton project to work on means that they never learn to create a project from scratch, while requiring them to write tests from day one might be too demanding. Introducting the concept of unit tests from day one increases the learning workload.
* It's impossible to create tests that check for existance of certain code constructs (functions, classes etc.) since such tests, along with the whole project, simply wouldn't compile. Having a project that doesn't compile until it's completely done prevents students from solving the task part by part and evaluating each subtask as it's done, one of the few benefits of programming. The common solution is to comment out certain tests (simply marking them as "Skip" is insufficient), but that creates a culture of editing the tests, which is a problem as described below.
* While solving a problem, students create a habit of editing the tests until the code compiles, which is a form of "cheating". Sometimes it's very difficult to explain the difference between editing the code and editing the tests, especially since compiler will position you at the test code. Of course the teacher can patch the code on server side to prevent cheating, but this again is problematic because students are genuinely confused about why they didn't receive maximum score because "it works for me".
* In addition, it's impossible to create tests that *ensure the nonexistance* of certain code constructs. For example, if a class is required to be a singleton, there could be an autotest which tries to invoke its constructor, and such test is expected to not compile. This is normal part of buildservice workflow, while impossible with unit tests.
* It's extremely cumbersome to write unit tests that verify the standard input and output, and might even be impossible with some programming languages. The test code, once created, will be extremely confusing to beginner students, they will not understand it and fail to correct their code. With buildservice standard input/output specification is part of every test and can be displayed very clearly in a beginner-friendly way.
