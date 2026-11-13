<?php

namespace Codeception\Specify;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\SelfDescribing;
use PHPUnit\Framework\Test;
use PHPUnit\Framework\TestResult;
use SebastianBergmann\Exporter\Exporter;

class SpecifyTest implements Test, SelfDescribing
{
    protected $name;

    protected $test;

    protected $example;

    protected $throws;
    protected $throwsMessage;

    public function __construct($test)
    {
        $this->test = $test;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function toString() : string
    {
        return $this->name;
    }

    public function getName($withDataSet = true)
    {
        if ($withDataSet && !empty($this->example)) {
            $exporter = new Exporter();
            return $this->name . ' | ' . $exporter->shortenedRecursiveExport($this->example);
        }

        return $this->name;
    }

    /**
     * Count elements of an object
     * @link http://php.net/manual/en/countable.count.php
     * @return int The custom count as an integer.
     * </p>
     * <p>
     * The return value is cast to an integer.
     * @since 5.1.0
     */
    public function count()
    {
        return 1;
    }

    /**
     * Runs a test and collects its result in a TestResult instance.
     *
     * @param TestResult|null $result
     *
     * @return TestResult
     */
    public function run(?TestResult $result = null): TestResult
    {
        /* Port: re-implements the 0.x "throws"/"throwsMessage" behavior on
         * top of the 1.x class (PHPUnit 9 compat; re-index examples). */
        try {
            call_user_func_array($this->test, array_values((array) $this->example));
        } catch (\Exception $e) {
            if ($this->throws !== null && $this->throws !== []) {
                $expected = is_array($this->throws) ? $this->throws : [$this->throws];
                foreach ($expected as $class) {
                    if ($e instanceof $class) {
                        if ($this->throwsMessage !== null && strpos($e->getMessage(), (string) $this->throwsMessage) === false) {
                            $failure = new AssertionFailedError(
                                "Specified exception message '" . $this->throwsMessage . "' not found in '" . $e->getMessage() . "'"
                            );
                            if ($result === null) { throw $failure; }
                            $result->addFailure(clone($this), $failure, $result->time());
                            if ($result === null) { $result = new TestResult(); }
                            return $result;
                        }
                        if ($result === null) { $result = new TestResult(); }
                        return $result; /* expected exception matched */
                    }
                }
            }
            if ($e instanceof AssertionFailedError) {
                if ($result === null) { throw $e; }
                $result->addFailure(clone($this), $e, $result->time());
            } else {
                /* unexpected exception: surface it (recorded by outer runner) */
                if ($result === null) { throw $e; }
                $result->addError(clone($this), $e, $result->time());
            }
            if ($result === null) { $result = new TestResult(); }
            return $result;
        }
        if ($this->throws !== null && $this->throws !== []) {
            $failure = new AssertionFailedError("Expected exception was not thrown");
            if ($result === null) { throw $failure; }
            $result->addFailure(clone($this), $failure, $result->time());
        }
        if ($result === null) { $result = new TestResult(); }
        return $result;
    }

    /**
     * @param mixed $example
     */
    public function setExample($example)
    {
        $this->example = $example;
    }

    /**
     * @param mixed $throws
     */
    public function setThrows($throws)
    {
        $this->throws = $throws;
    }

    /**
     * @param string|null $throwsMessage
     */
    public function setThrowsMessage($throwsMessage)
    {
        $this->throwsMessage = $throwsMessage;
    }
}