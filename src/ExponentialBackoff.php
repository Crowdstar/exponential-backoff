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
        return (int) (self::getTimeoutMicroseconds($iteration, $initialTimeout * 1000000) / 1000000);
    }

    /**
     * Get the next timeout in microseconds.
     */
    public static function getTimeoutMicroseconds(int $iteration, int $initialTimeout = 250000): int
    {
        $timeout = $initialTimeout * (1 << --$iteration);

        // We throw in some randomness here to try to prevent connections from colliding
        return $timeout + rand(0, $timeout / 10);
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
