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

use CrowdStar\Backoff\CallbackCondition;
use CrowdStar\Backoff\ExponentialBackoff;
use CrowdStar\Backoff\Jitter;
use Deminy\Counit\TestCase;
use Exception;
use RuntimeException;

/**
 * Class CallbackConditionTest
 *
 * @internal
 * @coversNothing
 */
class CallbackConditionTest extends TestCase
{
    /**
     * The closure gets what the closure under retry returned, or what it threw.
     *
     * @covers \CrowdStar\Backoff\CallbackCondition::shouldRetry()
     */
    public function testCallbackSeesResultAndException(): void
    {
        $seen      = [];
        $condition = new CallbackCondition(
            function (mixed $result, ?Exception $e) use (&$seen): bool {
                $seen[] = ($e === null) ? $result : $e->getMessage();

                return false;
            }
        );

        $condition->shouldRetry('a value', null);
        $condition->shouldRetry(null, new RuntimeException('an exception'));

        self::assertSame(['a value', 'an exception'], $seen);
    }

    /**
     * @covers \CrowdStar\Backoff\CallbackCondition::shouldRetry()
     * @covers \CrowdStar\Backoff\ExponentialBackoff::when()
     */
    public function testRetryUntilTheCallbackIsSatisfied(): void
    {
        $helper  = (new Helper())->setExpectedFailedAttempts(2);
        $backoff = ExponentialBackoff::when(fn (mixed $result): bool => empty($result))
            ->setJitter(Jitter::None)
            ->setMaxTimeout(1000)
        ;

        self::assertSame(
            $helper->getValue(),
            $backoff->run($helper->getValueAfterExpectedNumberOfFailedAttemptsWithEmptyReturnValuesReturned(...)),
            'the callback kept the run going until a value came back'
        );
        self::assertSame(3, $helper->getAttemptsMade());
    }

    /**
     * @covers \CrowdStar\Backoff\CallbackCondition::throwable()
     */
    public function testExceptionIsThrownWhenFinallyFailed(): void
    {
        $backoff = ExponentialBackoff::when(fn (): bool => true)->setMaxAttempts(1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('never works');
        $backoff->run(fn () => throw new RuntimeException('never works'));
    }

    /**
     * @covers \CrowdStar\Backoff\CallbackCondition::throwable()
     */
    public function testExceptionIsSilencedWhenAskedFor(): void
    {
        $backoff = ExponentialBackoff::when(fn (): bool => true, false)->setMaxAttempts(1);

        self::assertNull(
            $backoff->run(fn () => throw new RuntimeException('never works')),
            'the exception was swallowed, leaving nothing to return'
        );
    }

    /**
     * A subclass gets one of itself back, so that whatever it adds survives the call.
     *
     * @covers \CrowdStar\Backoff\ExponentialBackoff::when()
     */
    public function testSubclassGetsItsOwnTypeBack(): void
    {
        $backoff = new class(new CallbackCondition(fn (): bool => false)) extends ExponentialBackoff {};

        self::assertInstanceOf($backoff::class, $backoff::when(fn (): bool => false));
    }
}
