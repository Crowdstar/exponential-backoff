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
 * How much randomness to mix into a timeout, to keep clients that failed together from retrying together.
 *
 * @see https://aws.amazon.com/blogs/architecture/exponential-backoff-and-jitter/ where these are measured against
 *      each other. Backoff without randomness performs worst of all; Full spreads clients out the most.
 */
enum Jitter
{
    /**
     * Wait exactly as long as the timeout says. Predictable, and therefore handy in tests, but it leaves clients
     * retrying in lockstep.
     */
    case None;

    /**
     * Wait anywhere between nothing and the full timeout. Spreads clients out the most, at the cost of retrying
     * sooner than the timeout suggests: it makes the timeout a maximum rather than a target.
     */
    case Full;

    /**
     * Wait at least half of the timeout, and randomly up to all of it. Half the spread of Full, in exchange for
     * never retrying very soon after a failure.
     */
    case Equal;
}
