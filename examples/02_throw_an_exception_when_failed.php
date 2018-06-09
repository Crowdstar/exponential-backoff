#!/usr/bin/env php
<?php
/**
 * Sample code to return a non-empty value back after 3 failed attempts, where each failed attempt throws an
 * exception out.
 */

use CrowdStar\Backoff\ExceptionCondition;
use CrowdStar\Backoff\ExponentialBackoff;
use CrowdStar\Tests\Backoff\Helper;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// You may omit the first parameter "Exception::class" since it's the default value when not passed in:
//     $backoff = new ExponentialBackoff(new ExceptionCondition());
$backoff = new ExponentialBackoff(new ExceptionCondition(Exception::class));
$helper  = new Helper();
$result  = $backoff->run(
    function () use ($helper) {
        return $helper->getValueAfterThreeExceptions();
    }
);

echo "result is: {$result}\n";
