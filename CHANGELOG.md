# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [4.0.0] - 2026-08-27

### Changed

* **BREAKING** PHP 8.1 or above is now required. PHP 8.0 and below are no longer supported; use version 3.x there.
* **BREAKING** The unit of a timeout is no longer a choice: timeouts are always expressed in microseconds, and how long
  to wait before the first retry is set with _ExponentialBackoff::setInitialTimeout()_ rather than picked from two
  hardcoded values. This is what the removed _setType()_ was standing in for, and unlike it, any timeout can now be
  expressed — a tenth of a second, or a second and a half.
* **BREAKING** The second parameter of _ExponentialBackoff::__construct()_ is now `?CrowdStar\Backoff\Sapi` instead of
  an integer, and defaults to NULL (autodetect) instead of 0.
* **BREAKING** Method _AbstractRetryCondition::met()_ is now _::shouldRetry()_, and it answers the opposite question:
  return TRUE to try the call again, where _met()_ returned TRUE to stop. Every other retry library phrases this the way
  around _shouldRetry()_ does, and _met()_ conflated "this succeeded" with "give up on this", which is why the shipped
  conditions read as double negatives. The method also declares its first parameter as `mixed` now.

  Renaming and inverting together is deliberate: because _met()_ is gone, a condition that still implements it fails to
  declare itself at all — a fatal error naming the method — rather than quietly retrying whenever it used to stop.
* **BREAKING** A single timeout is now capped at 30 seconds by default, configurable with
  _ExponentialBackoff::setMaxTimeout()_. Timeouts double until they reach the cap and stay there, where before they
  doubled without limit. With the default of 4 attempts the longest timeout is 1 second, so the default behavior is
  unchanged; runs configured with more attempts than that now wait considerably less.
* **BREAKING** Method _ExponentialBackoff::getTimeoutMicroseconds()_ takes a maximum timeout as its third parameter and
  a _CrowdStar\Backoff\Jitter_ case as its fourth. Pass them explicitly to opt out of the default cap and of the
  default randomness.
* **BREAKING** A timeout is now randomized over its whole length instead of being lengthened by up to 10%, and the
  amount of randomness is configurable with _ExponentialBackoff::setJitter()_. Waits are therefore shorter on average
  and no longer predictable: a timeout has become the longest a wait may take rather than the shortest. Clients that
  failed together are what this spreads out; the measurements behind it are in the AWS article linked from enum
  _CrowdStar\Backoff\Jitter_. Pass _Jitter::None_ for the previous predictability, or _Jitter::Equal_ to keep at least
  half of every timeout.
* The randomness is no longer rounded away for timeouts of about a second. Where a seconds-mode timeout used to be
  rounded down to whole seconds after being randomized — which for a one-second timeout discarded the randomness
  entirely, leaving every client to retry in lockstep — nothing rounds any more.

### Added

* Enum _CrowdStar\Backoff\Sapi_, which callers can pass as the second constructor parameter to force blocking or
  non-blocking mode instead of having it worked out per wait.
* Methods _ExponentialBackoff::setInitialTimeout()_, _::getInitialTimeout()_, _::setMaxTimeout()_ and
  _::getMaxTimeout()_, plus constants _ExponentialBackoff::DEFAULT_INITIAL_TIMEOUT_ and _::DEFAULT_MAX_TIMEOUT_.
* Method _ExponentialBackoff::getSapi()_, telling which mode a wait would happen in right now.
* Enum _CrowdStar\Backoff\Jitter_ with cases _None_, _Full_ and _Equal_, along with methods
  _ExponentialBackoff::setJitter()_ and _::getJitter()_ and constant _ExponentialBackoff::DEFAULT_JITTER_.
* Class _CrowdStar\Backoff\CallbackCondition_ and method _ExponentialBackoff::when()_, for deciding whether to retry
  with a closure instead of a condition class of your own:
  `ExponentialBackoff::when(fn (mixed $result): bool => empty($result))->run($c)`. The closure receives what the call
  returned and what it threw; a second argument to _::when()_ says whether an exception the last attempt was left with
  should be thrown out.
* Methods _ExceptionBasedCondition::setIgnoredExceptions()_ and _::getIgnoredExceptions()_, listing types that are
  never retried. Ignored types take priority over the types being retried on, so "retry every _HttpException_ except
  _HttpBadRequestException_" no longer means enumerating every sibling of the one exception to be left alone. An
  ignored exception ends the run at once and is thrown out, the same as one that was never covered.

### Fixed

* Whether to wait in non-blocking mode is now decided per wait instead of once at construction. An instance built
  outside a coroutine — a service put together during bootstrap, say — used to block forever afterwards, even when used
  by coroutines, which is the way it is normally wired up in a Swoole application. Passing _Sapi::Swoole_ where no
  coroutine is running no longer raises the _Swoole\Error_ it would produce either; the wait falls back to blocking.
* One instance of _ExponentialBackoff_ can now be used by several callers at once. The attempt counter was kept on the
  object and reset at the start of every _::run()_, so a closure that called _::run()_ again on the same instance reset
  the count of the run it was part of, and that run then gave up after a single attempt while returning as if it had
  succeeded. The same went for concurrent Swoole coroutines sharing an instance, which is how a service tends to be
  wired up there — and which the 3.0.11 note about reusing an instance did not warn about, being true only for runs
  happening one after another.
* Timeouts no longer overflow. Doubling an uncapped timeout left the integer range from the 46th attempt on, where
  _ExponentialBackoff::getTimeoutMicroseconds()_ threw a _TypeError_, and from the 65th attempt on the bit shift it
  used returned 0, silently disabling the backoff altogether. Both were reachable through _::setMaxAttempts()_, and
  neither could be caught by _::run()_, which handles exceptions rather than errors. Timeouts now stop at the maximum
  instead of growing past what an integer holds, and a non-positive iteration is treated as the first one rather than
  raising an _ArithmeticError_.

### Removed

* **BREAKING** Constants _ExponentialBackoff::TYPE_MICROSECONDS_ and _ExponentialBackoff::TYPE_SECONDS_ along with
  methods _::setType()_ and _::getType()_. Use _::setInitialTimeout()_ instead.
* **BREAKING** Method _ExponentialBackoff::getTimeoutSeconds()_. It rounded timeouts down to whole seconds, which
  discarded the randomness of anything under ten seconds, and it existed only to serve the removed seconds mode. Divide
  the result of _::getTimeoutMicroseconds()_ by 1000000 where seconds are wanted.
* **BREAKING** Method _ExponentialBackoff::getCurrentAttempts()_, deprecated since 3.x. There is no replacement: the
  attempt counter belongs to a single run and is no longer kept on the object.
* **BREAKING** Methods _ExceptionBasedCondition::getException()_ and _ExceptionBasedCondition::setException()_,
  deprecated since 3.0.10. Use _::getExceptions()_ and _::setExceptions()_ instead, which handle one or more types.
* Exceptions previously thrown for an invalid backoff type or an invalid `$sapi` value. Both are now impossible, so
  _ExponentialBackoff::__construct()_ no longer throws.

### Migration from 3.x

1. Require PHP 8.1 or above.
2. Rename _met()_ to _shouldRetry()_ in every condition of your own, and negate what it returns:
   ```php
   public function met(mixed $result, ?Exception $e): bool          // 3.x
   {
       return !empty($result);                                      // TRUE meant "stop, this worked"
   }

   public function shouldRetry(mixed $result, ?Exception $e): bool  // 4.0
   {
       return empty($result);                                       // TRUE means "try again"
   }
   ```
   Conditions written around exceptions usually get shorter: `return (empty($e) || (!($e instanceof Exception)));`
   becomes `return ($e instanceof Exception);`. A condition left implementing _met()_ raises a fatal error saying
   _shouldRetry()_ is not implemented, so nothing silently starts retrying where it used to stop.
3. Replace the type constants with an initial timeout in microseconds:
   ```php
   $backoff->setType(ExponentialBackoff::TYPE_SECONDS);        // 3.x
   $backoff->setInitialTimeout(1_000_000);                     // 4.0

   $backoff->setType(ExponentialBackoff::TYPE_MICROSECONDS);   // 3.x — this was the default
   $backoff->setInitialTimeout(250_000);                       // 4.0 — still the default, so drop the call
   ```
   Any other timeout works as well now: `setInitialTimeout(100_000)` waits about a tenth of a second before the first
   retry. Method _getTimeoutSeconds()_ is gone; divide _getTimeoutMicroseconds()_ by 1000000 where seconds are wanted.
4. If you passed the second constructor parameter, pass an enum case instead of an integer:
   ```php
   new ExponentialBackoff($condition, 2);                          // 3.x
   new ExponentialBackoff($condition, \CrowdStar\Backoff\Sapi::Swoole); // 4.0
   ```
   Pass NULL, or nothing at all, to keep autodetecting Swoole coroutines.
5. Replace the singular exception accessors on _ExceptionBasedCondition_ with the plural ones:
   ```php
   $condition->setException(Exception::class);      // 3.x
   $condition->setExceptions(Exception::class);     // 4.0, accepts one or more types
   ```
   Method _getExceptions()_ returns a `string[]` where _getException()_ returned a single class name.
6. Drop any call to _ExponentialBackoff::getCurrentAttempts()_. There is no replacement; the attempt counter is
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
