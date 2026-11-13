<?php

namespace Codeception\Lib\Connector\Phalcon;

use Phalcon\Session\AdapterInterface;

/**
 * Port re-implementation of the Codeception 2 Phalcon connector session
 * adapter: a session backend that stores values in memory (never touches
 * real sessions or headers).
 */
class MemorySession implements AdapterInterface
{
    protected $data = array();
    protected $started = false;
    protected $options = array();

    public function setOptions(array $options)
    {
        $this->options = $options;
    }

    public function getOptions()
    {
        return $this->options;
    }

    public function start()
    {
        $this->started = true;
        return true;
    }

    public function set($index, $value)
    {
        $this->data[$index] = $value;
    }

    public function get($index, $defaultValue = null)
    {
        return isset($this->data[$index]) ? $this->data[$index] : $defaultValue;
    }

    public function has($index)
    {
        return isset($this->data[$index]);
    }

    public function remove($index)
    {
        unset($this->data[$index]);
    }

    public function getId()
    {
        return 'memory';
    }

    public function isStarted()
    {
        return $this->started;
    }

    public function destroy($removeData = false)
    {
        if ($removeData) {
            $this->data = array();
        }
        $this->started = false;
        return true;
    }

    public function regenerateId($deleteOldSession = true)
    {
        return true;
    }

    public function setName($name)
    {
    }

    public function getName()
    {
        return 'memory-session';
    }
}
