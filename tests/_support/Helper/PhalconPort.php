<?php

namespace Helper;

use Codeception\Exception\ModuleException;
use Codeception\Module;
use Codeception\TestInterface;
use Phalcon\Di\FactoryDefault;
use Phalcon\Mvc\Application;

/**
 * Port replacement for the Codeception 2 "Phalcon" module (removed in
 * Codeception 4+). Provides the actor methods the bundled 3.4 test suite
 * expects: getApplication, haveServiceInDi, grabServiceFromDi,
 * haveFrontendAdapter, haveInApc, seeInApc, dontSeeInApc, flushApc,
 * addServiceToContainer, seeCacheStarted, dontSeeCacheStarted.
 */
class PhalconPort extends Module
{
    /** @var \Phalcon\DiInterface */
    protected $di;

    /** @var Application */
    protected $application;

    public function _before(TestInterface $test)
    {
        $bootstrap = $this->_getConfig('bootstrap');
        if ($bootstrap === null) {
            throw new ModuleException($this, 'The "bootstrap" option is required');
        }

        /* Rebuild the whole container per test (what the Codeception 2
         * Phalcon module's cleanup did): tests mutate shared services
         * (db, modelsManager, modelsMetadata) and Phalcon\Di::reset(). */
        $app = require codecept_absolute_path($bootstrap);
        if (!$app instanceof Application) {
            throw new ModuleException($this, 'The bootstrap file must return an Application');
        }
        $this->application = $app;
        $this->di = $app->getDI();
        FactoryDefault::setDefault($this->di);
    }

    public function _afterSuite()
    {
        $this->application = null;
        $this->di = null;
        FactoryDefault::reset();
    }

    /**
     * @return Application
     */
    public function getApplication()
    {
        if ($this->application === null) {
            throw new ModuleException($this, 'Application is not bootstrapped');
        }
        return $this->application;
    }

    /**
     * Registers a shared service in the default DI and returns it resolved.
     *
     * @param string $name
     * @param mixed $definition
     * @return object
     */
    public function haveServiceInDi($name, $definition)
    {
        $this->di->set($name, $definition, true);
        return $this->di->get($name);
    }

    /**
     * Old Phalcon-module alias of haveServiceInDi.
     */
    public function addServiceToContainer($name, $definition)
    {
        return $this->haveServiceInDi($name, $definition);
    }

    /**
     * @param string $name
     * @return object
     */
    public function grabServiceFromDi($name)
    {
        return $this->di->get($name);
    }

    /**
     * Creates a cache frontend adapter with the given constructor options.
     *
     * @param string $frontend
     * @param array $options
     * @return object
     */
    public function haveFrontendAdapter($frontend, array $options = array())
    {
        return new $frontend($options);
    }

    /**
     * Stores a value in APCu under the given key.
     */
    public function haveInApc($key, $value)
    {
        apcu_store($key, $value);
    }

    public function grabValueFromApc($key)
    {
        $value = apcu_fetch($key);
        $this->assertNotFalse($value, "The APCu key '$key' does not exist");
        return $value;
    }

    public function seeInApc($key, $value = null)
    {
        $fetch = apcu_fetch($key);
        if ($fetch === false) {
            $this->fail("The APCu key '$key' does not exist");
        }
        if ($value !== null && $fetch != $value) {
            $this->fail("The APCu value for '$key' does not match");
        }
    }

    public function dontSeeInApc($key)
    {
        if (apcu_exists($key)) {
            $this->fail("The APCu key '$key' exists but should not");
        }
    }

    public function seeCacheStarted($frontend)
    {
        $this->assertTrue($frontend->isStarted());
    }

    public function dontSeeCacheStarted($frontend)
    {
        $this->assertFalse($frontend->isStarted());
    }

    /**
     * Clears APCu (and APC when present), as the old Apc module did.
     */
    public function flushApc()
    {
        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
        }
        if (function_exists('apc_clear_cache')) {
            apc_clear_cache('user');
        }
    }
}
