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
 *
 * @phpstan-consistent-constructor so that self::when() can build one of whatever subclass it was called on.
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
     * The mode the caller asked for, or NULL to work it out per wait. Never read this directly; $this->getMode()
     * answers which mode a wait would actually happen in.
     */
    protected ?Mode $mode;

    protected int $maxAttempts = self::DEFAULT_MAX_ATTEMPTS;

    protected int $maxTimeout = self::DEFAULT_MAX_TIMEOUT;

    protected ?int $maxElapsedTime = null;

    /** @var ?Closure(int): void */
    protected ?Closure $sleeper = null;

    protected AbstractRetryCondition $retryCondition;

    /**
     * A subclass that keeps this signature gets a working self::when(); one that does not has to override ::when() as
     * well, or set its own state up in a factory of its own.
     *
     * @param ?Mode $mode which primitive waits between attempts; worked out per wait when NULL.
     */
    public function __construct(AbstractRetryCondition $retryCondition, ?Mode $mode = null)
    {
        $this->mode = $mode;

        $this->setRetryCondition($retryCondition);
    }

    /**
     * Build a backoff that retries for as long as given closure says so, without having to write a condition class:
     *
     *     ExponentialBackoff::when(fn (mixed $result): bool => empty($result))->run($c);
     *
     * @param Closure(mixed, ?\Exception): bool $callback return TRUE from this to attempt the call again.
     * @param bool $throwable whether to throw an exception the last attempt was left with.
     */
    public static function when(Closure $callback, bool $throwable = true, ?Mode $mode = null): static
    {
        return new static(new CallbackCondition($callback, $throwable), $mode);
    }

    /**
     * The attempt counter lives here rather than on the object, so that one instance can be handed to several callers
     * at once -- concurrent coroutines, or a closure that calls run() again -- without them counting over each other.
     *
     * @throws Exception
     */
    public function run(Closure $c, mixed ...$params): mixed
    {
        $attempt   = 1;
        $startedAt = hrtime(true);

        do {
            $result = $e = null;

            try {
                $result = $c(...$params);
            } catch (\Exception $e) {
                // Nothing to process here.
            }
        } while ($this->retry($result, $e, $attempt++, $startedAt));

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
     * How a wait would happen right now: Mode::Sleeper while a sleeper is set, Mode::Swoole inside a Swoole coroutine,
     * Mode::Blocking anywhere else.
     *
     * This is worked out per call rather than once at construction, because the same instance may well be used both
     * inside and outside coroutines -- a service built during bootstrap and then used by coroutines, for one.
     */
    public function getMode(): Mode
    {
        if ($this->sleeper !== null) {
            return Mode::Sleeper;
        }

        if ($this->mode === Mode::Blocking) {
            return Mode::Blocking;
        }

        // Whether Mode::Swoole was asked for or is being worked out here, it only holds inside a coroutine created by
        // Swoole: method Coroutine::sleep() raises a Swoole\Error anywhere else, which is not something a library
        // whose job is to absorb failures should let happen. Coroutine::getPcid() answers FALSE only outside a
        // coroutine -- inside the outermost one it answers -1, having no parent to name.
        $inCoroutine = extension_loaded('swoole') && (Coroutine::getPcid() !== false);

        return $inCoroutine ? Mode::Swoole : Mode::Blocking;
    }

    /**
     * Hand the waiting over to given callback instead of doing it here, which takes precedence over both modes. Two
     * things this is for:
     *
     *   - waiting on an event loop this library knows nothing about -- ReactPHP, Amp, Revolt, a Fiber of your own;
     *   - tests, where a callback that records what it was given and returns makes a retrying test both instant and
     *     able to assert the timeouts it was supposed to wait for.
     *
     * @param ?Closure(int): void $sleeper receives the wait in microseconds; NULL to wait here again.
     */
    public function setSleeper(?Closure $sleeper): self
    {
        $this->sleeper = $sleeper;

        return $this;
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

    public function getMaxElapsedTime(): ?int
    {
        return $this->maxElapsedTime;
    }

    /**
     * Give the whole run a wall-clock budget: once the next wait would not finish inside it, the run stops and hands
     * back whatever the last attempt produced.
     *
     * Worth having even alongside a maximum number of attempts, because attempts say nothing about how long they
     * take, and it is the wall clock a caller is usually up against. Note that PHP's own max_execution_time will not
     * save you here: on Unix it does not count time spent in usleep(), so a runaway backoff is killed by whatever sits
     * in front of the process -- PHP-FPM, a proxy -- rather than by PHP, and it is killed mid-wait, with no error to
     * log.
     *
     * @param ?int $maxElapsedTime the budget in microseconds; NULL for no budget at all, which is the default.
     * @throws Exception
     */
    public function setMaxElapsedTime(?int $maxElapsedTime): self
    {
        if (($maxElapsedTime !== null) && ($maxElapsedTime < 1)) {
            throw new Exception('maximum elapsed time must be at least 1 microsecond, or NULL for no budget');
        }

        $this->maxElapsedTime = $maxElapsedTime;

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
     * treated as the first one. Randomness is applied to the capped timeout, so no timeout ever comes back longer than
     * $maxTimeout, whichever Jitter is in use.
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

        // Doubling in a loop that stops at the cap, instead of shifting by ($iteration - 1), keeps the timeout within
        // integer range no matter how many attempts are configured. A timeout of nothing is left alone: doubling it
        // would not move it, and looping until $iteration ran out could take years of that.
        for ($i = 1; ($i < $iteration) && ($timeout > 0); $i++) {
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

    protected function retry(mixed $result, ?\Exception $e, int $attempt, int $startedAt): bool
    {
        if (!$this->getRetryCondition()->shouldRetry($result, $e)) {
            return false;
        }

        if ($attempt >= $this->getMaxAttempts()) {
            return false;
        }

        $microSeconds = self::getTimeoutMicroseconds(
            $attempt,
            $this->getInitialTimeout(),
            $this->getMaxTimeout(),
            $this->getJitter()
        );

        if (!$this->affords($microSeconds, $startedAt)) {
            return false;
        }

        $this->sleep($microSeconds);

        return true;
    }

    /**
     * Whether waiting this long leaves the run inside the elapsed time it was given.
     *
     * A wait that would overrun the budget is not started at all, rather than being cut short: a wait cut short ends
     * at whatever moment the budget runs out, which is the same moment for every client that started together -- the
     * very thing the randomness is there to avoid.
     */
    protected function affords(int $microSeconds, int $startedAt): bool
    {
        if ($this->maxElapsedTime === null) {
            return true;
        }

        $elapsed = intdiv(hrtime(true) - $startedAt, 1_000);

        return ($elapsed + $microSeconds) <= $this->maxElapsedTime;
    }

    protected function sleep(int $microSeconds): void
    {
        // $this->getMode() decides this, so that what a caller is told and what actually happens cannot drift apart.
        $mode    = $this->getMode();
        $sleeper = $this->sleeper;

        if (($mode === Mode::Sleeper) && ($sleeper !== null)) {
            $sleeper($microSeconds);

            return;
        }

        if ($mode === Mode::Swoole) {
            // Minimum execution delay in Swoole is 1ms.
            Coroutine::sleep(max($microSeconds / 1_000_000, 0.001));

            return;
        }

        // ponytail: PHP implements usleep() via nanosleep(), so multi-second waits are fine here.
        usleep($microSeconds);
    }
}
