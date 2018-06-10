#!/usr/bin/env php
<?php
/**
 * Sample code to show how to define customized options when running exponential backoff.
 */

use CrowdStar\Backoff\CustomizedCondition;
use CrowdStar\Backoff\CustomizedConditionInterface;
use CrowdStar\Backoff\EmptyValueCondition;
use CrowdStar\Backoff\ExceptionCondition;
use CrowdStar\Backoff\ExponentialBackoff;
use CrowdStar\Tests\Backoff\Helper;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$helper    = new Helper();
$condition = new class implements CustomizedConditionInterface {
    public function met($result, ?Exception $e): bool
    {
        return $GLOBALS['helper']->reachExpectedAttempts();
    }
};

$backoff = new ExponentialBackoff(new EmptyValueCondition());
$backoff = new ExponentialBackoff(new ExceptionCondition());
$backoff = new ExponentialBackoff(new ExceptionCondition(Exception::class));
$backoff = new ExponentialBackoff(new CustomizedCondition($condition));

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
