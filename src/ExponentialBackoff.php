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

namespace CrowdStar\Backoff;

use Closure;
use Swoole\Coroutine;

/**
 * Class ExponentialBackoff
 *
 * This class uses an exponential back-off algorithm to calculate the timeout for the next request. Exponential
 * back-offs prevent overloading an unavailable service by doubling the timeout each iteration.
 */
class ExponentialBackoff
{
    public const DEFAULT_MAX_ATTEMPTS = 4;

    /**
     * @deprecated Removed in 4.0, where every delay is in microseconds. Use ExponentialBackoff::setInitialDelay().
     * @see ExponentialBackoff::setType()
     */
    public const TYPE_MICROSECONDS = 1;

    /**
     * @deprecated Removed in 4.0, where every delay is in microseconds. Use ExponentialBackoff::setInitialDelay().
     * @see ExponentialBackoff::setType()
     */
    public const TYPE_SECONDS      = 2;

    /**
     * @deprecated Replaced in 4.0 by case Mode::Blocking of enum CrowdStar\Backoff\Mode.
     */
    protected const SAPI_DEFAULT = 1;

    /**
     * @deprecated Replaced in 4.0 by case Mode::Swoole of enum CrowdStar\Backoff\Mode.
     */
    protected const SAPI_SWOOLE  = 2;

    /**
     * @var int
     * @deprecated Removed in 4.0 along with the two TYPE_* constants it holds.
     */
    protected $type = self::TYPE_MICROSECONDS;

    /**
     * The mode resolved when this instance was built. Kept for backward compatibility only: waits no longer read it,
     * because whether a Swoole coroutine is there to yield to is a question about the moment of the wait rather than
     * about the moment of construction.
     *
     * @var int
     * @deprecated Reports the mode as it was at construction time, which a wait may well not happen in. Removed in
     * 4.0, where ExponentialBackoff::getMode() answers the question per wait.
     * @see ExponentialBackoff::getEffectiveSapi()
     * @see ExponentialBackoff::SAPI_DEFAULT
     * @see ExponentialBackoff::SAPI_SWOOLE
     */
    protected $sapi;

    /**
     * What the caller asked for, rather than what was made of it: 0 when the mode is left to be worked out per wait.
     *
     * @var int
     * @deprecated Removed in 4.0, where protected property $mode holds the requested mode.
     */
    protected $sapiRequested = 0;

    /**
     * @var int
     */
    protected $maxAttempts = self::DEFAULT_MAX_ATTEMPTS;

    /**
     * @var int
     * @deprecated Removed in 4.0, where the attempt counter is local to a run instead of living on the instance.
     * @see ExponentialBackoff::getCurrentAttempts()
     */
    protected $currentAttempts = 1;

    /**
     * @var AbstractRetryCondition
     */
    protected $retryCondition;

    /**
     * The second parameter becomes a nullable CrowdStar\Backoff\Mode in 4.0, so an integer passed here has to become
     * Mode::Blocking or Mode::Swoole on the way over. Note that passing nothing keeps meaning the same thing in both:
     * work the mode out rather than fix it.
     *
     * @throws Exception
     */
    public function __construct(AbstractRetryCondition $retryCondition, int $sapi = 0)
    {
        if ($sapi !== 0) {
            if (($sapi !== self::SAPI_DEFAULT) && ($sapi !== self::SAPI_SWOOLE)) {
                throw new Exception(sprintf('Second parameter $sapi must be either %s::SAPI_DEFAULT or %s::SAPI_SWOOLE.', self::class, self::class));
            }
            $this->sapi = $sapi;
        } elseif (extension_loaded('swoole') && (Coroutine::getPcid() !== false)) {
            $this->sapi = self::SAPI_SWOOLE; // If running inside a coroutine created by Swoole.
        } else {
            $this->sapi = self::SAPI_DEFAULT;
        }

        $this->sapiRequested = $sapi;

        $this->setRetryCondition($retryCondition);
    }

    /**
     * Note that the attempt counter lives on the instance here, so one instance cannot be run by two callers at once
     * -- concurrent coroutines, or a closure that calls run() again -- without them counting over each other. Give
     * each caller its own instance. 4.0 keeps the counter local to a run and has no such limitation.
     *
     * @return mixed
     * @throws Exception
     */
    public function run(Closure $c, ...$params) // @phpstan-ignore-line
    {
        $this->currentAttempts = 1; // Force to reset # of current attempts.

        do {
            $result = $e = null;

            try {
                $result = $c(...$params);
            } catch (\Exception $e) {
                // Nothing to process here.
            }
        } while ($this->retry($result, $e));

        // If you still have an exception, throw it out if needed.
        if (!empty($e) && $this->getRetryCondition()->throwable()) {
            throw $e;
        }

        return $result;
    }

    /**
     * @throws Exception
     */
    public function disable(): self
    {
        return $this->setMaxAttempts(1);
    }

    /**
     * @deprecated Removed in 4.0, where every delay is in microseconds.
     */
    public function getType(): int
    {
        return $this->type;
    }

    /**
     * @deprecated Removed in 4.0, where every delay is in microseconds. Instead of switching to TYPE_SECONDS, set the
     * length you want directly with ExponentialBackoff::setInitialDelay() -- setType(TYPE_SECONDS) is the same as an
     * initial delay of 1000000 microseconds.
     */
    public function setType(int $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }

    /**
     * @throws Exception
     */
    public function setMaxAttempts(int $maxAttempts): self
    {
        if ($maxAttempts < 1) {
            throw new Exception('maximum number of allowed attempts must be at least 1');
        }

        $this->maxAttempts = $maxAttempts;

        return $this;
    }

    /**
     * @deprecated Removed in 4.0, which has no replacement: the attempt counter is local to a run there, so that one
     * instance can be run by several callers at once. Count the attempts in the closure you hand to
     * ExponentialBackoff::run() if you need the number.
     */
    public function getCurrentAttempts(): int
    {
        return $this->currentAttempts;
    }

    public function getRetryCondition(): AbstractRetryCondition
    {
        return $this->retryCondition;
    }

    public function setRetryCondition(AbstractRetryCondition $retryCondition): self
    {
        $this->retryCondition = $retryCondition;

        return $this;
    }

    /**
     * Get the next timeout in seconds.
     *
     * @deprecated Removed in 4.0, where every delay is in microseconds. Pass the seconds you want as microseconds to
     * ExponentialBackoff::getDelayMicroseconds() instead, and divide what comes back by 1000000 if you need seconds.
     */
    public static function getTimeoutSeconds(int $iteration, int $initialTimeout = 1): int
    {
        // Clamped before the multiplication rather than after it, by when a large enough value has already left integer
        // range and turned into a float that this method's own int parameter would reject.
        $initialTimeout = min(max(0, $initialTimeout), intdiv(PHP_INT_MAX, 1000000));

        return intdiv(self::getTimeoutMicroseconds($iteration, $initialTimeout * 1000000), 1000000);
    }

    /**
     * Get the next timeout in microseconds.
     *
     * The timeout doubles on every iteration, up to a ceiling that leaves room for the randomness added afterwards.
     * Iterations below 1 are treated as the first one, and a timeout of nothing stays nothing.
     *
     * @deprecated Renamed to ExponentialBackoff::getDelayMicroseconds() in 4.0, which takes the cap and the kind of
     * randomness as two further parameters.
     */
    public static function getTimeoutMicroseconds(int $iteration, int $initialTimeout = 250000): int
    {
        // A tenth of the timeout is added below, so a timeout of ten elevenths of the maximum is as high as the sum can
        // go without leaving integer range.
        $ceiling = intdiv(PHP_INT_MAX, 11) * 10;
        $timeout = min(max(0, $initialTimeout), $ceiling);

        // Doubling in a loop that stops at the ceiling, rather than shifting by ($iteration - 1), keeps the timeout
        // inside integer range whatever it is handed. The shift broke down in three ways once enough attempts were
        // configured, all of them reachable through setMaxAttempts() and none of them catchable by run(), which handles
        // exceptions rather than errors: from the 46th iteration on the timeout no longer fit in an integer and this
        // method threw a TypeError; from the 65th on, shifting by more than the width of an integer returned 0 and
        // disabled the backoff silently; and a non-positive iteration raised an ArithmeticError.
        //
        // A timeout of nothing is left alone: doubling it would not move it, and looping until $iteration ran out could
        // take millennia.
        for ($i = 1; ($i < $iteration) && ($timeout > 0); $i++) {
            if ($timeout > intdiv($ceiling, 2)) {
                $timeout = $ceiling;
                break;
            }

            $timeout *= 2;
        }

        // We throw in some randomness here to try to prevent connections from colliding
        return $timeout + random_int(0, intdiv($timeout, 10));
    }

    /**
     * @deprecated Removed in 4.0 along with the attempt counter it increments.
     */
    protected function increaseCurrentAttempts(): self
    {
        $this->currentAttempts++;

        return $this;
    }

    /**
     * Which mode a wait would happen in right now: what the caller asked for when it asked for anything, otherwise
     * SAPI_SWOOLE inside a Swoole coroutine and SAPI_DEFAULT anywhere else.
     *
     * Worked out per wait rather than once in the constructor, because the same instance may well be used both inside
     * and outside coroutines -- a service built during bootstrap and then shared with coroutines, which is how a Swoole
     * application is usually wired up. Settled at construction, such an instance blocked for the rest of its life.
     *
     * Note that Coroutine::getPcid() answers FALSE only outside a coroutine; inside the outermost one it answers -1,
     * having no parent to name.
     *
     * @deprecated Renamed to ExponentialBackoff::getMode() in 4.0, and public there.
     */
    protected function getEffectiveSapi(): int
    {
        if ($this->sapiRequested !== 0) {
            return $this->sapiRequested;
        }

        return (extension_loaded('swoole') && (Coroutine::getPcid() !== false)) ? self::SAPI_SWOOLE : self::SAPI_DEFAULT;
    }

    /**
     * @param mixed $result
     * @throws Exception
     * @deprecated Kept, but its signature changes in 4.0: it takes the attempt number and the start of the run as two
     * further parameters, and asks the retry condition the opposite question.
     */
    protected function retry($result, ?\Exception $e): bool
    {
        if ($this->getRetryCondition()->met($result, $e)) {
            return false;
        }

        if ($this->currentAttempts >= $this->getMaxAttempts()) {
            return false;
        }

        $this->sleep();

        return true;
    }

    /**
     * @throws Exception
     * @deprecated Kept, but its signature changes in 4.0: it takes the wait in microseconds as a parameter and returns
     * nothing.
     */
    protected function sleep(): self
    {
        // Asked per wait rather than read off the instance, so that an instance shared between coroutines and plain
        // code waits the right way in each.
        $sapi = $this->getEffectiveSapi();

        switch ($this->getType()) {
            case self::TYPE_MICROSECONDS:
                $microSeconds = self::getTimeoutMicroseconds($this->currentAttempts);
                switch ($sapi) {
                    case self::SAPI_SWOOLE:
                        // Minimum execution delay in Swoole is 1ms.
                        Coroutine::sleep(max($microSeconds / 1000000, 0.001));
                        break;
                    default:
                        usleep($microSeconds);
                        break;
                }
                break;
            case self::TYPE_SECONDS:
                $seconds = self::getTimeoutSeconds($this->currentAttempts);
                switch ($sapi) {
                    case self::SAPI_SWOOLE:
                        // Minimum execution delay in Swoole is 1ms.
                        Coroutine::sleep(max($seconds, 0.001));
                        break;
                    default:
                        sleep($seconds);
                        break;
                }
                break;
            default:
                throw new Exception("invalid backoff type '{$this->getType()}'");
        }

        return $this->increaseCurrentAttempts();
    }
}
