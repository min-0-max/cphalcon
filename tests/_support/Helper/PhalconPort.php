<?php

namespace Helper;

use Codeception\Exception\ModuleException;
use Codeception\Lib\Framework;
use Codeception\Module;
use Codeception\TestInterface;
use Phalcon\Di\FactoryDefault;
use Phalcon\Mvc\Application;

/**
 * Port replacement for the Codeception 2 "Phalcon" module (removed in
 * Codeception 4+). Provides the actor methods the bundled 3.4 test suite
 * expects: getApplication, haveServiceInDi, haveFrontendAdapter, flushApc.
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

        if ($this->application === null) {
            $app = require codecept_absolute_path($bootstrap);
            if (!$app instanceof Application) {
                throw new ModuleException($this, 'The bootstrap file must return an Application');
            }
            $this->application = $app;
            $this->di = $app->getDI();
            FactoryDefault::setDefault($this->di);
        }
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
     * Registers a service in the default DI and returns it (resolved).
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
