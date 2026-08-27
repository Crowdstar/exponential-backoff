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
use Exception;

/**
 * Class CallbackCondition
 *
 * Decide whether to retry with a closure instead of a class of your own. Where a condition is worth naming and
 * reusing, write one: extend AbstractRetryCondition the way EmptyValueCondition and ExceptionBasedCondition do.
 *
 * @see ExponentialBackoff::when() for the short way to build a backoff around one of these.
 */
class CallbackCondition extends AbstractRetryCondition
{
    /**
     * @param Closure(mixed, ?Exception): bool $callback  return TRUE from this to attempt the call again.
     * @param bool                             $throwable whether to throw an exception the last attempt was left with.
     */
    public function __construct(
        protected readonly Closure $callback,
        protected readonly bool $throwable = true
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function shouldRetry(mixed $result, ?Exception $e): bool
    {
        return ($this->callback)($result, $e);
    }

    /**
     * {@inheritdoc}
     */
    public function throwable(): bool
    {
        return $this->throwable;
    }
}
