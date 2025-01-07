<?php
/**
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
 */

declare(strict_types=1);

namespace CrowdStar\Tests\Backoff;

use CrowdStar\Backoff\AbstractRetryCondition;
use CrowdStar\Backoff\ExponentialBackoff;
use Exception;
use PHPUnit\Framework\TestCase;

/**
 * Class CustomizedConditionTest
 *
 * @internal
 * @coversNothing
 */
class CustomizedConditionTest extends TestCase
{
    protected const MAX_ATTEMPTS = ExponentialBackoff::DEFAULT_MAX_ATTEMPTS;

    /**
     * @return array<array{'maxAttempts': int, 'message': string}>
     */
    public function dataBackoff(): array
    {
        return [
            [
                'maxAttempts' => 1,
                'message'     => 'Maximum # of attempts is 1 (exponential backoff disabled)',
            ],
            [
                'maxAttempts' => 2,
                'message'     => 'Maximum # of attempts is 2',
            ],
            [
                'maxAttempts' => self::MAX_ATTEMPTS,
                'message'     => 'Maximum # of attempts is 4',
            ],
        ];
    }

    /**
     * The $backoff object in this test is the same as the one in the next method self::testUnthrowableException(),
     * except that method $backoff->throwable() returns TRUE.
     *
     * @dataProvider dataBackoff
     * @covers \CrowdStar\Backoff\AbstractRetryCondition::throwable()
     * @covers \CrowdStar\Backoff\ExponentialBackoff::run()
     */
    public function testThrowableException(int $maxAttempts): void
    {
        $helper = (new Helper())->setException(Exception::class)->setExpectedFailedAttempts(self::MAX_ATTEMPTS);

        $this->expectException(Exception::class); // Next function call will throw out an exception.
        $this->getBackoff($maxAttempts, false)->run(
            function () use ($helper) {
                return $helper->getValueAfterExpectedNumberOfFailedAttemptsWithExceptionsThrownOut();
            }
        );
    }

    /**
     * The $backoff object in this test is the same as the one in the previous method self::testThrowableException(),
     * except that method $backoff->throwable() returns FALSE.
     *
     * @dataProvider dataBackoff
     * @covers \CrowdStar\Backoff\AbstractRetryCondition::throwable()
     * @covers \CrowdStar\Backoff\ExponentialBackoff::run()
     */
    public function testUnthrowableException(int $maxAttempts): void
    {
        $helper = (new Helper())->setException(Exception::class)->setExpectedFailedAttempts(self::MAX_ATTEMPTS);
        $this->getBackoff($maxAttempts, true)->run(
            function () use ($helper) {
                return $helper->getValueAfterExpectedNumberOfFailedAttemptsWithExceptionsThrownOut();
            }
        );

        $this->addToAssertionCount(1); // Since there is no assertions in this test, we manually add the count by 1.
    }

    /**
     * @param bool $silenceWhenFailed To hide or throw out the exception when finally failed.
     */
    protected function getBackoff(int $maxAttempts, bool $silenceWhenFailed): ExponentialBackoff
    {
        $backoff = (new ExponentialBackoff(
            new class($silenceWhenFailed) extends AbstractRetryCondition {
                /** @var bool */
                protected $silenceWhenFailed;

                /** @var bool */
                protected $throwable = true;

                public function __construct(bool $silenceWhenFailed)
                {
                    $this->silenceWhenFailed = $silenceWhenFailed;
                }

                public function throwable(): bool
                {
                    // This tells the caller to hide or throw out the exception when finally failed.
                    return $this->throwable;
                }

                public function met($result, ?Exception $e): bool
                {
                    if ($e === null) {
                        return true;
                    }
                    $this->throwable = !$this->silenceWhenFailed;
                    return false;
                }
            }
        ));
        $backoff->setMaxAttempts($maxAttempts);

        return $backoff;
    }
}
