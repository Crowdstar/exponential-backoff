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
use CrowdStar\Backoff\AbstractRetryCondition;
use CrowdStar\Backoff\EmptyValueCondition;
use CrowdStar\Backoff\ExceptionBasedCondition;
use CrowdStar\Backoff\ExponentialBackoff;
use CrowdStar\Reflection\Reflection;
use Exception;
use PHPUnit\Framework\TestCase;

/**
 * Class ExponentialBackoffTest
 *
 * @package CrowdStar\Tests\Backoff
 */
class ExponentialBackoffTest extends TestCase
{
    public function dataFailuresWithEmptyValue(): array
    {
        $helper = (new Helper())->setException(Exception::class);
        return [
            [
                $helper,
                new ExponentialBackoff(new EmptyValueCondition()),
                function () use ($helper) {
                    return $helper->getValueAfterExpectedNumberOfFailedAttemptsWithEmptyReturnValuesReturned();
                },
                'fetch a non-empty value after 3 failed attempts where empty values were returned.',
            ],
            [
                $helper,
                new ExponentialBackoff(new ExceptionBasedCondition()),
                function () use ($helper) {
                    return $helper->getValueAfterExpectedNumberOfFailedAttemptsWithExceptionsThrownOut();
                },
                'fetch a value after 3 failed attempts where exceptions were thrown out.',
            ],
            [
                $helper,
                new ExponentialBackoff(
                    new class ($helper) extends AbstractRetryCondition {
                        protected $helper;
                        public function __construct(Helper $helper)
                        {
                            $this->helper = $helper;
                        }
                        public function met($result, ?Exception $e): bool
                        {
                            return $this->helper->reachExpectedAttempts();
                        }
                    }
                ),
                function () use ($helper) {
                    return $helper->getValue();
                },
                'fetch a value after 3 failed attempts through a self-defined retry function.',
            ],
        ];
    }

    /**
     * @dataProvider dataFailuresWithEmptyValue
     * @covers \CrowdStar\Backoff\ExponentialBackoff::run()
     */
    public function testFailuresWithEmptyValue(Helper $helper, ExponentialBackoff $backoff, Closure $c, string $message)
    {
        $helper->reset();
        $this->assertSame(1, $backoff->getCurrentAttempts(), 'current iteration should be 1 (not yet started)');
        $this->assertSame($helper->getValue(), $backoff->run($c), $message);
        $this->assertSame(4, $backoff->getCurrentAttempts(), 'current iteration should be 4 (after 4 attempts)');
    }

    public function dataGetTimeoutMicroseconds(): array
    {
        // Test data to help to understand how timeouts are calculated, with input data in following order:
        //     ($expectedMin, $expectedMax, $iteration, $initial_timeout)
        $simpleData = [
            [(50 *  1), ((50 *  1) + ((50 *  1) / 10)), 1, 50],
            [(60 *  2), ((60 *  2) + ((60 *  2) / 10)), 2, 60],
            [(70 *  4), ((70 *  4) + ((70 *  4) / 10)), 3, 70],

            // Exactly same input data as above 3 ones, just to help to understand the timeouts better.
            [ 50,  55, 1, 50],
            [120, 132, 2, 60],
            [280, 308, 3, 70],
        ];

        // Test data for simulating actual application timeouts.
        $data = [
            [(250000 *  1), ((250000 *  1) + ((250000 *  1) / 10)), 1, 250000],
            [(300000 *  2), ((300000 *  2) + ((300000 *  2) / 10)), 2, 300000],
            [(350000 *  4), ((350000 *  4) + ((350000 *  4) / 10)), 3, 350000],
            [(400000 *  8), ((400000 *  8) + ((400000 *  8) / 10)), 4, 400000],
            [(450000 * 16), ((450000 * 16) + ((450000 * 16) / 10)), 5, 450000],

            // Exactly same input data as above 5 ones, just to help to understand the timeouts better.
            [ 250000,  275000, 1, 250000],
            [ 600000,  660000, 2, 300000],
            [1400000, 1540000, 3, 350000],
            [3200000, 3520000, 4, 400000],
            [7200000, 7920000, 5, 450000],
        ];

        // Since we are testing methods with random output, repeat tests on same data for 20 (4 * 5) times.
        $data = array_merge($data, $data, $data, $data);
        $data = array_merge($data, $data, $data, $data, $data);

        return array_merge($simpleData, $data);
    }

    /**
     * @dataProvider dataGetTimeoutMicroseconds
     * @covers \CrowdStar\Backoff\ExponentialBackoff::getTimeoutMicroseconds
     */
    public function testGetTimeoutMicroseconds(
        int $expectedMin,
        int $expectedMax,
        int $iteration,
        int $initialTimeout
    ): void {
        $timeout = Reflection::callMethod(
            new ExponentialBackoff(new EmptyValueCondition()),
            'getTimeoutMicroseconds',
            [
                $iteration,
                $initialTimeout,
            ]
        );

        $this->assertGreaterThanOrEqual(
            $expectedMin,
            $timeout,
            sprintf(
                'For round #%d with initial timeout %d, expected timeout should be no less than %d.',
                $iteration,
                $initialTimeout,
                $expectedMin
            )
        );
        $this->assertLessThanOrEqual(
            $expectedMax,
            $timeout,
            sprintf(
                'For round #%d with initial timeout %d, expected timeout should be no greater than %d.',
                $iteration,
                $initialTimeout,
                $expectedMax
            )
        );
    }
}
