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

declare(strict_types=1);

namespace CrowdStar\Tests\Backoff;

use CrowdStar\Backoff\AbstractRetryCondition;
use CrowdStar\Backoff\ExponentialBackoff;
use Exception;
use PHPUnit\Framework\TestCase;

/**
 * Class CustomizedConditionTest
 *
 * @package CrowdStar\Tests\Backoff
 */
class CustomizedConditionTest extends TestCase
{
    /**
     * The $backoff object in this test is the same as the one in the next method self::testUnthrowableException(),
     * except that method $backoff->throwable() returns TRUE.
     *
     * @covers \CrowdStar\Backoff\AbstractRetryCondition::throwable()
     * @covers \CrowdStar\Backoff\ExponentialBackoff::run()
     */
    public function testThrowableException()
    {
        $helper  = (new Helper())->setException(Exception::class)->setExpectedFailedAttempts(4);
        $backoff = (new ExponentialBackoff(
            new class extends AbstractRetryCondition {
                public function throwable(): bool
                {
                    // This tells the caller to throw out the exception when finally failed.
                    return true;
                }
                public function met($result, ?Exception $e): bool
                {
                    return (empty($e) || (!($e instanceof Exception)));
                }
            }
        ));

        $this->expectException(Exception::class); // Next function call will through out an exception.
        $backoff->run(
            function () use ($helper) {
                return $helper->getValueAfterExpectedNumberOfFailedAttemptsWithExceptionsThrownOut();
            }
        );
    }

    /**
     * The $backoff object in this test is the same as the one in the previous method self::testThrowableException(),
     * except that method $backoff->throwable() returns FALSE.
     *
     * @covers \CrowdStar\Backoff\AbstractRetryCondition::throwable()
     * @covers \CrowdStar\Backoff\ExponentialBackoff::run()
     */
    public function testUnthrowableException()
    {
        $helper  = (new Helper())->setException(Exception::class)->setExpectedFailedAttempts(4);
        $backoff = (new ExponentialBackoff(
            new class extends AbstractRetryCondition {
                public function throwable(): bool
                {
                    // This tells the caller NOT to throw out the exception when finally failed.
                    return false;
                }
                public function met($result, ?Exception $e): bool
                {
                    return (empty($e) || (!($e instanceof Exception)));
                }
            }
        ));

        $this->addToAssertionCount(1); // Since there is no assertions in this test, we manually add the count by 1.
        $backoff->run(
            function () use ($helper) {
                return $helper->getValueAfterExpectedNumberOfFailedAttemptsWithExceptionsThrownOut();
            }
        );
    }
}
