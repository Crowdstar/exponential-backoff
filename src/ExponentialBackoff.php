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
     * The timeout before the first retry, in microseconds.
     */
    public const DEFAULT_INITIAL_TIMEOUT = 250_000;

    /**
     * How long a single timeout may grow to, in microseconds. Doubling is not capped by nature, and an uncapped
     * exponential grows past anything usable within a few attempts.
     */
    public const DEFAULT_MAX_TIMEOUT = 30_000_000;

    protected Type $type = Type::Microseconds;

    protected readonly Sapi $sapi;

    protected int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS;

    protected int $maxTimeout = self::DEFAULT_MAX_TIMEOUT;

    /**
     * Set by method $this->run() before the first attempt is made.
     */
    protected int $currentAttempts;

    protected AbstractRetryCondition $retryCondition;

    /**
     * @param ?Sapi $sapi how to sleep between attempts; autodetected when NULL.
     */
    public function __construct(AbstractRetryCondition $retryCondition, ?Sapi $sapi = null)
    {
        // Sleep in non-blocking mode only when running inside a coroutine created by Swoole.
        $this->sapi = $sapi ?? (
            (extension_loaded('swoole') && (Coroutine::getPcid() !== false)) ? Sapi::Swoole : Sapi::Default
        );

        $this->setRetryCondition($retryCondition);
    }

    /**
     * @throws Exception
     */
    public function run(Closure $c, mixed ...$params): mixed
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

    public function getType(): Type
    {
        return $this->type;
    }

    public function setType(Type $type): self
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

    public function getMaxTimeout(): int
    {
        return $this->maxTimeout;
    }

    /**
     * Cap how long a single timeout may grow to. In Type::Seconds mode the value is rounded down to whole seconds,
     * with one second as the minimum.
     *
     * @param int $maxTimeout the maximum timeout in microseconds.
     * @throws Exception
     */
    public function setMaxTimeout(int $maxTimeout): self
    {
        if ($maxTimeout < 1) {
            throw new Exception('maximum timeout must be at least 1 microsecond');
        }

        $this->maxTimeout = $maxTimeout;

        return $this;
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
     * @param ?int $maxTimeout the maximum timeout in seconds; self::DEFAULT_MAX_TIMEOUT when NULL.
     */
    public static function getTimeoutSeconds(int $iteration, int $initialTimeout = 1, ?int $maxTimeout = null): int
    {
        return (int) (
            self::getTimeoutMicroseconds(
                $iteration,
                self::toMicroseconds($initialTimeout),
                ($maxTimeout === null) ? self::DEFAULT_MAX_TIMEOUT : self::toMicroseconds($maxTimeout)
            ) / 1_000_000
        );
    }

    /**
     * Get the next timeout in microseconds.
     *
     * The timeout doubles on every iteration until it reaches $maxTimeout, where it stays. Iterations below 1 are
     * treated as the first one.
     */
    public static function getTimeoutMicroseconds(
        int $iteration,
        int $initialTimeout = self::DEFAULT_INITIAL_TIMEOUT,
        int $maxTimeout = self::DEFAULT_MAX_TIMEOUT
    ): int {
        // Leave room for the randomness added below, so that no input can make the arithmetic overflow to a float.
        $maxTimeout = min(max(0, $maxTimeout), intdiv(PHP_INT_MAX, 2));
        $timeout    = min(max(0, $initialTimeout), $maxTimeout);

        for ($i = 1; $i < $iteration; $i++) {
            // Doubling in a loop that stops at the cap, instead of shifting by ($iteration - 1), keeps the timeout
            // within integer range no matter how many attempts are configured.
            if ($timeout > intdiv($maxTimeout, 2)) {
                $timeout = $maxTimeout;
                break;
            }

            $timeout *= 2;
        }

        // We throw in some randomness here to try to prevent connections from colliding. The cap is applied before
        // the randomness, the way https://aws.amazon.com/blogs/architecture/exponential-backoff-and-jitter/ does it.
        return $timeout + random_int(0, intdiv($timeout, 10));
    }

    /**
     * Convert seconds to microseconds, saturating instead of overflowing on absurdly large input.
     */
    protected static function toMicroseconds(int $seconds): int
    {
        return ($seconds > intdiv(PHP_INT_MAX, 1_000_000)) ? PHP_INT_MAX : ($seconds * 1_000_000);
    }

    protected function increaseCurrentAttempts(): self
    {
        $this->currentAttempts++;

        return $this;
    }

    protected function retry(mixed $result, ?\Exception $e): bool
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

    protected function sleep(): self
    {
        $microSeconds = match ($this->getType()) {
            Type::Microseconds => self::getTimeoutMicroseconds(
                $this->currentAttempts,
                self::DEFAULT_INITIAL_TIMEOUT,
                $this->maxTimeout
            ),
            Type::Seconds => self::getTimeoutSeconds(
                $this->currentAttempts,
                1,
                max(1, intdiv($this->maxTimeout, 1_000_000))
            ) * 1_000_000,
        };

        if ($this->sapi === Sapi::Swoole) {
            // Minimum execution delay in Swoole is 1ms.
            Coroutine::sleep(max($microSeconds / 1_000_000, 0.001));
        } else {
            // ponytail: usleep() covers both units; PHP implements it via nanosleep(), so multi-second waits are fine.
            usleep($microSeconds);
        }

        return $this->increaseCurrentAttempts();
    }
}
