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

use BadFunctionCallException;
use BadMethodCallException;
use CrowdStar\Backoff\ExceptionBasedCondition;
use CrowdStar\Backoff\ExponentialBackoff;
use Error;
use Exception;
use LogicException;
use PHPUnit\Framework\ExpectationFailedException;
use PHPUnit\Framework\TestCase;
use TypeError;

/**
 * Class ExceptionBasedCondition
 *
 * @package CrowdStar\Tests\Backoff
 */
class ExceptionBasedConditionTest extends TestCase
{
    public function dataSuccessfulRetries(): array
    {
        // @see http://php.net/manual/en/spl.exceptions.php SPL exceptions
        // Exception > LogicException > BadFunctionCallException > BadMethodCallException
        return [
            [
                Exception::class,
                Exception::class,
                'try to catch exception \Exception and get \Exception objects thrown out.',
            ],
            [
                LogicException::class,
                LogicException::class,
                'try to catch exception \LogicException and get \LogicException objects thrown out.',
            ],
            [
                BadFunctionCallException::class,
                BadFunctionCallException::class,
                'try to catch exception \BadFunctionCallException; get \BadFunctionCallException objects thrown out.',
            ],
            [
                BadMethodCallException::class,
                BadMethodCallException::class,
                'try to catch exception \BadMethodCallException and get \BadMethodCallException objects thrown out.',
            ],

            [
                Exception::class,
                LogicException::class,
                'try to catch exception \Exception and get \LogicException objects thrown out.',
            ],
            [
                Exception::class,
                BadFunctionCallException::class,
                'try to catch exception \Exception and get \BadFunctionCallException objects thrown out.',
            ],
            [
                Exception::class,
                BadMethodCallException::class,
                'try to catch exception \Exception and get \BadMethodCallException objects thrown out.',
            ],

            [
                LogicException::class,
                BadFunctionCallException::class,
                'try to catch exception \LogicException and get \BadFunctionCallException objects thrown out.',
            ],
            [
                LogicException::class,
                BadMethodCallException::class,
                'try to catch exception \LogicException and get \BadMethodCallException objects thrown out.',
            ],

            [
                BadFunctionCallException::class,
                BadMethodCallException::class,
                'try to catch exception \BadFunctionCallException and get \BadMethodCallException objects thrown out.',
            ],
        ];
    }

    /**
     * @dataProvider dataSuccessfulRetries
     * @covers \CrowdStar\Backoff\ExceptionBasedCondition::met()
     * @covers \CrowdStar\Backoff\ExponentialBackoff::run()
     */
    public function testSuccessfulRetries(string $exceptionToCatch, string $exceptionToThrow, string $message)
    {
        $maxAttempts = 3; // Three attempts are enough for verification purpose.
        foreach ([0, 1, 2] as $expectedFailedAttempts) {
            $helper  = (new Helper())
                ->setException($exceptionToThrow)
                ->setExpectedFailedAttempts($expectedFailedAttempts);
            $backoff = (new ExponentialBackoff(new ExceptionBasedCondition($exceptionToCatch)))
                ->setMaxAttempts($maxAttempts);

            self::assertSame(1, $backoff->getCurrentAttempts(), 'current iteration should be 1 (not yet started)');
            self::assertSame(
                $helper->getValue(),
                $backoff->run(
                    function () use ($helper) {
                        return $helper->getValueAfterExpectedNumberOfFailedAttemptsWithExceptionsThrownOut();
                    }
                ),
                $message
            );
            self::assertSame(
                $expectedFailedAttempts + 1,
                $backoff->getCurrentAttempts(),
                'total # of attempts made should be one time more than failed attempts'
            );
        }
    }

    public function dataUnsuccessfulRetries(): array
    {
        return [
            [1, 1, 'will fail 1 time  before getting a value back, but maximally only 1 time  allowed'],
            [2, 1, 'will fail 2 times before getting a value back, but maximally only 1 time  allowed'],
            [2, 2, 'will fail 2 times before getting a value back, but maximally only 2 times allowed'],
            [3, 1, 'will fail 3 times before getting a value back, but maximally only 1 time  allowed'],
            [3, 2, 'will fail 3 times before getting a value back, but maximally only 2 times allowed'],
            [3, 3, 'will fail 3 times before getting a value back, but maximally only 3 times allowed'],
        ];
    }

    /**
     * @dataProvider dataUnsuccessfulRetries
     * @covers \CrowdStar\Backoff\ExceptionBasedCondition::met()
     * @covers \CrowdStar\Backoff\ExponentialBackoff::run()
     */
    public function testUnsuccessfulRetries(int $expectedFailedAttempts, int $maxAttempts)
    {
        $e       = null;
        $helper  = (new Helper())->setException(Exception::class)->setExpectedFailedAttempts($expectedFailedAttempts);
        $backoff = (new ExponentialBackoff(new ExceptionBasedCondition(Exception::class)))
            ->setMaxAttempts($maxAttempts);

        self::assertSame(1, $backoff->getCurrentAttempts(), 'current iteration should be 1 (not yet started)');
        try {
            $backoff->run(
                function () use ($helper) {
                    return $helper->getValueAfterExpectedNumberOfFailedAttemptsWithExceptionsThrownOut();
                }
            );
        } catch (Exception $e) {
            // Nothing to do here. Exceptions will be evaluates in the finally block.
        } finally {
            self::assertInstanceOf(Exception::class, $e);
            self::assertSame('an exception thrown out from class \CrowdStar\Tests\Backoff\Helper', $e->getMessage());
            self::assertSame(
                $maxAttempts,
                $backoff->getCurrentAttempts(),
                'maximum number of allowed attempts have been made but all failed with exceptions thrown out'
            );
        }
    }

    public function dataSetException(): array
    {
        return [
            [
                Exception::class,
            ],
            [
                LogicException::class,
            ],
            [
                BadFunctionCallException::class,
            ],
            [
                BadMethodCallException::class,
            ],
            [
                ExpectationFailedException::class, // this one requires at least 1 parameter in the constructor method.
            ],
        ];
    }

    /**
     * @dataProvider dataSetException
     * @covers \CrowdStar\Backoff\ExceptionBasedCondition::setException()
     */
    public function testSetException(string $exception): void
    {
        self::assertSame($exception, (new ExceptionBasedCondition($exception))->getException());
    }

    /**
     * @return array
     */
    public function dataSetExceptionWithExceptions(): array
    {
        return [
            [
                'Exception class \CrowdStar\Backoff\a_non_existing_class_name not exists',
                '\CrowdStar\Backoff\a_non_existing_class_name',
            ],
            [
                'Error objects are not instance of class \Exception',
                Error::class,
            ],
            [
                'TypeError objects are not instance of class \Exception',
                TypeError::class,
            ],
        ];
    }

    /**
     * @dataProvider dataSetExceptionWithExceptions
     * @covers \CrowdStar\Backoff\ExceptionBasedCondition::setException()
     */
    public function testSetExceptionWithExceptions(string $expectedExceptionMessage, string $exception): void
    {
        $this->expectException(\CrowdStar\Backoff\Exception::class);
        $this->expectExceptionMessage($expectedExceptionMessage);

        new ExceptionBasedCondition($exception);
    }
}
