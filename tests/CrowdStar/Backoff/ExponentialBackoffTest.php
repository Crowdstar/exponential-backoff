<?php

namespace CrowdStar\Tests\Backoff;

use Closure;
use CrowdStar\Backoff\CustomizedCondition;
use CrowdStar\Backoff\EmptyValueCondition;
use CrowdStar\Backoff\ExceptionCondition;
use CrowdStar\Backoff\ExponentialBackoff;
use Exception;
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
    public function dataFailuresWithEmptyValue(): array
    {
        $helper = new Helper();
        return [
            [
                $helper,
                new ExponentialBackoff(new EmptyValueCondition()),
                function () use ($helper) {
                    return $helper->getValueAfterThreeEmptyReturnValues();
                },
                'fetch a non-empty value after 3 failed attempts where empty values were returned.',
            ],
            [
                $helper,
                new ExponentialBackoff(new ExceptionCondition()),
                function () use ($helper) {
                    return $helper->getValueAfterThreeExceptions();
                },
                'fetch a value after 3 failed attempts where exceptions were thrown out.',
            ],
            [
                $helper,
                new ExponentialBackoff(
                    new CustomizedCondition(
                        function ($result, ?Exception $e) use ($helper): bool {
                            return $helper->isLessThan3();
                        }
                    )
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
     * @param Helper $helper
     * @param ExponentialBackoff $backoff
     * @param Closure $c
     * @param string $message
     * @throws \CrowdStar\Backoff\Exception
     */
    public function testFailuresWithEmptyValue(Helper $helper, ExponentialBackoff $backoff, Closure $c, string $message)
    {
        $helper->reset();
        $this->assertSame(0, $helper->getCurrentIteration(), 'current iteration should be 0 (not yet started)');
        $this->assertSame($helper->getValue(), $backoff->run($c), $message);
        $this->assertSame(4, $helper->getCurrentIteration(), 'current iteration should be 4 (after 4 attempts)');
    }

    /**
     * @return array
     */
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
     * @param int $expectedMin
     * @param int $expectedMax
     * @param int $iteration
     * @param int $initialTimeout
     * @return void
     * @throws ReflectionException
     */
    public function testGetTimeoutMicroseconds(
        int $expectedMin,
        int $expectedMax,
        int $iteration,
        int $initialTimeout
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

        return $method->invokeArgs(new ExponentialBackoff(new EmptyValueCondition()), [$iteration, $initialTimeout]);
    }
}
