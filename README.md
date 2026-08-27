[![Library Status](https://github.com/Crowdstar/exponential-backoff/workflows/Tests/badge.svg)](https://github.com/Crowdstar/exponential-backoff/actions)
[![Latest Stable Version](https://poser.pugx.org/Crowdstar/exponential-backoff/v/stable.svg)](https://packagist.org/packages/crowdstar/exponential-backoff)
[![Latest Unstable Version](https://poser.pugx.org/Crowdstar/exponential-backoff/v/unstable.svg)](https://packagist.org/packages/crowdstar/exponential-backoff)
[![License](https://poser.pugx.org/Crowdstar/exponential-backoff/license.svg)](https://packagist.org/packages/crowdstar/exponential-backoff)

* [Summary](#summary)
* [Installation](#installation)
* [Sample Usage](#sample-usage)
     * [1. Retry When Return Value Is Empty](#1-retry-when-return-value-is-empty)
     * [2. Retry When Certain Exceptions Thrown Out](#2-retry-when-certain-exceptions-thrown-out)
          * [Don't Throw Out an Exception When Finally Failed](#dont-throw-out-an-exception-when-finally-failed)
     * [3. Retry When Customized Condition Met](#3-retry-when-customized-condition-met)
     * [4. More Options When Doing Exponential Backoff](#4-more-options-when-doing-exponential-backoff)
          * [Doing the Waiting Elsewhere](#doing-the-waiting-elsewhere)
     * [5. To Disable Exponential Backoff Temporarily](#5-to-disable-exponential-backoff-temporarily)
* [Things to Keep in Mind](#things-to-keep-in-mind)
     * [Not Every Call Is Safe to Retry](#not-every-call-is-safe-to-retry)
     * [Retries Multiply When They Nest](#retries-multiply-when-they-nest)
* [Sample Scripts](#sample-scripts)

# Summary

Exponential back-offs prevent overloading an unavailable service by doubling the timeout each iteration. This class uses
an exponential back-off algorithm to calculate the timeout for the next request.

This library allows doing exponential backoff in non-blocking mode in [Swoole](https://github.com/swoole/swoole-src).
Coroutines are detected before every wait, so one instance can be shared by coroutines and by ordinary code alike; pass
a _\CrowdStar\Backoff\Sapi_ case as the second constructor parameter to force blocking mode, and
_\CrowdStar\Backoff\ExponentialBackoff::getSapi()_ tells which mode a wait would happen in.

# Installation

This library requires PHP 8.1 or above. For PHP 8.0 and below, use version 3.x instead.

```bash
composer require crowdstar/exponential-backoff:~4.0.0
```

# Sample Usage

In following code pieces, we assume that you want to store return value of method _MyClass::fetchData()_ in variable
_$result_, and you want to do exponential backoff on that because something unexpected could happen when running method
_MyClass::fetchData()_.

## 1. Retry When Return Value Is Empty

Following code is to try to fetch some non-empty data back with method _MyClass::fetchData()_. This piece of code will
try a few more times (by default 4) until either we get some non-empty data back, or we have reached maximum numbers
of retries.
 
```php
<?php
use CrowdStar\Backoff\EmptyValueCondition;
use CrowdStar\Backoff\ExponentialBackoff;

$result = (new ExponentialBackoff(new EmptyValueCondition()))->run(
    function () {
        return MyClass::fetchData();
    }
);
?>
```

## 2. Retry When Certain Exceptions Thrown Out

Following code is to try to fetch some data back with method _MyClass::fetchData()_, which may throw out exceptions.
This piece of code will try a few more times (by default 4) until either we get some data back, or we have reached
maximum numbers of retries.

NOTE: Internal PHP errors (class [Error](https://www.php.net/error)) won't trigger exponential backoff. They should be
fixed manually. Listing _Throwable_ or one of _Error_'s subclasses does not change that: only exceptions ever reach a
retry condition, so a _TypeError_ ends a run however the condition is set up.

```php
<?php
use CrowdStar\Backoff\ExceptionBasedCondition;
use CrowdStar\Backoff\ExponentialBackoff;

// Allow to catch multiple types of exceptions.
$backoff = new ExponentialBackoff(new ExceptionBasedCondition(LogicException::class, RuntimeException::class));
try {
    $result = $backoff->run(
        function () {
            return MyClass::fetchData();
        }
    );
} catch (Throwable $t) {
    // Handle the errors here.
}
?>
```

To retry a whole family of exceptions except for one of its members, list that one through method
_\CrowdStar\Backoff\ExceptionBasedCondition::setIgnoredExceptions()_. Ignored types are never retried, whichever
types are being retried on, so there is no need to enumerate every sibling of the one you want left alone:

```php
<?php
use CrowdStar\Backoff\ExceptionBasedCondition;
use CrowdStar\Backoff\ExponentialBackoff;

// Retry any HttpException -- a 503 or a timeout is worth another attempt -- but give up on a 400 right away, since
// sending the same bad request again will fail the same way.
$condition = (new ExceptionBasedCondition(HttpException::class))
    ->setIgnoredExceptions(HttpBadRequestException::class);

$result = (new ExponentialBackoff($condition))->run(
    function () {
        return MyClass::fetchData();
    }
);
?>
```

An ignored exception ends the run at once and is thrown out, the same as one that was never covered to begin with.

### Don't Throw Out an Exception When Finally Failed

When method call _MyClass::fetchData()_ finally fails with an exception caught, we can silence the exception without
throwing it out by overriding method _AbstractRetryCondition::throwable()_:

```php
<?php
use CrowdStar\Backoff\AbstractRetryCondition;
use CrowdStar\Backoff\ExponentialBackoff;

$backoff = new ExponentialBackoff(
    new class extends AbstractRetryCondition {
        public function throwable(): bool
        {
            return false;
        }
        public function shouldRetry(mixed $result, ?Exception $e): bool
        {
            return ($e instanceof Exception);
        }
    }
);

$backoff->run(
    function () {
        return MyClass::fetchData();
    }
);
?>
```

If needed, you can have more complex logic defined when overriding method _AbstractRetryCondition::throwable()_.

## 3. Retry When Customized Condition Met

Following code is to try to fetch some non-empty data back with method _MyClass::fetchData()_. This piece of code works
the same as the first example, except that here the condition to retry on is written out instead of coming from class
_\CrowdStar\Backoff\EmptyValueCondition_. Method _\CrowdStar\Backoff\ExponentialBackoff::when()_ takes a closure that
receives what the call returned and what it threw, and returns TRUE for as long as another attempt should be made:

```php
<?php
use CrowdStar\Backoff\ExponentialBackoff;

$result = ExponentialBackoff::when(fn (mixed $result): bool => empty($result))->run(
    function () {
        return MyClass::fetchData();
    }
);
?>
```

The closure is given both the return value and the exception, so conditions about exceptions work the same way:

```php
<?php
use CrowdStar\Backoff\ExponentialBackoff;

// Retry when a \RuntimeException was thrown, and don't throw it out when the last attempt still fails.
$backoff = ExponentialBackoff::when(
    fn (mixed $result, ?Exception $e): bool => ($e instanceof RuntimeException),
    false
);
$result = $backoff->run(
    function () {
        return MyClass::fetchData();
    }
);
?>
```

Where a condition is worth naming and reusing, write a class for it instead, the way
_\CrowdStar\Backoff\EmptyValueCondition_ and _\CrowdStar\Backoff\ExceptionBasedCondition_ do:

```php
<?php
use CrowdStar\Backoff\AbstractRetryCondition;
use CrowdStar\Backoff\ExponentialBackoff;

final class UntilRateLimitLifts extends AbstractRetryCondition
{
    public function shouldRetry(mixed $result, ?Exception $e): bool
    {
        return ($result?->getStatusCode() === 429);
    }
}

$result = (new ExponentialBackoff(new UntilRateLimitLifts()))->run(
    function () {
        return MyClass::fetchData();
    }
);
?>
```

## 4. More Options When Doing Exponential Backoff

Following code is to try to fetch some data back with method _MyClass::fetchData()_. This piece of code works the
same as the second example, except that here the condition to retry on is written out instead of coming from class
_\CrowdStar\Backoff\ExceptionBasedCondition_.

In this piece of code, we also show what options are available when doing exponential backoff with the package.

```php
<?php
use CrowdStar\Backoff\EmptyValueCondition;
use CrowdStar\Backoff\ExceptionBasedCondition;
use CrowdStar\Backoff\ExponentialBackoff;
use CrowdStar\Backoff\Jitter;

$backoff = new ExponentialBackoff(new EmptyValueCondition());
$backoff = new ExponentialBackoff(new ExceptionBasedCondition());
$backoff = new ExponentialBackoff(new ExceptionBasedCondition(LogicException::class, RuntimeException::class));
$backoff = ExponentialBackoff::when(fn (mixed $result, ?Exception $e): bool => ($e instanceof Exception));

$backoff
    ->setInitialTimeout(1_000_000)  // Wait about 1 second before the first retry.
    ->setInitialTimeout(ExponentialBackoff::DEFAULT_INITIAL_TIMEOUT)
    ->setMaxAttempts(3)
    ->setMaxAttempts(4)
    ->setMaxTimeout(5_000_000)  // Wait at most 5 seconds between two attempts.
    ->setMaxTimeout(ExponentialBackoff::DEFAULT_MAX_TIMEOUT)
    ->setJitter(Jitter::Equal)  // Wait at least half of the calculated timeout, and randomly up to all of it.
    ->setJitter(Jitter::None)   // Wait exactly as long as calculated; predictable, but no protection from collisions.
    ->setJitter(Jitter::Full);  // Wait anywhere between nothing and the calculated timeout. The default.

$result = $backoff->run(
    function () {
        return MyClass::fetchData();
    }
);
?>
```

### Doing the Waiting Elsewhere

Method _\CrowdStar\Backoff\ExponentialBackoff::setSleeper()_ hands the waiting over to a callback of yours, which
receives the wait in microseconds. Two things it is for: waiting on an event loop this library knows nothing about,
and tests — a callback that records and returns makes a retrying test instant, and lets it assert the timeouts that
would have been waited for:

```php
<?php
use CrowdStar\Backoff\EmptyValueCondition;
use CrowdStar\Backoff\ExponentialBackoff;
use CrowdStar\Backoff\Jitter;

$slept   = [];
$backoff = (new ExponentialBackoff(new EmptyValueCondition()))
    ->setJitter(Jitter::None)
    ->setSleeper(function (int $microSeconds) use (&$slept): void {
        $slept[] = $microSeconds;
    });

$backoff->run(function () { return MyClass::fetchData(); });

// $slept is now [250000, 500000, 1000000], and the test took no time at all.
?>
```

A sleeper takes precedence over blocking and non-blocking mode both. Pass NULL to hand the waiting back.

## 5. To Disable Exponential Backoff Temporarily

There are two ways to disable exponential backoff temporarily for code piece like following:

```php
<?php
$result = MyClass::fetchData();
?>
```

First, you may disable exponential backoff temporarily by calling method _\CrowdStar\Backoff\ExponentialBackoff::disable()_. For example:

```php
<?php
use CrowdStar\Backoff\EmptyValueCondition;
use CrowdStar\Backoff\ExponentialBackoff;

$backoff = new ExponentialBackoff(new EmptyValueCondition());
$backoff->disable();
$result = $backoff->run(function () {return MyClass::fetchData();});
?>
```

You may also disable exponential backoff temporarily by using class _\CrowdStar\Backoff\NullCondition_:

```php
<?php
use CrowdStar\Backoff\ExponentialBackoff;
use CrowdStar\Backoff\NullCondition;

$result = (new ExponentialBackoff(new NullCondition()))
    ->setRetryCondition(new NullCondition()) // The method here is for demonstration purpose.
    ->run(function () {return MyClass::fetchData();});
?>
```

All these 3 code piece work the same, having return value of method call _MyClass::fetchData()_ assigned to variable _$result_.

# Things to Keep in Mind

## Not Every Call Is Safe to Retry

A retry sends the same call again, so it is only safe where sending it twice is as good as sending it once. Reads
usually are. Anything that creates or changes something may well not be: a request that timed out on the way back may
have been carried out in full, and retrying it then does the work twice.

Where a call is not naturally repeatable, make it so before retrying — a payment provider taking an idempotency key,
a database statement written to be a no-op the second time — or accept the duplicate knowingly. This library retries
whatever closure it is handed and cannot tell the difference.

## Retries Multiply When They Nest

Attempts multiply through layers rather than adding up. Retrying 4 times around a call that itself retries 4 times is
16 attempts, and three such layers is 64; a five-deep stack of three retries each reaches 243 attempts on whatever
sits at the bottom, which is usually the thing that was already struggling.

Method _\CrowdStar\Backoff\ExponentialBackoff::run()_ takes any closure at all, including one that retries inside. When
several layers of your own code could each retry, pick one of them — as a rule the one closest to the failing call —
and let the failure travel up from the others.

# Sample Scripts

Sample scripts can be found under folder _examples/_. Before running them under CLI, please do a composer update first:

```bash
composer update -n
```
