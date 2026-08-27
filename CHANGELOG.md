# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [4.0.0] - 2026-08-27

### Changed

* **BREAKING** PHP 8.1 or above is now required. PHP 8.0 and below are no longer supported; use version 3.x there.
* **BREAKING** Methods _ExponentialBackoff::setType()_ and _ExponentialBackoff::getType()_ now take and return enum
  _CrowdStar\Backoff\Type_ instead of an integer.
* **BREAKING** The second parameter of _ExponentialBackoff::__construct()_ is now `?CrowdStar\Backoff\Sapi` instead of
  an integer, and defaults to NULL (autodetect) instead of 0.
* Method _AbstractRetryCondition::met()_ now declares its first parameter as `mixed`. Existing subclasses that leave the
  parameter untyped keep working.
* Class properties now use native types, and _ExponentialBackoff::$sapi_ is `readonly`.

### Added

* Enum _CrowdStar\Backoff\Type_, replacing constants _ExponentialBackoff::TYPE_MICROSECONDS_ and
  _ExponentialBackoff::TYPE_SECONDS_.
* Enum _CrowdStar\Backoff\Sapi_, replacing the protected constants _ExponentialBackoff::SAPI_DEFAULT_ and
  _ExponentialBackoff::SAPI_SWOOLE_. Unlike those, it can be used by callers to force blocking or non-blocking mode.

### Removed

* **BREAKING** Constants _ExponentialBackoff::TYPE_MICROSECONDS_, _ExponentialBackoff::TYPE_SECONDS_,
  _ExponentialBackoff::SAPI_DEFAULT_, and _ExponentialBackoff::SAPI_SWOOLE_. Use the enums instead.
* **BREAKING** Method _ExponentialBackoff::getCurrentAttempts()_, deprecated since 3.x. Property
  _ExponentialBackoff::$currentAttempts_ no longer carries an initial value either: it is set by _::run()_ before the
  first attempt, so reading it through reflection before a run now raises an _Error_ instead of returning 1.
* **BREAKING** Methods _ExceptionBasedCondition::getException()_ and _ExceptionBasedCondition::setException()_,
  deprecated since 3.0.10. Use _::getExceptions()_ and _::setExceptions()_ instead, which handle one or more types.
* Exceptions previously thrown for an invalid backoff type or an invalid `$sapi` value. Both are now impossible, so
  _ExponentialBackoff::__construct()_ and the protected methods _::retry()_ and _::sleep()_ no longer throw.

### Migration from 3.x

1. Require PHP 8.1 or above.
2. Replace the type constants with enum cases:
   ```php
   $backoff->setType(ExponentialBackoff::TYPE_SECONDS);      // 3.x
   $backoff->setType(\CrowdStar\Backoff\Type::Seconds);      // 4.0
   ```
   Method _getType()_ returns a _Type_ instead of an integer. Use `$backoff->getType()->value` if you need the old
   integer, the enums are backed by the same values as the removed constants.
3. If you passed the second constructor parameter, pass an enum case instead of an integer:
   ```php
   new ExponentialBackoff($condition, 2);                          // 3.x
   new ExponentialBackoff($condition, \CrowdStar\Backoff\Sapi::Swoole); // 4.0
   ```
   Pass NULL, or nothing at all, to keep autodetecting Swoole coroutines.
4. Replace the singular exception accessors on _ExceptionBasedCondition_ with the plural ones:
   ```php
   $condition->setException(Exception::class);      // 3.x
   $condition->setExceptions(Exception::class);     // 4.0, accepts one or more types
   ```
   Method _getExceptions()_ returns a `string[]` where _getException()_ returned a single class name.
5. Drop any call to _ExponentialBackoff::getCurrentAttempts()_. There is no replacement; the attempt counter is
   internal.

## [3.0.12] - 2026-04-19

### Changed

* Static analysis upgraded from PHPStan v1 to v2, still running at maximum level 9.

### Added

* Test executions under PHP 8.4 and PHP 8.5.

### Fixed

* PHP 8.1+ deprecation in method _ExponentialBackoff::getTimeoutMicroseconds()_: `rand()` and the division
  `$timeout / 10` were replaced with `random_int()` and `intdiv($timeout, 10)`. Passing a float to the `int` parameters
  of `rand()` triggers a deprecation warning when the timeout is not evenly divisible by 10. `random_int()` also
  provides a better uniform distribution than `mt_rand()` (aliased by `rand()`).

## [3.0.11] - 2023-05-05

### Changed

* Allow reusing the same instance of _ExponentialBackoff_ multiple times.
* Add CI job to run tests on PHP 8.2.
* Improvements on the CI jobs through GitHub Actions.
* Coding style improvements.

## [3.0.10] - 2021-12-10

### Added

* Allow doing exponential backoff when multiple throwable objects (except PHP internal errors) are thrown out.

## [3.0.9] - 2021-12-08

### Changed

* Allow doing exponential backoff when _Throwable_ objects are thrown out.

## [3.0.8] - 2021-03-24

### Fixed

* Allow to silence exceptions when exponential backoff disabled.

## [3.0.7] - 2021-01-31

### Added

* Allow to silence an exception when finally failed, by overriding method _AbstractRetryCondition::throwable()_.

## [3.0.6] - 2020-12-15

### Fixed

* Fix coroutine detection in Swoole.

## [3.0.5] - 2020-12-09

### Changed

* Use GitHub Actions instead of _Travis CI_ for automated tests.
* Make some getter methods public and static.

## [3.0.4] - 2020-08-06

### Added

* Support doing exponential backoff in non-blocking mode in [Swoole](https://github.com/swoole/swoole-src).

### Changed

* Use [strict mode](https://www.php.net/manual/en/functions.arguments.php#functions.arguments.type-declaration.strict).
* Use [PSR-12](https://www.php-fig.org/psr/psr-12/) instead of PSR-2 for coding style checks.
* More unit tests.

### Fixed

* Fix execution delay when using seconds as the base retry interval.

## [3.0.3] - 2018-08-14

### Changed

* Use the Apache-2.0 license.

## [3.0.2] - 2018-07-27

### Added

* Allow disabling exponential backoff temporarily.

### Changed

* Use package [crowdstar/reflection](https://github.com/Crowdstar/reflection) in unit tests.
* Use PHPUnit 7 instead of PHPUnit 6 when running unit tests.

## [3.0.1] - 2018-06-11

### Fixed

* An issue with exception based conditions, with unit tests added to cover the changes made.

## [3.0.0] - 2018-06-11

### Added

* First public release that is ready for production use.

[4.0.0]: https://github.com/Crowdstar/exponential-backoff/releases/tag/4.0.0
[3.0.12]: https://github.com/Crowdstar/exponential-backoff/releases/tag/3.0.12
[3.0.11]: https://github.com/Crowdstar/exponential-backoff/releases/tag/3.0.11
[3.0.10]: https://github.com/Crowdstar/exponential-backoff/releases/tag/3.0.10
[3.0.9]: https://github.com/Crowdstar/exponential-backoff/releases/tag/3.0.9
[3.0.8]: https://github.com/Crowdstar/exponential-backoff/releases/tag/3.0.8
[3.0.7]: https://github.com/Crowdstar/exponential-backoff/releases/tag/3.0.7
[3.0.6]: https://github.com/Crowdstar/exponential-backoff/releases/tag/3.0.6
[3.0.5]: https://github.com/Crowdstar/exponential-backoff/releases/tag/3.0.5
[3.0.4]: https://github.com/Crowdstar/exponential-backoff/releases/tag/3.0.4
[3.0.3]: https://github.com/Crowdstar/exponential-backoff/releases/tag/3.0.3
[3.0.2]: https://github.com/Crowdstar/exponential-backoff/releases/tag/3.0.2
[3.0.1]: https://github.com/Crowdstar/exponential-backoff/releases/tag/3.0.1
[3.0.0]: https://github.com/Crowdstar/exponential-backoff/releases/tag/3.0.0
