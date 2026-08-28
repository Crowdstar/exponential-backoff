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
     * Don't retry if conditions met.
     *
     * BEWARE WHEN MOVING TO 4.0: this method is renamed to shouldRetry() there, and the question it asks is inverted.
     * Returning TRUE means "try again" in 4.0, where here it means "stop". A condition carried across with nothing
     * changed but the method name therefore retries in exactly the cases it used to stop in, and nothing catches that
     * for you -- the signature is compatible either way round. Negate the body along with the rename.
     *
     * @param mixed $result
     * @return bool return TRUE if conditions met, otherwise return FALSE.
     * @deprecated Renamed to shouldRetry() in 4.0, with its meaning inverted. See the note above before upgrading.
     * @see ExponentialBackoff::retry()
     */
    abstract public function met($result, ?Exception $e): bool;
}
