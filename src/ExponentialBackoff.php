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

    public const TYPE_MICROSECONDS = 1;

    public const TYPE_SECONDS      = 2;

    protected const SAPI_DEFAULT = 1;

    protected const SAPI_SWOOLE  = 2;

    /**
     * @var int
     */
    protected $type = self::TYPE_MICROSECONDS;

    /**
     * @var int
     * @see ExponentialBackoff::SAPI_DEFAULT
     * @see ExponentialBackoff::SAPI_SWOOLE
     */
    protected $sapi;

    /**
     * @var int
     */
    protected $maxAttempts = self::DEFAULT_MAX_ATTEMPTS;

    /**
     * @var int
     *
     * @todo Drop the initial value in version 4.0 (once we have method $this->>getCurrentAttempts() removed).
     */
    protected $currentAttempts = 1;

    /**
     * @var AbstractRetryCondition
     */
    protected $retryCondition;

    /**
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

        $this->setRetryCondition($retryCondition);
    }

    /**
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

    public function getType(): int
    {
        return $this->type;
    }

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
     * @deprecated Will be removed in 4.0.
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

    protected function increaseCurrentAttempts(): self
    {
        $this->currentAttempts++;

        return $this;
    }

    /**
     * @param mixed $result
     * @throws Exception
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
     */
    protected function sleep(): self
    {
        switch ($this->getType()) {
            case self::TYPE_MICROSECONDS:
                $microSeconds = self::getTimeoutMicroseconds($this->currentAttempts);
                switch ($this->sapi) {
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
                switch ($this->sapi) {
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
