<?php

/**************************************************************************
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
 *************************************************************************/

declare(strict_types=1);

namespace CrowdStar\Tests\Backoff;

use Exception;

/**
 * Class Helper.
 *
 * Used for unit tests and by sample PHP scripts under folder /examples.
 *
 * @package CrowdStar\Backoff
 */
class Helper
{
    protected const VALUE = 'Hello World!';

    /**
     * Expected numbers of failed attempts before the value could be fetched.
     * @var int
     */
    protected $expectedFailedAttempts = 3;

    /**
     * @var int
     */
    protected $currentAttempts = 1;

    /**
     * @var string
     */
    protected $exception;

    /**
     * To return an empty string back when not yet reached expected # of failed attempts, otherwise return the value.
     */
    public function getValueAfterExpectedNumberOfFailedAttemptsWithEmptyReturnValuesReturned(): string
    {
        if (!$this->reachExpectedAttempts()) {
            return '';
        };

        return $this->getValue();
    }

    /**
     * To throw an exception out when not yet reached expected # of failed attempts, otherwise return the value.
     *
     * @throws Exception
     */
    public function getValueAfterExpectedNumberOfFailedAttemptsWithExceptionsThrownOut(): string
    {
        if (!$this->reachExpectedAttempts()) {
            $exception = $this->getException();
            throw new $exception('an exception thrown out from class \CrowdStar\Tests\Backoff\Helper');
        };

        return $this->getValue();
    }

    /**
     * Return TRUE if not yet reached expected # of failed attempts, otherwise return FALSE. This method will also
     * increment the iterator by 1 every time when called.
     */
    public function reachExpectedAttempts(): bool
    {
        if (!defined('UNDER_PHPUNIT') || !UNDER_PHPUNIT) {
            echo "# of attempts made: ", $this->currentAttempts, "\n";
        }

        $reachExpectedAttempts = ($this->getCurrentAttempts() > $this->getExpectedFailedAttempts());
        $this->increaseCurrentAttempts();

        return $reachExpectedAttempts;
    }

    /**
     * @see AttemptsTrait::$currentAttempts
     */
    public function reset(): self
    {
        $this->currentAttempts = 1;

        return $this;
    }

    public function getValue(): string
    {
        return self::VALUE;
    }

    public function getExpectedFailedAttempts(): int
    {
        return $this->expectedFailedAttempts;
    }

    public function setExpectedFailedAttempts(int $expectedFailedAttempts): self
    {
        $this->expectedFailedAttempts = $expectedFailedAttempts;

        return $this;
    }

    public function getCurrentAttempts(): int
    {
        return $this->currentAttempts;
    }

    protected function increaseCurrentAttempts(): self
    {
        $this->currentAttempts++;

        return $this;
    }

    public function getException(): string
    {
        return $this->exception;
    }

    public function setException(string $exception): self
    {
        $this->exception = $exception;

        return $this;
    }
}
