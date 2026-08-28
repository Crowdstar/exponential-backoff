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

/**
 * Which primitive waits between attempts.
 *
 * Of the two cases worth passing to ExponentialBackoff::__construct(), only Mode::Blocking changes anything:
 * Mode::Swoole is what happens anyway wherever a Swoole coroutine is running, so passing it does the same as passing
 * nothing. Mode::Sleeper is reported by ExponentialBackoff::getMode() and is not something to pass.
 */
enum Mode
{
    /**
     * Wait with usleep(), never with Swoole's.
     *
     * Named for what it usually does rather than what it guarantees: Swoole's runtime hooks turn usleep() into a
     * coroutine yield, and SWOOLE_HOOK_SLEEP is on by default inside Swoole\Coroutine\run(), so a wait here does not
     * block the coroutine it runs in unless those hooks are off.
     */
    case Blocking;

    /**
     * Wait with Swoole\Coroutine::sleep(), which suspends the current coroutine instead of the process.
     *
     * Falls back to Mode::Blocking wherever no Swoole coroutine is running, rather than raising the Swoole\Error that
     * Coroutine::sleep() produces there.
     */
    case Swoole;

    /**
     * A callback set with ExponentialBackoff::setSleeper() does the waiting, so neither of the other two cases applies.
     *
     * Only ever returned by ExponentialBackoff::getMode(). Passing it to the constructor is not an error, but says
     * nothing: what a wait does is then worked out the way NULL has it, because the sleeper decides this on its own.
     */
    case Sleeper;
}
