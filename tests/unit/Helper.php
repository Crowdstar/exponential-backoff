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

namespace CrowdStar\Tests\Backoff;

use Exception;

/**
 * Class Helper.
 *
 * Used for unit tests and by sample PHP scripts under folder /examples.
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
     * @var string[]
     */
    protected $exceptions;

    /**
     * A pointer pointing to the exception to be thrown out from array $this->exceptions.
     *
     * @var int
     */
    protected $idxException = 0;

    /**
     * To return an empty string back when not yet reached expected # of failed attempts, otherwise return the value.
     */
    public function getValueAfterExpectedNumberOfFailedAttemptsWithEmptyReturnValuesReturned(): string
    {
        if (!$this->reachExpectedAttempts()) {
            return '';
        }

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
            throw new $exception('an exception thrown out from class \\' . __CLASS__); // @phpstan-ignore throw.notThrowable
        }

        return $this->getValue();
    }

    /**
     * Return TRUE if not yet reached expected # of failed attempts, otherwise return FALSE. This method will also
     * increment the iterator by 1 every time when called.
     */
    public function reachExpectedAttempts(): bool
    {
        if (!defined('UNDER_PHPUNIT') || !UNDER_PHPUNIT) {
            echo '# of attempts made: ', $this->currentAttempts, "\n";
        }

        $reachExpectedAttempts = ($this->getCurrentAttempts() > $this->getExpectedFailedAttempts());
        $this->increaseCurrentAttempts();

        return $reachExpectedAttempts;
    }

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

    public function getException(): string
    {
        if (empty($this->exceptions)) {
            throw new Exception('No exceptions defined.');
        }

        $exception = $this->exceptions[$this->idxException];
        // Now let's move the pointer to the next exception in the array.
        $this->idxException = ($this->idxException + 1) % count($this->exceptions);
        return $exception;
    }

    public function setException(string $exception): self
    {
        return $this->setExceptions($exception);
    }

    public function setExceptions(string ...$exceptions): self
    {
        $this->exceptions   = $exceptions;
        $this->idxException = 0;

        return $this;
    }

    protected function increaseCurrentAttempts(): self
    {
        $this->currentAttempts++;

        return $this;
    }
}
