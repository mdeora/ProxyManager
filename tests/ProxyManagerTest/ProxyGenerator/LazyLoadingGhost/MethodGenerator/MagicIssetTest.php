<?php

declare(strict_types=1);

namespace ProxyManagerTest\ProxyGenerator\LazyLoadingGhost\MethodGenerator;

use PHPUnit\Framework\TestCase;
use ProxyManager\ProxyGenerator\LazyLoadingGhost\MethodGenerator\MagicIsset;
use ProxyManager\ProxyGenerator\LazyLoadingGhost\PropertyGenerator\PrivatePropertiesMap;
use ProxyManager\ProxyGenerator\LazyLoadingGhost\PropertyGenerator\ProtectedPropertiesMap;
use ProxyManager\ProxyGenerator\PropertyGenerator\PublicPropertiesMap;
use ProxyManagerTestAsset\ClassWithMagicMethods;
use ProxyManagerTestAsset\ProxyGenerator\LazyLoading\MethodGenerator\ClassWithTwoPublicProperties;
use ReflectionClass;
use Zend\Code\Generator\MethodGenerator;
use Zend\Code\Generator\PropertyGenerator;

/**
 * Tests for {@see \ProxyManager\ProxyGenerator\LazyLoadingGhost\MethodGenerator\MagicIsset}
 *
 * @group Coverage
 */
class MagicIssetTest extends TestCase
{
    /** @var PropertyGenerator|\PHPUnit_Framework_MockObject_MockObject */
    protected $initializer;

    /** @var MethodGenerator|\PHPUnit_Framework_MockObject_MockObject */
    protected $initMethod;

    /** @var PublicPropertiesMap|\PHPUnit_Framework_MockObject_MockObject */
    protected $publicProperties;

    /** @var ProtectedPropertiesMap|\PHPUnit_Framework_MockObject_MockObject */
    protected $protectedProperties;

    /** @var PrivatePropertiesMap|\PHPUnit_Framework_MockObject_MockObject */
    protected $privateProperties;

    /** @var string */
    private $expectedCode = <<<'PHP'
$this->foo && $this->baz('__isset', array('name' => $name));

if (isset(self::$bar[$name])) {
    return isset($this->$name);
}

if (isset(self::$baz[$name])) {
    // check protected property access via compatible class
    $callers      = debug_backtrace(\DEBUG_BACKTRACE_PROVIDE_OBJECT, 2);
    $caller       = isset($callers[1]) ? $callers[1] : [];
    $object       = isset($caller['object']) ? $caller['object'] : '';
    $expectedType = self::$baz[$name];

    if ($object instanceof $expectedType) {
        return isset($this->$name);
    }

    $class = isset($caller['class']) ? $caller['class'] : '';

    if ($class === $expectedType || is_subclass_of($class, $expectedType)) {
        return isset($this->$name);
    }
} else {
    // check private property access via same class
    $callers = debug_backtrace(\DEBUG_BACKTRACE_PROVIDE_OBJECT, 2);
    $caller  = isset($callers[1]) ? $callers[1] : [];
    $class   = isset($caller['class']) ? $caller['class'] : '';

    static $accessorCache = [];

    if (isset(self::$tab[$name][$class])) {
        $cacheKey = $class . '#' . $name;
        $accessor = isset($accessorCache[$cacheKey])
            ? $accessorCache[$cacheKey]
            : $accessorCache[$cacheKey] = \Closure::bind(function ($instance) use ($name) {
                return isset($instance->$name);
            }, null, $class);

        return $accessor($this);
    }

    if ('ReflectionProperty' === $class) {
        $tmpClass = key(self::$tab[$name]);
        $cacheKey = $tmpClass . '#' . $name;
        $accessor = isset($accessorCache[$cacheKey])
            ? $accessorCache[$cacheKey]
            : $accessorCache[$cacheKey] = \Closure::bind(function ($instance) use ($name) {
                return isset($instance->$name);
            }, null, $tmpClass);

        return $accessor($this);
    }
}
%A
PHP;

    /**
     * {@inheritDoc}
     */
    protected function setUp() : void
    {
        $this->initializer         = $this->createMock(PropertyGenerator::class);
        $this->initMethod          = $this->createMock(MethodGenerator::class);
        $this->publicProperties    = $this
            ->getMockBuilder(PublicPropertiesMap::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->protectedProperties = $this
            ->getMockBuilder(ProtectedPropertiesMap::class)
            ->disableOriginalConstructor()
            ->getMock();
        $this->privateProperties   = $this
            ->getMockBuilder(PrivatePropertiesMap::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->initializer->expects(self::any())->method('getName')->will(self::returnValue('foo'));
        $this->initMethod->expects(self::any())->method('getName')->will(self::returnValue('baz'));
        $this->publicProperties->expects(self::any())->method('isEmpty')->will(self::returnValue(false));
        $this->publicProperties->expects(self::any())->method('getName')->will(self::returnValue('bar'));
        $this->protectedProperties->expects(self::any())->method('getName')->will(self::returnValue('baz'));
        $this->privateProperties->expects(self::any())->method('getName')->will(self::returnValue('tab'));
    }

    /**
     * @covers \ProxyManager\ProxyGenerator\LazyLoadingGhost\MethodGenerator\MagicIsset
     */
    public function testBodyStructureWithPublicProperties() : void
    {
        $magicIsset = new MagicIsset(
            new ReflectionClass(ClassWithTwoPublicProperties::class),
            $this->initializer,
            $this->initMethod,
            $this->publicProperties,
            $this->protectedProperties,
            $this->privateProperties
        );

        self::assertSame('__isset', $magicIsset->getName());
        self::assertCount(1, $magicIsset->getParameters());

        self::assertStringMatchesFormat($this->expectedCode, $magicIsset->getBody());
    }

    /**
     * @covers \ProxyManager\ProxyGenerator\LazyLoadingGhost\MethodGenerator\MagicIsset
     */
    public function testBodyStructureWithOverriddenMagicGet() : void
    {
        $magicIsset = new MagicIsset(
            new ReflectionClass(ClassWithMagicMethods::class),
            $this->initializer,
            $this->initMethod,
            $this->publicProperties,
            $this->protectedProperties,
            $this->privateProperties
        );

        self::assertSame('__isset', $magicIsset->getName());
        self::assertCount(1, $magicIsset->getParameters());

        $body = $magicIsset->getBody();

        self::assertStringMatchesFormat($this->expectedCode, $body);
        self::assertStringMatchesFormat('%Areturn parent::__isset($name);', $body);
    }
}
