#!/usr/bin/env php
<?php
/**************************************************************************
 * Copyright 2018 Glu Mobile Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *************************************************************************/

/**
 * Sample code to return some value back after few failed attempts, where each failed attempt throws an exception out.
 */

declare(strict_types=1);

use CrowdStar\Backoff\ExceptionBasedCondition;
use CrowdStar\Backoff\ExponentialBackoff;
use CrowdStar\Tests\Backoff\Helper;

require_once dirname(__DIR__) . '/vendor/autoload.php';

// You may omit the first parameter "Exception::class" since it's the default value when not passed in:
//     $backoff = new ExponentialBackoff(new ExceptionBasedCondition());
$backoff = new ExponentialBackoff(new ExceptionBasedCondition(Exception::class));
$helper  = (new Helper())->setException(Exception::class);
$result  = $backoff->run(
    function () use ($helper) {
        return $helper->getValueAfterExpectedNumberOfFailedAttemptsWithExceptionsThrownOut();
    }
);

echo "result is: {$result}\n";
