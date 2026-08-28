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
 * How the waiting between attempts happens.
 *
 * Only two of the three cases can be passed to ExponentialBackoff::__construct(), and of those only Mode::Blocking
 * changes anything: Mode::Swoole is what happens anyway wherever a Swoole coroutine is running, so passing it does the
 * same as passing nothing. Mode::Sleeper is an answer rather than a request, and the constructor rejects it.
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
     * A callback set with ExponentialBackoff::setSleeper() does the waiting, so neither primitive applies.
     *
     * Only ever returned by ExponentialBackoff::getMode(); the constructor throws on it. Ask for this by handing
     * ExponentialBackoff::setSleeper() a callback -- which is the only thing that can bring it about -- rather than by
     * naming the case.
     */
    case Sleeper;
}
