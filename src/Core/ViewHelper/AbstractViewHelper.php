<?php
declare(strict_types=1);
namespace TYPO3Fluid\Fluid\Core\ViewHelper;

/*
 * This file belongs to the package "TYPO3 Fluid".
 * See LICENSE.txt that was shipped with this package.
 */

use TYPO3Fluid\Fluid\Component\Argument\ArgumentCollection;
use TYPO3Fluid\Fluid\Component\Argument\ArgumentCollectionInterface;
use TYPO3Fluid\Fluid\Component\ComponentInterface;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\AbstractNode;
use TYPO3Fluid\Fluid\Core\Parser\SyntaxTree\BooleanNode;
use TYPO3Fluid\Fluid\Core\Rendering\RenderingContextInterface;

/**
 * The abstract base class for all view helpers.
 *
 * @api
 */
abstract class AbstractViewHelper extends AbstractNode implements ViewHelperInterface
{
    /**
     * @var array
     */
    protected $parsedArguments = [];

    /**
     * @var RenderingContextInterface
     */
    protected $renderingContext;

    /**
     * @var \Closure
     */
    protected $renderChildrenClosure = null;

    /**
     * Execute via Component API implementation.
     *
     * @param RenderingContextInterface $renderingContext
     * @param ArgumentCollectionInterface|null $arguments
     * @return mixed
     * @api
     */
    public function execute(RenderingContextInterface $renderingContext, ?ArgumentCollectionInterface $arguments = null)
    {
        $this->setRenderingContext($renderingContext);
        if ($arguments) {
            $this->arguments = (array) $arguments->evaluate($renderingContext);
        } else {
            $this->arguments = $this->parsedArguments;
            foreach ($this->arguments as $name => $value) {
                $this->arguments[$name] = $value instanceof ComponentInterface ? $value->execute($renderingContext) : $value;
            }
        }
        return $this->initializeArgumentsAndRender();
    }

    /**
     * @param RenderingContextInterface $renderingContext
     * @param ArgumentCollectionInterface|null $arguments
     * @return ComponentInterface
     */
    public function onOpen(RenderingContextInterface $renderingContext, ?ArgumentCollectionInterface $arguments = null): ComponentInterface
    {
        $definitions = $this->prepareArguments();
        $this->parsedArguments = $this->createInternalArguments($arguments ? $arguments->readAll() : $this->parsedArguments, $definitions);
        $this->renderingContext = $renderingContext;
        $this->validateParsedArguments($this->parsedArguments, $definitions);
        return $this;
    }

    public function createArgumentDefinitions(): ArgumentCollectionInterface
    {
        return new ArgumentCollection($this->prepareArguments());
    }

    public function getParsedArguments(): array
    {
        return $this->parsedArguments;
    }

    /**
     * @param ComponentInterface[]|mixed[] $arguments
     * @param ArgumentDefinition[] $argumentDefinitions
     * @throws Exception
     */
    protected function validateParsedArguments(array $arguments, array $argumentDefinitions)
    {
        $additionalArguments = [];
        foreach ($arguments as $argumentName => $value) {
            if (!isset($argumentDefinitions[$argumentName])) {
                $additionalArguments[$argumentName] = $value;
            }
        }
        $this->validateAdditionalArguments($additionalArguments);
    }

    /**
     * Creates arguments by padding with missing+optional arguments
     * and casting or creating BooleanNode where appropriate. Input
     * array may not contain all arguments - output array will.
     *
     * @param array $arguments
     * @param array|null $definitions
     * @return array
     */
    protected function createInternalArguments(array $arguments, ?array $definitions = null): array
    {
        $definitions = $definitions ?? $this->prepareArguments();
        $missingArguments = [];
        foreach ($definitions as $name => $definition) {
            $argument = &$arguments[$name] ?? null;
            if ($definition->isRequired() && !isset($argument)) {
                // Required but missing argument, causes failure (delayed, to report all missing arguments at once)
                $missingArguments[] = $name;
            } elseif (!isset($argument)) {
                // Argument is optional (required filtered out above), fit it with the default value
                $argument = $definition->getDefaultValue();
            } elseif (($type = $definition->getType()) && ($type === 'bool' || $type === 'boolean')) {
                // Cast the value or create a BooleanNode
                $argument = is_bool($argument) || is_numeric($argument) ? (bool)$argument : new BooleanNode($argument);
            }
            $arguments[$name] = $argument;
        }
        if (!empty($missingArguments)) {
            throw new \TYPO3Fluid\Fluid\Core\Parser\Exception('Required argument(s) not provided: ' . implode(', ', $missingArguments), 1558533510);
        }
        return $arguments;
    }

    /**
     * @param array $arguments
     * @return void
     */
    public function setArguments(array $arguments): void
    {
        $this->arguments = $arguments;
    }

    /**
     * @param RenderingContextInterface $renderingContext
     * @return void
     */
    public function setRenderingContext(RenderingContextInterface $renderingContext)
    {
        $this->renderingContext = $renderingContext;
    }

    /**
     * Register a new argument. Call this method from your ViewHelper subclass
     * inside the initializeArguments() method.
     *
     * @param string $name Name of the argument
     * @param string $type Type of the argument
     * @param string $description Description of the argument
     * @param boolean $required If TRUE, argument is required. Defaults to FALSE.
     * @param mixed $defaultValue Default value of argument
     * @return AbstractViewHelper $this, to allow chaining.
     * @throws Exception
     * @api
     */
    protected function registerArgument(string $name, string $type, string $description, bool $required = false, $defaultValue = null): self
    {
        if (array_key_exists($name, $this->argumentDefinitions)) {
            throw new Exception(
                'Argument "' . $name . '" has already been defined, thus it should not be defined again.',
                1253036401
            );
        }
        $this->argumentDefinitions[$name] = new ArgumentDefinition($name, $type, $description, $required, $defaultValue);
        return $this;
    }

    /**
     * Overrides a registered argument. Call this method from your ViewHelper subclass
     * inside the initializeArguments() method if you want to override a previously registered argument.
     * @see registerArgument()
     *
     * @param string $name Name of the argument
     * @param string $type Type of the argument
     * @param string $description Description of the argument
     * @param boolean $required If TRUE, argument is required. Defaults to FALSE.
     * @param mixed $defaultValue Default value of argument
     * @return AbstractViewHelper $this, to allow chaining.
     * @throws Exception
     * @api
     */
    protected function overrideArgument(string $name, string $type, string $description, bool $required = false, $defaultValue = null): self
    {
        if (!array_key_exists($name, $this->argumentDefinitions)) {
            throw new Exception(
                'Argument "' . $name . '" has not been defined, thus it can\'t be overridden.',
                1279212461
            );
        }
        $this->argumentDefinitions[$name] = new ArgumentDefinition($name, $type, $description, $required, $defaultValue);
        return $this;
    }

    /**
     * Called when being inside a cached template.
     *
     * @param \Closure $renderChildrenClosure
     * @return void
     */
    public function setRenderChildrenClosure(\Closure $renderChildrenClosure)
    {
        $this->renderChildrenClosure = $renderChildrenClosure;
    }

    /**
     * Initialize the arguments of the ViewHelper, and call the render() method of the ViewHelper.
     *
     * @return mixed the rendered ViewHelper.
     */
    public function initializeArgumentsAndRender()
    {
        $this->validateArguments();
        return $this->callRenderMethod();
    }

    /**
     * Call the render() method and handle errors.
     *
     * @return mixed the rendered ViewHelper
     * @throws Exception
     */
    protected function callRenderMethod()
    {
        if (method_exists($this, 'render')) {
            return call_user_func([$this, 'render']);
        }
        if (method_exists($this, 'renderStatic')) {
            // Method is safe to call - will not recurse through ViewHelperInvoker via the default
            // implementation of renderStatic() on this class.
            return call_user_func_array([static::class, 'renderStatic'], [$this->arguments ?? [], $this->buildRenderChildrenClosure(), $this->renderingContext]);
        }
        throw new Exception(
            sprintf(
                'ViewHelper class "%s" does not declare a "render()" method and inherits the default "renderStatic". ' .
                'Executing this ViewHelper would cause infinite recursion - please either implement "render()" or ' .
                '"renderStatic()" on your ViewHelper class',
                get_class($this)
            )
        );
    }

    public function evaluate(RenderingContextInterface $renderingContext)
    {
        return $this->execute($renderingContext);
    }

    /**
     * Helper method which triggers the rendering of everything between the
     * opening and the closing tag.
     *
     * @return mixed The finally rendered child nodes.
     * @api
     */
    protected function renderChildren()
    {
        if ($this->renderChildrenClosure !== null) {
            $closure = $this->renderChildrenClosure;
            return $closure();
        }
        return $this->evaluateChildNodes($this->renderingContext);
    }

    /**
     * Helper which is mostly needed when calling renderStatic() from within
     * render().
     *
     * No public API yet.
     *
     * @return \Closure
     */
    protected function buildRenderChildrenClosure()
    {
        $self = clone $this;
        return function() use ($self) {
            return $self->renderChildren();
        };
    }

    /**
     * Initialize all arguments and return them
     *
     * @return ArgumentDefinition[]
     */
    public function prepareArguments()
    {
        if (empty($this->argumentDefinitions)) {
            $this->initializeArguments();
        }
        return $this->argumentDefinitions;
    }

    /**
     * Validate arguments, and throw exception if arguments do not validate.
     *
     * @return void
     * @throws \InvalidArgumentException
     */
    public function validateArguments()
    {
        $argumentDefinitions = $this->prepareArguments();
        foreach ($argumentDefinitions as $argumentName => $registeredArgument) {
            if ($this->hasArgument($argumentName)) {
                $value = $this->arguments[$argumentName];
                $type = $registeredArgument->getType();
                if ($value !== $registeredArgument->getDefaultValue() && $type !== 'mixed') {
                    $givenType = is_object($value) ? get_class($value) : gettype($value);
                    if (!$this->isValidType($type, $value)) {
                        throw new \InvalidArgumentException(
                            'The argument "' . $argumentName . '" was registered with type "' . $type . '", but is of type "' .
                            $givenType . '" in view helper "' . get_class($this) . '". Value: ' . var_export($value, true),
                            1256475113
                        );
                    }
                }
            }
        }
    }

    /**
     * Check whether the defined type matches the value type
     *
     * @param string $type
     * @param mixed $value
     * @return boolean
     */
    protected function isValidType(string $type, $value): bool
    {
        if ($type === 'object') {
            if (!is_object($value)) {
                return false;
            }
        } elseif ($type === 'array' || substr($type, -2) === '[]') {
            if (!is_array($value) && !$value instanceof \ArrayAccess && !$value instanceof \Traversable && !empty($value)) {
                return false;
            } elseif (substr($type, -2) === '[]') {
                $firstElement = $this->getFirstElementOfNonEmpty($value);
                if ($firstElement === null) {
                    return true;
                }
                return $this->isValidType(substr($type, 0, -2), $firstElement);
            }
        } elseif ($type === 'string') {
            if (is_object($value) && !method_exists($value, '__toString')) {
                return false;
            }
        } elseif ($type === 'boolean' && !is_bool($value)) {
            return false;
        } elseif (class_exists($type) && $value !== null && !$value instanceof $type) {
            return false;
        } elseif (is_object($value) && !is_a($value, $type, true)) {
            return false;
        }
        return true;
    }

    /**
     * Return the first element of the given array, ArrayAccess or Traversable
     * that is not empty
     *
     * @param mixed $value
     * @return mixed
     */
    protected function getFirstElementOfNonEmpty($value)
    {
        if (is_array($value)) {
            return reset($value);
        } elseif ($value instanceof \Traversable) {
            foreach ($value as $element) {
                return $element;
            }
        }
        return null;
    }

    /**
     * Initialize all arguments. You need to override this method and call
     * $this->registerArgument(...) inside this method, to register all your arguments.
     *
     * @return void
     * @api
     */
    protected function initializeArguments()
    {
    }

    /**
     * Tests if the given $argumentName is set, and not NULL.
     * The isset() test used fills both those requirements.
     *
     * @param string $argumentName
     * @return boolean TRUE if $argumentName is found, FALSE otherwise
     * @api
     */
    protected function hasArgument(string $argumentName): bool
    {
        return isset($this->arguments[$argumentName]);
    }

    /**
     * Default implementation of "handling" additional, undeclared arguments.
     * In this implementation the behavior is to consistently throw an error
     * about NOT supporting any additional arguments. This method MUST be
     * overridden by any ViewHelper that desires this support and this inherited
     * method must not be called, obviously.
     *
     * @throws Exception
     * @param array $arguments
     * @return void
     */
    public function handleAdditionalArguments(array $arguments)
    {
    }

    /**
     * Default implementation of validating additional, undeclared arguments.
     * In this implementation the behavior is to consistently throw an error
     * about NOT supporting any additional arguments. This method MUST be
     * overridden by any ViewHelper that desires this support and this inherited
     * method must not be called, obviously.
     *
     * @throws Exception
     * @param array $arguments
     * @return void
     */
    public function validateAdditionalArguments(array $arguments)
    {
        if (!empty($arguments)) {
            throw new Exception(
                sprintf(
                    'Undeclared arguments passed to ViewHelper %s: %s. Valid arguments are: %s',
                    get_class($this),
                    implode(', ', array_keys($arguments)),
                    implode(', ', array_keys($this->argumentDefinitions))
                )
            );
        }
    }

    /**
     * Validate a single undeclared argument - see validateAdditionalArguments
     * for more information.
     *
     * @param string $argumentName
     * @return bool
     */
    public function validateAdditionalArgument(string $argumentName): bool
    {
        return false;
    }

    /**
     * Resets the ViewHelper state.
     *
     * Overwrite this method if you need to get a clean state of your ViewHelper.
     *
     * @return void
     */
    public function resetState()
    {
    }
}
