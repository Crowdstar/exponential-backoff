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

    protected Type $type = Type::Microseconds;

    protected readonly Sapi $sapi;

    protected int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS;

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
     */
    public static function getTimeoutSeconds(int $iteration, int $initialTimeout = 1): int
    {
        return (int) (self::getTimeoutMicroseconds($iteration, $initialTimeout * 1000000) / 1000000);
    }

    /**
     * Get the next timeout in microseconds.
     */
    public static function getTimeoutMicroseconds(int $iteration, int $initialTimeout = 250000): int
    {
        $timeout = $initialTimeout * (1 << --$iteration);

        // We throw in some randomness here to try to prevent connections from colliding
        return $timeout + random_int(0, intdiv($timeout, 10));
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
            Type::Microseconds => self::getTimeoutMicroseconds($this->currentAttempts),
            Type::Seconds      => self::getTimeoutSeconds($this->currentAttempts) * 1000000,
        };

        if ($this->sapi === Sapi::Swoole) {
            // Minimum execution delay in Swoole is 1ms.
            Coroutine::sleep(max($microSeconds / 1000000, 0.001));
        } else {
            // ponytail: usleep() covers both units; PHP implements it via nanosleep(), so multi-second waits are fine.
            usleep($microSeconds);
        }

        return $this->increaseCurrentAttempts();
    }
}
