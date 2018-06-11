#!/usr/bin/env php
<?php
/**
 * Sample code to show what options are available when doing exponential backoff with the package.
 */

use CrowdStar\Backoff\AbstractRetryCondition;
use CrowdStar\Backoff\EmptyValueCondition;
use CrowdStar\Backoff\ExceptionBasedCondition;
use CrowdStar\Backoff\ExponentialBackoff;
use CrowdStar\Tests\Backoff\Helper;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$helper    = new Helper();
$condition = new class extends AbstractRetryCondition {
    public function met($result, ?Exception $e): bool
    {
        return $GLOBALS['helper']->reachExpectedAttempts();
    }
};

$backoff = new ExponentialBackoff(new EmptyValueCondition());
$backoff = new ExponentialBackoff(new ExceptionBasedCondition());
$backoff = new ExponentialBackoff(new ExceptionBasedCondition(Exception::class));
$backoff = new ExponentialBackoff($condition);

$backoff
    ->setType(ExponentialBackoff::TYPE_SECONDS)
    ->setType(ExponentialBackoff::TYPE_MICROSECONDS)
    ->setMaxAttempts(3)
    ->setMaxAttempts(4);

$result = $backoff->run(
    function () use ($helper) {
        return $helper->getValue();
    }
);

echo "result is: {$result}\n";
