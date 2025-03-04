<?php

declare(strict_types=1);

namespace Symplify\AutowireArrayParameter\DependencyInjection\CompilerPass;

use Nette\Utils\Reflection;
use Nette\Utils\Strings;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symplify\AutowireArrayParameter\DocBlock\ParamTypeDocBlockResolver;
use Symplify\PackageBuilder\DependencyInjection\DefinitionFinder;

/**
 * @inspiration https://github.com/nette/di/pull/178
 * @see \Symplify\AutowireArrayParameter\Tests\DependencyInjection\CompilerPass\AutowireArrayParameterCompilerPassTest
 */
final class AutowireArrayParameterCompilerPass implements CompilerPassInterface
{
    /**
     * These namespaces are already configured by their bundles/extensions.
     * @var string[]
     */
    private const EXCLUDED_NAMESPACES = ['Doctrine', 'JMS', 'Symfony', 'Sensio', 'Knp', 'EasyCorp', 'Sonata', 'Twig'];

    /**
     * Classes that create circular dependencies
     * @var string[]
     * @noRector
     */
    private $excludedFatalClasses = [
        'Symfony\Component\Form\FormExtensionInterface',
        'Symfony\Component\Asset\PackageInterface',
        'Symfony\Component\Config\Loader\LoaderInterface',
        'Symfony\Component\VarDumper\Dumper\ContextProvider\ContextProviderInterface',
        'EasyCorp\Bundle\EasyAdminBundle\Form\Type\Configurator\TypeConfiguratorInterface',
        'Sonata\CoreBundle\Model\Adapter\AdapterInterface',
        'Sonata\Doctrine\Adapter\AdapterChain',
        'Sonata\Twig\Extension\TemplateExtension',
    ];

    /**
     * @var DefinitionFinder
     */
    private $definitionFinder;

    /**
     * @var ParamTypeDocBlockResolver
     */
    private $paramTypeDocBlockResolver;

    /**
     * @param string[] $excludedFatalClasses
     */
    public function __construct(array $excludedFatalClasses = [])
    {
        $this->definitionFinder = new DefinitionFinder();
        $this->paramTypeDocBlockResolver = new ParamTypeDocBlockResolver();

        $this->excludedFatalClasses = array_merge($this->excludedFatalClasses, $excludedFatalClasses);
    }

    public function process(ContainerBuilder $containerBuilder): void
    {
        $definitions = $containerBuilder->getDefinitions();
        foreach ($definitions as $definition) {
            if ($this->shouldSkipDefinition($containerBuilder, $definition)) {
                continue;
            }

            /** @var ReflectionClass $reflectionClass */
            $reflectionClass = $containerBuilder->getReflectionClass($definition->getClass());
            /** @var ReflectionMethod $constructorMethodReflection */
            $constructorMethodReflection = $reflectionClass->getConstructor();

            $this->processParameters($containerBuilder, $constructorMethodReflection, $definition);
        }
    }

    private function shouldSkipDefinition(ContainerBuilder $containerBuilder, Definition $definition): bool
    {
        if ($definition->isAbstract()) {
            return true;
        }

        if ($definition->getClass() === null) {
            return true;
        }

        // here class name can be "%parameter.class%"
        $parameterBag = $containerBuilder->getParameterBag();
        $resolvedClassName = $parameterBag->resolveValue($definition->getClass());

        // skip 3rd party classes, they're autowired by own config
        $excludedNamespacePattern = '#^(' . implode('|', self::EXCLUDED_NAMESPACES) . ')\\\\#';
        if ((bool) Strings::match($resolvedClassName, $excludedNamespacePattern)) {
            return true;
        }

        if (in_array($resolvedClassName, $this->excludedFatalClasses, true)) {
            return true;
        }

        if ($definition->getFactory()) {
            return true;
        }

        $reflectionClass = $containerBuilder->getReflectionClass($definition->getClass());
        if ($reflectionClass === null) {
            return true;
        }

        if (! $reflectionClass->hasMethod('__construct')) {
            return true;
        }

        /** @var ReflectionMethod $constructorMethodReflection */
        $constructorMethodReflection = $reflectionClass->getConstructor();
        return ! $constructorMethodReflection->getParameters();
    }

    private function processParameters(
        ContainerBuilder $containerBuilder,
        ReflectionMethod $reflectionMethod,
        Definition $definition
    ): void {
        $reflectionParameters = $reflectionMethod->getParameters();
        foreach ($reflectionParameters as $reflectionParameter) {
            if ($this->shouldSkipParameter($reflectionMethod, $definition, $reflectionParameter)) {
                continue;
            }

            $parameterType = $this->resolveParameterType($reflectionParameter->getName(), $reflectionMethod);
            if ($parameterType === null) {
                continue;
            }

            $definitionsOfType = $this->definitionFinder->findAllByType($containerBuilder, $parameterType);
            $definitionsOfType = $this->filterOutAbstractDefinitions($definitionsOfType);

            $argumentName = '$' . $reflectionParameter->getName();
            $definition->setArgument($argumentName, $this->createReferencesFromDefinitions($definitionsOfType));
        }
    }

    private function shouldSkipParameter(
        ReflectionMethod $reflectionMethod,
        Definition $definition,
        ReflectionParameter $reflectionParameter
    ): bool {
        if (! $this->isArrayType($reflectionParameter)) {
            return true;
        }

        // already set
        $argumentName = '$' . $reflectionParameter->getName();
        if (isset($definition->getArguments()[$argumentName])) {
            return true;
        }

        $parameterType = $this->resolveParameterType($reflectionParameter->getName(), $reflectionMethod);
        if ($parameterType === null) {
            return true;
        }

        if (in_array($parameterType, $this->excludedFatalClasses, true)) {
            return true;
        }

        if (! class_exists($parameterType) && ! interface_exists($parameterType)) {
            return true;
        }

        // prevent circular dependency
        if ($definition->getClass() === null) {
            return false;
        }
        return is_a($definition->getClass(), $parameterType, true);
    }

    private function resolveParameterType(string $parameterName, ReflectionMethod $reflectionMethod): ?string
    {
        $docComment = $reflectionMethod->getDocComment();
        if ($docComment === false) {
            return null;
        }

        $resolvedType = $this->paramTypeDocBlockResolver->resolve($docComment, $parameterName);

        if ($resolvedType === null) {
            return null;
        }

        // not a class|interface type
        if (ctype_lower($resolvedType[0])) {
            return null;
        }

        return Reflection::expandClassName($resolvedType, $reflectionMethod->getDeclaringClass());
    }

    /**
     * Abstract definitions cannot be the target of references
     *
     * @param Definition[] $definitions
     * @return Definition[]
     */
    private function filterOutAbstractDefinitions(array $definitions): array
    {
        foreach ($definitions as $key => $definition) {
            if ($definition->isAbstract()) {
                unset($definitions[$key]);
            }
        }

        return $definitions;
    }

    /**
     * @param Definition[] $definitions
     * @return Reference[]
     */
    private function createReferencesFromDefinitions(array $definitions): array
    {
        $references = [];
        $definitionOfTypeNames = array_keys($definitions);
        foreach ($definitionOfTypeNames as $definitionOfTypeName) {
            $references[] = new Reference($definitionOfTypeName);
        }

        return $references;
    }

    private function isArrayType(ReflectionParameter $reflectionParameter): bool
    {
        if ($reflectionParameter->getType() === null) {
            return false;
        }

        $reflectionParameterType = $reflectionParameter->getType();
        return $reflectionParameterType->getName() === 'array';
    }
}
