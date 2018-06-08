<?php

namespace CrowdStar\Tests\Backoff;

use CrowdStar\Backoff\ExponentialBackoff;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;

/**
 * Class ExponentialBackoffTest
 *
 * @package CrowdStar\Tests\Backoff
 */
class ExponentialBackoffTest extends TestCase
{
    /**
     * @return array
     */
    public function dataGetTimeoutMicroseconds(): array
    {
        // Test data to help to understand how timeouts are calculated, with input data in following order:
        //     ($iteration, $initial_timeout, $expectedMin, $expectedMax)
        $simpleData = array(
            array(1, 50, (50 *  1), ((50 *  1) + ((50 *  1) / 10))),
            array(2, 60, (60 *  2), ((60 *  2) + ((60 *  2) / 10))),
            array(3, 70, (70 *  4), ((70 *  4) + ((70 *  4) / 10))),

            // Exactly same input data as above 3 ones, just to help to understand the timeouts better.
            array(1, 50,  50,  55),
            array(2, 60, 120, 132),
            array(3, 70, 280, 308),
        );

        // Test data for simunating actual application timeouts.
        $data = array(
            array(1, 250000, (250000 *  1), ((250000 *  1) + ((250000 *  1) / 10))),
            array(2, 300000, (300000 *  2), ((300000 *  2) + ((300000 *  2) / 10))),
            array(3, 350000, (350000 *  4), ((350000 *  4) + ((350000 *  4) / 10))),
            array(4, 400000, (400000 *  8), ((400000 *  8) + ((400000 *  8) / 10))),
            array(5, 450000, (450000 * 16), ((450000 * 16) + ((450000 * 16) / 10))),

            // Exactly same input data as above 5 ones, just to help to understand the timeouts better.
            array(1, 250000,  250000,  275000),
            array(2, 300000,  600000,  660000),
            array(3, 350000, 1400000, 1540000),
            array(4, 400000, 3200000, 3520000),
            array(5, 450000, 7200000, 7920000),
        );

        // Since we are testing methods with random output, repeat tests on same data for 20 (2 * 2 * 5) times.
        $data = array_merge($data, $data);
        $data = array_merge($data, $data, $data, $data, $data);

        return array_merge($simpleData, $data);
    }

    /**
     * @dataProvider dataGetTimeoutMicroseconds
     * @covers \CrowdStar\Backoff\ExponentialBackoff::getTimeoutMicroseconds
     * @param int $iteration
     * @param int $initialTimeout
     * @param int $expectedMin
     * @param int $expectedMax
     * @return void
     * @throws ReflectionException
     */
    public function testGetTimeoutMicroseconds(
        int $iteration,
        int $initialTimeout,
        int $expectedMin,
        int $expectedMax
    ): void {
        $timeout = $this->getTimeoutMicroseconds($iteration, $initialTimeout);

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

    /**
     * @param int $iteration
     * @param int $initialTimeout
     * @return int
     * @throws ReflectionException
     * @see ExponentialBackoff::getTimeoutMicroseconds()
     */
    protected function getTimeoutMicroseconds(int $iteration, int $initialTimeout): int
    {
        $class  = new ReflectionClass(ExponentialBackoff::class);
        $method = $class->getMethod('getTimeoutMicroseconds');
        $method->setAccessible(true);

        return $method->invokeArgs(new ExponentialBackoff(), [$iteration, $initialTimeout]);
    }
}
