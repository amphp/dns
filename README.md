Addr
====

Asynchronous DNS resolver using [Alert](https://github.com/rdlowrey/Alert).


Tests
=====

[![Build Status](https://travis-ci.org/DaveRandom/Addr.svg?branch=master)](https://travis-ci.org/DaveRandom/Addr)

Tests can be run from the command line using:

`php vendor/bin/phpunit -c test/phpunit.xml`

or to exlude tests that require a working internet connection:

`php vendor/bin/phpunit -c test/phpunit.xml --exclude-group internet`
