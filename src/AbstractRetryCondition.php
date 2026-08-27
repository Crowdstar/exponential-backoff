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

use Exception;

/**
 * Class AbstractRetryCondition
 */
abstract class AbstractRetryCondition
{
    /**
     * When the caller finally fails with an exception caught, this method tells if the exception should be thrown out
     * or not.
     *
     * Saying that you are creating a customized condition where exceptions are thrown out. In this case, you can use
     * this method to decide if the exception should be thrown out (when finally failed), or when to throw out the
     * exception.
     */
    public function throwable(): bool
    {
        return true;
    }

    /**
     * Whether the attempt just made should be tried again.
     *
     * @param mixed      $result what the closure returned, or NULL when it threw.
     * @param ?Exception $e      what the closure threw, or NULL when it returned.
     * @return bool return TRUE to attempt the call again, FALSE to stop and take what the last attempt produced.
     * @see ExponentialBackoff::retry()
     */
    abstract public function shouldRetry(mixed $result, ?Exception $e): bool;
}
