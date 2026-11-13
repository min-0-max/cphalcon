<?php declare(strict_types=1);

namespace Codeception\Verify;

use Codeception\Verify\Verifiers\VerifyArrayTrait;
use Codeception\Verify\Verifiers\VerifyDirectoryTrait;
use Codeception\Verify\Verifiers\VerifyFileTrait;
use Codeception\Verify\Verifiers\VerifyMixedTrait;
use Codeception\Verify\Verifiers\VerifyStringTrait;
use Codeception\Verify\Verifiers\VerifyThrowableTrait;
use Codeception\Verify\Verifiers\VerifyUnionTrait;
use Codeception\Verify\Verifiers\VerifyXmlTrait;

/**
 * Port shim over codeception/verify 1.x: restores the 0.x behavior of
 * contains()/notContains() on strings (strpos-based), which the bundled
 * 3.4 test suite relies on. Loaded via the prepended autoloader in
 * tests-port/_bootstrap.php so it takes precedence over the vendored copy.
 */
class Verify
{
    use
        VerifyArrayTrait,
        VerifyDirectoryTrait,
        VerifyFileTrait,
        VerifyMixedTrait,
        VerifyStringTrait,
        VerifyThrowableTrait,
        VerifyUnionTrait,
        VerifyXmlTrait
    ;

    public static $override = false;

    protected $actual = null;
    protected $message = '';
    protected $isFileExpectation = false;

    public function __construct($message)
    {
        $messageGiven = (func_num_args() == 2);

        if (!$messageGiven) {
            $this->actual = $message;
            return;
        }

        $actual = func_get_args();
        $this->actual = $actual[1];
        $this->message = $message;
    }

    /* verify 0.x API names used by the bundled suite */
    public function hasntKey($key)
    {
        return $this->hasNotKey($key);
    }

    public function notEquals($expected)
    {
        \PHPUnit\Framework\Assert::assertNotEquals($expected, $this->actual, $this->message);
        return $this;
    }

    public function lessOrEquals($expected)
    {
        \PHPUnit\Framework\Assert::assertLessThanOrEqual($expected, $this->actual, $this->message);
        return $this;
    }

    public function greaterOrEquals($expected)
    {
        \PHPUnit\Framework\Assert::assertGreaterThanOrEqual($expected, $this->actual, $this->message);
        return $this;
    }

    public function null()
    {
        \PHPUnit\Framework\Assert::assertNull($this->actual, $this->message);
        return $this;
    }

    public function notNull()
    {
        \PHPUnit\Framework\Assert::assertNotNull($this->actual, $this->message);
        return $this;
    }

    public function notEmpty()
    {
        \PHPUnit\Framework\Assert::assertNotEmpty($this->actual, $this->message);
        return $this;
    }

    public function contains($needle)
    {
        if (is_string($this->actual)) {
            \PHPUnit\Framework\Assert::assertStringContainsString($needle, $this->actual, $this->message);
        } else {
            \PHPUnit\Framework\Assert::assertContains($needle, $this->actual, $this->message);
        }
        return $this;
    }

    public function notContains($needle)
    {
        if (is_string($this->actual)) {
            \PHPUnit\Framework\Assert::assertStringNotContainsString($needle, $this->actual, $this->message);
        } else {
            \PHPUnit\Framework\Assert::assertNotContains($needle, $this->actual, $this->message);
        }
        return $this;
    }
}
