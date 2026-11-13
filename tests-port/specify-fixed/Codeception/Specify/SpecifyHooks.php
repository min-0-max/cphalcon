<?php

namespace Codeception\Specify;

use Closure;
use DeepCopy\DeepCopy;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;
use ReflectionObject;
use ReflectionProperty;
use RuntimeException;

trait SpecifyHooks
{
    private $afterSpecify = [];

    private $beforeSpecify = [];

    /**
     * @var DeepCopy
     */
    private $copier;

    /**
     * @var SpecifyTest
     */
    private $currentSpecifyTest;

    private $specifyName = '';

    /**
     * @return SpecifyTest
     */
    private function getCurrentSpecifyTest()
    {
        return $this->currentSpecifyTest;
    }
    /**
     * @param string $specification
     * @param Closure|null $callable
     * @param callable|array $params
     */
    private function runSpec($specification, ?Closure $callable = null, $params = [])
    {
        if (!$callable) {
            return;
        }

        if (!$this->copier) {
            $this->copier = new DeepCopy();
            $this->copier->skipUncloneable();
        }

        $properties = $this->getSpecifyObjectProperties();

        // prepare for execution
        $examples = $this->getSpecifyExamples($params);
        $showExamplesIndex = $examples !== [[]];

        $specifyName = $this->specifyName;
        $this->specifyName .= ' ' . $specification;

        foreach ($examples as $idx => $example) {
            $test = new SpecifyTest($callable->bindTo($this));
            $this->currentSpecifyTest = $test;
            $test->setName($this->getName() . ' |' . $this->specifyName);
            $test->setExample($example);
            if (isset($params['throws'])) {
                $test->setThrows($params['throws']);
            }
            if (isset($params['throwsMessage'])) {
                $test->setThrowsMessage($params['throwsMessage']);
            }
            if ($showExamplesIndex) {
                $test->setName($this->getName() . ' |' . $this->specifyName . ' # example ' . $idx);
            }

            // copy current object properties
            $this->specifyCloneProperties($properties);

            if (!empty($this->beforeSpecify) && is_array($this->beforeSpecify)) {
                foreach ($this->beforeSpecify as $closure) {
                    if ($closure instanceof Closure) $closure->__invoke();
                }
            }

            $test->run($this->getTestResultObject());
            $this->specifyCheckMockObjects();

            // restore object properties
            $this->specifyRestoreProperties($properties);

            if (!empty($this->afterSpecify) && is_array($this->afterSpecify)) {
                foreach ($this->afterSpecify as $closure) {
                    if ($closure instanceof Closure) $closure->__invoke();
                }
            }
        }

        // revert specify name
        $this->specifyName = $specifyName;
    }

    /**
     * @param $params
     * @return array
     */
    private function getSpecifyExamples($params)
    {
        if (isset($params['examples'])) {
            if (!is_array($params['examples'])) {
                throw new RuntimeException("Examples should be an array");
            }
            return $params['examples'];
        }
        return [[]];
    }

    /**
     * @return ReflectionClass|null
     */
    private function specifyGetPhpUnitReflection()
    {
        if ($this instanceof TestCase) {
            return new ReflectionClass(TestCase::class);
        }
        return null;
    }

    private function specifyCheckMockObjects()
    {
        if (($phpUnitReflection = $this->specifyGetPhpUnitReflection()) !== null) {
            try {
                $verifyMockObjects = $phpUnitReflection->getMethod('verifyMockObjects');
                $verifyMockObjects->setAccessible(true);
                $verifyMockObjects->invoke($this);
            } catch (ReflectionException $e) {
            }
        }
    }

    /**
     * @param ObjectProperty[] $properties
     */
    private function specifyRestoreProperties($properties)
    {
        foreach ($properties as $property) {
            $property->restoreValue();
        }
    }

    /**
     * @return ObjectProperty[]
     */
    private function getSpecifyObjectProperties()
    {
        $objectReflection = new ReflectionObject($this);
        $properties = $objectReflection->getProperties();

        if (($classProperties = $this->specifyGetClassPrivateProperties()) !== []) {
            $properties = array_merge($properties, $classProperties);
        }

        $clonedProperties = [];

        foreach ($properties as $property) {
            /* 0.4 cloned/restore ALL object properties (opt-out via ignore
             * lists, handled in specifyCloneProperties); 1.x requires an
             * opt-in @specify annotation. The bundled suite has no such
             * annotations - use the 0.4 semantics. */
            $property->setAccessible(true);
            $clonedProperties[] = new ObjectProperty($this, $property);
        }

        // isolate mockObjects property from PHPUnit\Framework\TestCase
        if ($classReflection = $this->specifyGetPhpUnitReflection()) {
            try {
                $property = $classReflection->getProperty('mockObjects');
                // remove all mock objects inherited from parent scope(s)
                $clonedProperties[] = new ObjectProperty($this, $property);
                $property->setValue($this, []);
            } catch (ReflectionException $e) {
            }
        }

        return $clonedProperties;
    }

    private function specifyGetClassPrivateProperties()
    {
        static $properties = [];

        if (!isset($properties[__CLASS__])) {
            $reflection = new ReflectionClass(__CLASS__);

            $properties[__CLASS__] = (get_class($this) !== __CLASS__)
                ? $reflection->getProperties(ReflectionProperty::IS_PRIVATE) : [];
        }

        return $properties[__CLASS__];
    }

    /**
     * @param ObjectProperty[] $properties
     */
    private function specifyCloneProperties($properties)
    {
        /* The bundled 3.4 suite ran specify 0.4 with deep cloning disabled
         * (SpecifyConfig::setDeepClone(false) in Helper\Unit::_before), so
         * every object property was SHALLOW-cloned per example and restored
         * after it: mutations land on the clone, the original stays
         * pristine. codeception/specify 1.x removed this mode (deep clone
         * only); restore the 0.4 semantics the suite was written against. */
        static $ignoredClasses = [
            'Codeception\\Actor',
            'Symfony\\Component\\EventDispatcher\\EventDispatcher',
            'Codeception\\Scenario',
            'Codeception\\Lib\\Parser',
        ];
        static $ignoredProperties = [
            'backupGlobals', 'backupGlobalsBlacklist',
            'backupStaticAttributes', 'backupStaticAttributesBlacklist',
            'runTestInSeparateProcess', 'preserveGlobalState',
            'dependencies', 'dependencyInput', 'tester', 'guy', 'name',
        ];
        foreach ($properties as $property) {
            $propertyName = $property->getName();
            if (in_array($propertyName, $ignoredProperties, true)) {
                continue;
            }
            $propertyValue = $property->getValue();
            if (is_object($propertyValue) && in_array(get_class($propertyValue), $ignoredClasses, true)) {
                continue;
            }
            if (is_object($propertyValue)) {
                $property->setValue(clone $propertyValue);
            } else {
                $property->setValue($propertyValue);
            }
        }
    }

    private function beforeSpecify(?Closure $callable = null)
    {
        $this->beforeSpecify[] = $callable->bindTo($this);
    }

    private function afterSpecify(?Closure $callable = null)
    {
        $this->afterSpecify[] = $callable->bindTo($this);
    }

    private function cleanSpecify()
    {
        $this->afterSpecify = [];
        $this->beforeSpecify = [];
    }
}