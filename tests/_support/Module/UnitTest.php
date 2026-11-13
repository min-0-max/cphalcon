<?php

namespace Phalcon\Test\Module;

use UnitTester;
use Codeception\Specify;
use Codeception\Test\Unit;
use PHPUnit_Runner_Version;

/**
 * \Phalcon\Test\Module\UnitTest
 * Base class for all Unit tests
 *
 * @copyright (c) 2011-2017 Phalcon Team
 * @link      http://www.phalconphp.com
 * @author    Andres Gutierrez <andres@phalconphp.com>
 * @author    Nikolaos Dimopoulos <nikos@phalconphp.com>
 * @package   Phalcon\Test\Module
 *
 * The contents of this file are subject to the New BSD License that is
 * bundled with this package in the file LICENSE.txt
 *
 * If you did not receive a copy of the license and are unable to obtain it
 * through the world-wide-web, please send an email to license@phalconphp.com
 * so that we can send you a copy immediately.
 */
class UnitTest extends Unit
{
    use Specify;

    /**
     * UnitTester Object
     * @var UnitTester
     */
    protected $tester;

    /**
     * @return UnitTester
     */
    public function getTester()
    {
        return $this->tester;
    }

    /**
     * PHPUnit < 9 honored @expectedException / @expectedExceptionMessage
     * docblock annotations; PHPUnit 9 removed them. The bundled suite uses
     * them, so translate them to expectException() calls here.
     */
    protected function runTest()
    {
        try {
            $ref = new \ReflectionMethod($this, $this->getName(false));
        } catch (\ReflectionException $e) {
            return parent::runTest();
        }
        $doc = $ref->getDocComment();
        if ($doc) {
            if (preg_match('/@expectedException\s+([^\s\*]+)/', $doc, $m)) {
                $this->expectException(trim($m[1], '\\\\'));
            }
            if (preg_match('/@expectedExceptionMessage\s+(.+)/', $doc, $m)) {
                $this->expectExceptionMessage(trim($m[1]));
            }
        }
        return parent::runTest();
    }

    public function setExpectedException($exception, $message = '', $code = null)
    {
        /* Port runner (PHPUnit >= 9): only the expectException API exists. */
        $this->expectException($exception);

        if ($message !== null && $message !== '') {
            $this->expectExceptionMessage($message);
        }

        if ($code !== null) {
            $this->expectExceptionCode($code);
        }
    }

    /**
     * Tries to delete a file (or a list of files) which may not exist.
     *
     * @param mixed $files
     * @return void
     */
    public function silentRemoveFiles($files)
    {
        if (!is_string($files) && !is_array($files)) {
            return;
        }

        if (!is_array($files)) {
            $files = [$files];
        }

        foreach ($files as $file) {
            if (file_exists($file) && is_readable($file) && !is_dir($file)) {
                @unlink($file);
            }
        }
    }
}
