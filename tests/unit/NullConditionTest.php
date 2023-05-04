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

use Closure;
use CrowdStar\Backoff\EmptyValueCondition;
use CrowdStar\Backoff\ExponentialBackoff;
use CrowdStar\Backoff\NullCondition;
use Exception;
use PHPUnit\Framework\TestCase;

/**
 * Class NullConditionTest
 *
 * @package CrowdStar\Tests\Backoff
 */
class NullConditionTest extends TestCase
{
    public function dataSuccessfulRetries(): array
    {
        return [
            [
                0,
                4,
                new ExponentialBackoff(new NullCondition()),
                function () {
                    return 0;
                },
                'an empty value returned when exponential backoff disabled with a null condition.',
            ],
            [
                1,
                4,
                new ExponentialBackoff(new NullCondition()),
                function () {
                    return 1;
                },
                'a non-empty value returned when exponential backoff disabled with a null condition.',
            ],
            [
                0,
                1,
                (new ExponentialBackoff(new EmptyValueCondition()))->setMaxAttempts(1),
                function () {
                    return 0;
                },
                'an empty value returned when exponential backoff disabled by setting maximum # of attempts to 1.',
            ],
            [
                1,
                1,
                (new ExponentialBackoff(new EmptyValueCondition()))->setMaxAttempts(1),
                function () {
                    return 1;
                },
                'a non-empty value returned when exponential backoff disabled by setting maximum # of attempts to 1.',
            ],
            [
                0,
                1,
                (new ExponentialBackoff(new EmptyValueCondition()))->disable(),
                function () {
                    return 0;
                },
                'an empty value returned when exponential backoff disabled by calling method disable().',
            ],
            [
                1,
                1,
                (new ExponentialBackoff(new EmptyValueCondition()))->disable(),
                function () {
                    return 1;
                },
                'a non-empty value returned when exponential backoff disabled by calling method disable().',
            ],
        ];
    }

    /**
     * @dataProvider dataSuccessfulRetries
     * @covers \CrowdStar\Backoff\EmptyValueCondition
     * @covers \CrowdStar\Backoff\ExponentialBackoff::disable()
     * @covers \CrowdStar\Backoff\ExponentialBackoff::run()
     * @covers \CrowdStar\Backoff\NullCondition
     */
    public function testSuccessfulRetries(
        int $expectedValue,
        int $expectedMaxAttempts,
        ExponentialBackoff $backoff,
        Closure $c,
        string $message
    ) {
        self::assertSame(1, getCurrentAttempts($backoff), 'current iteration should be 1 (not yet started)');
        self::assertSame($expectedMaxAttempts, $backoff->getMaxAttempts(), 'check maximum number of allowed attempts');
        self::assertSame($expectedValue, $backoff->run($c), $message);
        self::assertSame(
            1,
            getCurrentAttempts($backoff),
            'current iteration should still be 1 after first attempt (no matter what has been returned)'
        );
    }

    public function dataUnsuccessfulRetries(): array
    {
        return [
            [
                4,
                new ExponentialBackoff(new NullCondition()),
                'exception thrown out when exponential backoff disabled with a null condition.',
            ],
            [
                1,
                (new ExponentialBackoff(new EmptyValueCondition()))->setMaxAttempts(1),
                'exception thrown out when exponential backoff disabled by setting maximum # of attempts to 1.',
            ],
            [
                1,
                (new ExponentialBackoff(new EmptyValueCondition()))->disable(),
                'exception thrown out when exponential backoff disabled by calling method disable().',
            ],
        ];
    }

    /**
     * @dataProvider dataUnsuccessfulRetries
     * @covers \CrowdStar\Backoff\ExceptionBasedCondition
     * @covers \CrowdStar\Backoff\ExponentialBackoff::disable()
     * @covers \CrowdStar\Backoff\ExponentialBackoff::run()
     * @covers \CrowdStar\Backoff\NullCondition
     */
    public function testUnsuccessfulRetries(int $expectedMaxAttempts, ExponentialBackoff $backoff)
    {
        self::assertSame(1, getCurrentAttempts($backoff), 'current iteration should be 1 (not yet started)');
        self::assertSame($expectedMaxAttempts, $backoff->getMaxAttempts(), 'check maximum number of allowed attempts');

        $e = null;
        try {
            $backoff->run(
                function () {
                    throw new Exception();
                }
            );
        } catch (Exception $e) {
            // Nothing to do here. Exceptions will be evaluated in the finally block.
        } finally {
            self::assertInstanceOf(Exception::class, $e);
            self::assertSame(
                1,
                getCurrentAttempts($backoff),
                'current iteration should still be 1 after first attempt (no matter what has been returned)'
            );
        }
    }
}
