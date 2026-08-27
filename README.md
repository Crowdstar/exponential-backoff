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
     * [5. To Disable Exponential Backoff Temporarily](#5-to-disable-exponential-backoff-temporarily)
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
fixed manually.

```php
<?php
use CrowdStar\Backoff\ExceptionBasedCondition;
use CrowdStar\Backoff\ExponentialBackoff;

// Allow to catch multiple types of exceptions and throwable objects.
$backoff = new ExponentialBackoff(new ExceptionBasedCondition(Exception::class, Throwable::class));
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
$backoff = new ExponentialBackoff(new ExceptionBasedCondition(Exception::class, Throwable::class));
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

# Sample Scripts

Sample scripts can be found under folder _examples/_. Before running them under CLI, please do a composer update first:

```bash
composer update -n
```
