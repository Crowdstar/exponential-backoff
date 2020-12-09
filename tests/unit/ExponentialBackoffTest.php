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
    public function dataSuccessfulRetries(): array
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
     * @dataProvider dataSuccessfulRetries
     * @covers \CrowdStar\Backoff\ExponentialBackoff::run()
     */
    public function testSuccessfulRetries(Helper $helper, ExponentialBackoff $backoff, Closure $c, string $message)
    {
        $helper->reset();
        $this->assertSame(1, $backoff->getCurrentAttempts(), 'current iteration should be 1 (not yet started)');
        $this->assertSame($helper->getValue(), $backoff->run($c), $message);
        $this->assertSame(4, $backoff->getCurrentAttempts(), 'current iteration should be 4 (after 4 attempts)');
    }

    public function dataDelays(): array
    {
        // We add 0.2 seconds to the total execution time in each test, assuming that the rest part of the test won't
        // take more than 0.2 seconds to finish.
        return [
            [
                (new ExponentialBackoff(new EmptyValueCondition()))->setMaxAttempts(1),
                0.00,
                0.20,
                'It takes no time to do exponential backoff with maximum # of attempts "1".',
            ],
            [
                (new ExponentialBackoff(new EmptyValueCondition()))->setMaxAttempts(2),
                0.25,
                0.40,
                'It takes barely over 0.25 second to do exponential backoff with maximum # of attempts "2".',
            ],
            [
                (new ExponentialBackoff(new EmptyValueCondition()))->setMaxAttempts(3),
                0.75,
                0.95,
                'It takes barely over 0.75 second to do exponential backoff with maximum # of attempts "3".',
            ],
            [
                new ExponentialBackoff(new EmptyValueCondition()),
                1.75,
                1.95,
                'It takes barely over 1.75 seconds to do exponential backoff with a default maximum # of attempts "4".',
            ],

            [
                (new ExponentialBackoff(new EmptyValueCondition()))
                    ->setType(ExponentialBackoff::TYPE_SECONDS)
                    ->setMaxAttempts(1),
                0.0,
                0.2,
                'It takes no time to do exponential backoff with maximum # of attempts "1".',
            ],
            [
                (new ExponentialBackoff(new EmptyValueCondition()))
                    ->setType(ExponentialBackoff::TYPE_SECONDS)
                    ->setMaxAttempts(2),
                1.0,
                1.2,
                'It takes barely over 1 second to do exponential backoff with maximum # of attempts "2".',
            ],
            [
                (new ExponentialBackoff(new EmptyValueCondition()))
                    ->setType(ExponentialBackoff::TYPE_SECONDS)
                    ->setMaxAttempts(3),
                3.0,
                3.2,
                'It takes barely over 3 seconds to do exponential backoff with maximum # of attempts "3".',
            ],
            [
                (new ExponentialBackoff(new EmptyValueCondition()))->setType(ExponentialBackoff::TYPE_SECONDS),
                7.0,
                7.2,
                'It takes barely over 7 seconds to do exponential backoff with a default maximum # of attempts "4".',
            ],
        ];
    }

    /**
     * @dataProvider dataDelays
     * @covers \CrowdStar\Backoff\ExponentialBackoff::run()
     * @covers \CrowdStar\Backoff\ExponentialBackoff::getTimeoutSeconds()
     * @covers \CrowdStar\Backoff\ExponentialBackoff::getTimeoutMicroseconds()
     */
    public function testDelays(ExponentialBackoff $backoff, float $expectedMin, float $expectedMax, string $message)
    {
        $helper = new Helper();
        $start = microtime(true);
        $backoff->run(
            function () use ($helper) {
                return $helper->getValueAfterExpectedNumberOfFailedAttemptsWithEmptyReturnValuesReturned();
            }
        );
        $end = microtime(true);

        self::assertThat(
            ($end - $start),
            self::logicalAnd(
                self::greaterThanOrEqual($expectedMin),
                self::lessThanOrEqual($expectedMax)
            ),
            $message
        );
    }

    public function dataGetTimeoutSeconds(): array
    {
        // Test data to help to understand how timeouts are calculated, with input data in following order:
        //     ($expectedMin, $expectedMax, $iteration, $initialTimeout)
        $data = [
            [(50 *  1), ((50 *  1) + ((50 *  1) / 10)), 1, 50],
            [(60 *  2), ((60 *  2) + ((60 *  2) / 10)), 2, 60],
            [(70 *  4), ((70 *  4) + ((70 *  4) / 10)), 3, 70],

            // Exactly same input data as above 3 ones, just to help to understand the timeouts better.
            [ 50,  55, 1, 50],
            [120, 132, 2, 60],
            [280, 308, 3, 70],
        ];

        // Since we are testing methods with random output, repeat tests on same data for 20 (4 * 5) times.
        $data = array_merge($data, $data, $data, $data);
        $data = array_merge($data, $data, $data, $data, $data);

        return $data;
    }

    /**
     * @dataProvider dataGetTimeoutSeconds
     * @covers \CrowdStar\Backoff\ExponentialBackoff::getTimeoutSeconds
     */
    public function testGetTimeoutSeconds(int $expectedMin, int $expectedMax, int $iteration, int $initialTimeout)
    {
        self::assertThat(
            ExponentialBackoff::getTimeoutSeconds($iteration, $initialTimeout),
            self::logicalAnd(
                self::greaterThanOrEqual($expectedMin),
                self::lessThanOrEqual($expectedMax)
            ),
            sprintf(
                'For round #%d with initial timeout %d, expected timeout should be between %d and %d.',
                $iteration,
                $initialTimeout,
                $expectedMin,
                $expectedMax
            )
        );
    }

    public function dataGetTimeoutMicroseconds(): array
    {
        // Test data to help to understand how timeouts are calculated, with input data in following order:
        //     ($expectedMin, $expectedMax, $iteration, $initialTimeout)
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
        self::assertThat(
            ExponentialBackoff::getTimeoutMicroseconds($iteration, $initialTimeout),
            self::logicalAnd(
                self::greaterThanOrEqual($expectedMin),
                self::lessThanOrEqual($expectedMax)
            ),
            sprintf(
                'For round #%d with initial timeout %d, expected timeout should be between %d and %d.',
                $iteration,
                $initialTimeout,
                $expectedMin,
                $expectedMax
            )
        );
    }
}
