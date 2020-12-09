[![Library Status](https://github.com/Crowdstar/exponential-backoff/workflows/Tests/badge.svg)](https://github.com/Crowdstar/exponential-backoff/actions)
[![Latest Stable Version](https://poser.pugx.org/Crowdstar/exponential-backoff/v/stable.svg)](https://packagist.org/packages/crowdstar/exponential-backoff)
[![Latest Unstable Version](https://poser.pugx.org/Crowdstar/exponential-backoff/v/unstable.svg)](https://packagist.org/packages/crowdstar/exponential-backoff)
[![License](https://poser.pugx.org/Crowdstar/exponential-backoff/license.svg)](https://packagist.org/packages/crowdstar/exponential-backoff)

# Summary

Exponential back-offs prevent overloading an unavailable service by doubling the timeout each iteration. This class uses
an exponential back-off algorithm to calculate the timeout for the next request.

This library allows doing exponential backoff in non-blocking mode in [Swoole](https://github.com/swoole/swoole-src).

# Installation

```bash
composer require crowdstar/exponential-backoff:~3.0.0
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

## 2. Retry When Certain Exception Thrown Out

Following code is to try to fetch some data back with method _MyClass::fetchData()_, which may throw out exceptions.
This piece of code will try a few more times (by default 4) until either we get some data back, or we have reached
maximum numbers of retries.

```php
<?php
use CrowdStar\Backoff\ExceptionBasedCondition;
use CrowdStar\Backoff\ExponentialBackoff;

$backoff = new ExponentialBackoff(new ExceptionBasedCondition(Exception::class));
try {
    $result = $backoff->run(
        function () {
            return MyClass::fetchData();
        }
    );
} catch (Exception $e) {
    // Handle the exception here.
}
?>
```

## 3. Retry When Customized Condition Met

Following code is to try to fetch some non-empty data back with method _MyClass::fetchData()_. This piece of code works
the same as the first example, except that here it's implemented with a customized condition class instead of class
_\CrowdStar\Backoff\EmptyValueCondition_.

```php
<?php
use CrowdStar\Backoff\AbstractRetryCondition;
use CrowdStar\Backoff\ExponentialBackoff;

$backoff = new ExponentialBackoff(
    new class extends AbstractRetryCondition {
        public function met($result, ?Exception $e): bool
        {
            return !empty($result);
        }
    }
);
$result = $backoff->run(
    function () {
        return MyClass::fetchData();
    }
);
?>
```

## 4. More Options When Doing Exponential Backoff

Following code is to try to fetch some data back with method _MyClass::fetchData()_. This piece of code works the
same as the second example, except that here it's implemented with a customized condition class instead of class
_\CrowdStar\Backoff\ExceptionBasedCondition_.

In this piece of code, we also show what options are available when doing exponential backoff with the package.

```php
<?php
use CrowdStar\Backoff\AbstractRetryCondition;
use CrowdStar\Backoff\EmptyValueCondition;
use CrowdStar\Backoff\ExceptionBasedCondition;
use CrowdStar\Backoff\ExponentialBackoff;

$backoff = new ExponentialBackoff(new EmptyValueCondition());
$backoff = new ExponentialBackoff(new ExceptionBasedCondition());
$backoff = new ExponentialBackoff(new ExceptionBasedCondition(Exception::class));
$backoff = new ExponentialBackoff(
    new class extends AbstractRetryCondition {
        public function met($result, ?Exception $e): bool
        {
            $exception = $this->getException();

            return (empty($e) || (!($e instanceof $exception)));
        }
    }
);

$backoff
    ->setType(ExponentialBackoff::TYPE_SECONDS)
    ->setType(ExponentialBackoff::TYPE_MICROSECONDS)
    ->setMaxAttempts(3)
    ->setMaxAttempts(4);

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
