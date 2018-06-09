#!/usr/bin/env php
<?php
/**
 * Sample code to return a non-empty value back after 3 failed attempts, where each failed attempt returns FALSE while
 * calling the customized function.
 */

use CrowdStar\Backoff\CustomizedCondition;
use CrowdStar\Backoff\ExponentialBackoff;
use CrowdStar\Tests\Backoff\Helper;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$helper = new Helper();
// This object has a customized function to determine if a retry is needed or not. The customized function needs to
// return a boolean value back.
$backoff = new ExponentialBackoff(
    new CustomizedCondition(
        function ($result, ?Exception $e) use ($helper): bool {
            return $helper->isLessThan3();
        }
    )
);

$result = $backoff->run(
    function () use ($helper) {
        return $helper->getValue();
    }
);

echo "result is: {$result}\n";
