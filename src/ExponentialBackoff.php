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

    /**
     * How much randomness to mix into a timeout. Waiting anywhere between nothing and the full timeout spreads
     * clients out best; see CrowdStar\Backoff\Jitter for the alternatives.
     */
    public const DEFAULT_JITTER = Jitter::Full;

    protected int $initialTimeout = self::DEFAULT_INITIAL_TIMEOUT;

    protected Jitter $jitter = self::DEFAULT_JITTER;

    /**
     * The mode the caller asked for, or NULL to work it out per wait. Never read this directly; $this->getSapi()
     * answers which mode a wait would actually happen in.
     */
    protected ?Sapi $sapi;

    protected int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS;

    protected int $maxTimeout = self::DEFAULT_MAX_TIMEOUT;

    protected AbstractRetryCondition $retryCondition;

    /**
     * @param ?Sapi $sapi how to sleep between attempts; worked out per wait when NULL.
     */
    public function __construct(AbstractRetryCondition $retryCondition, ?Sapi $sapi = null)
    {
        $this->sapi = $sapi;

        $this->setRetryCondition($retryCondition);
    }

    /**
     * Build a backoff that retries for as long as given closure says so, without having to write a condition class:
     *
     *     ExponentialBackoff::when(fn (mixed $result): bool => empty($result))->run($c);
     *
     * @param Closure(mixed, ?\Exception): bool $callback  return TRUE from this to attempt the call again.
     * @param bool                              $throwable whether to throw an exception the last attempt was left with.
     */
    public static function when(Closure $callback, bool $throwable = true, ?Sapi $sapi = null): self
    {
        return new self(new CallbackCondition($callback, $throwable), $sapi);
    }

    /**
     * The attempt counter lives here rather than on the object, so that one instance can be handed to several callers
     * at once -- concurrent coroutines, or a closure that calls run() again -- without them counting over each other.
     *
     * @throws Exception
     */
    public function run(Closure $c, mixed ...$params): mixed
    {
        $attempt = 1;

        do {
            $result = $e = null;

            try {
                $result = $c(...$params);
            } catch (\Exception $e) {
                // Nothing to process here.
            }
        } while ($this->retry($result, $e, $attempt++));

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

    public function getInitialTimeout(): int
    {
        return $this->initialTimeout;
    }

    /**
     * Set how long to wait before the first retry; every timeout after that doubles it, up to the maximum.
     *
     * @param int $initialTimeout the initial timeout in microseconds. Pass 1000000 for one second.
     * @throws Exception
     */
    public function setInitialTimeout(int $initialTimeout): self
    {
        if ($initialTimeout < 1) {
            throw new Exception('initial timeout must be at least 1 microsecond');
        }

        $this->initialTimeout = $initialTimeout;

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
     * Which mode a wait would happen in right now: non-blocking inside a Swoole coroutine, blocking anywhere else.
     *
     * This is worked out per call rather than once at construction, because the same instance may well be used both
     * inside and outside coroutines -- a service built during bootstrap and then used by coroutines, for one.
     */
    public function getSapi(): Sapi
    {
        return $this->sleepsInCoroutine() ? Sapi::Swoole : Sapi::Default;
    }

    public function getJitter(): Jitter
    {
        return $this->jitter;
    }

    public function setJitter(Jitter $jitter): self
    {
        $this->jitter = $jitter;

        return $this;
    }

    public function getMaxTimeout(): int
    {
        return $this->maxTimeout;
    }

    /**
     * Cap how long a single timeout may grow to.
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
     * Get the next timeout in microseconds.
     *
     * The timeout doubles on every iteration until it reaches $maxTimeout, where it stays. Iterations below 1 are
     * treated as the first one. Randomness is applied to the capped timeout, so a Jitter::None timeout never exceeds
     * $maxTimeout while a Jitter::Equal one may exceed it by nothing at all and a Jitter::Full one stays below it.
     */
    public static function getTimeoutMicroseconds(
        int $iteration,
        int $initialTimeout = self::DEFAULT_INITIAL_TIMEOUT,
        int $maxTimeout = self::DEFAULT_MAX_TIMEOUT,
        Jitter $jitter = self::DEFAULT_JITTER
    ): int {
        // Leave room for the arithmetic below, so that no input can make it overflow to a float.
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

        // We throw in some randomness here to try to prevent connections from colliding. It is applied to the capped
        // timeout, the way https://aws.amazon.com/blogs/architecture/exponential-backoff-and-jitter/ does it: capping
        // afterwards would make every timeout past the cap exactly equal, putting the clients it spread out back in
        // step with each other.
        return match ($jitter) {
            Jitter::None  => $timeout,
            Jitter::Full  => random_int(0, $timeout),
            Jitter::Equal => intdiv($timeout, 2) + random_int(0, $timeout - intdiv($timeout, 2)),
        };
    }

    protected function retry(mixed $result, ?\Exception $e, int $attempt): bool
    {
        if (!$this->getRetryCondition()->shouldRetry($result, $e)) {
            return false;
        }

        if ($attempt >= $this->getMaxAttempts()) {
            return false;
        }

        $this->sleep($attempt);

        return true;
    }

    protected function sleep(int $attempt): void
    {
        $microSeconds = self::getTimeoutMicroseconds(
            $attempt,
            $this->getInitialTimeout(),
            $this->getMaxTimeout(),
            $this->getJitter()
        );

        if ($this->sleepsInCoroutine()) {
            // Minimum execution delay in Swoole is 1ms.
            Coroutine::sleep(max($microSeconds / 1_000_000, 0.001));
        } else {
            // ponytail: PHP implements usleep() via nanosleep(), so multi-second waits are fine here.
            usleep($microSeconds);
        }
    }

    protected function sleepsInCoroutine(): bool
    {
        if ($this->sapi === Sapi::Default) {
            return false; // Blocking mode was asked for.
        }

        // Whether Sapi::Swoole was asked for or is being worked out here, it only holds inside a coroutine created by
        // Swoole: method Coroutine::sleep() raises a Swoole\Error anywhere else, which is not something a library
        // whose job is to absorb failures should let happen.
        return extension_loaded('swoole') && (Coroutine::getPcid() !== false);
    }
}
