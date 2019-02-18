<?php

namespace Doctrine\Tests\Persistence\Mapping;

use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Common\Cache\Cache;
use Doctrine\Persistence\Mapping\AbstractClassMetadataFactory;
use Doctrine\Persistence\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Persistence\Mapping\MappingException;
use Doctrine\Persistence\Mapping\ReflectionService;
use Doctrine\Tests\DoctrineTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use stdClass;

/**
 * @covers \Doctrine\Persistence\Mapping\AbstractClassMetadataFactory
 */
class ClassMetadataFactoryTest extends DoctrineTestCase
{
    /** @var TestClassMetadataFactory */
    private $cmf;

    public function setUp()
    {
        $driver    = $this->createMock(MappingDriver::class);
        $metadata  = $this->createMock(ClassMetadata::class);
        $this->cmf = new TestClassMetadataFactory($driver, $metadata);
    }

    public function testGetCacheDriver()
    {
        self::assertNull($this->cmf->getCacheDriver());
        $cache = new ArrayCache();
        $this->cmf->setCacheDriver($cache);
        self::assertSame($cache, $this->cmf->getCacheDriver());
    }

    public function testGetMetadataFor()
    {
        $metadata = $this->cmf->getMetadataFor('stdClass');

        self::assertInstanceOf(ClassMetadata::class, $metadata);
        self::assertTrue($this->cmf->hasMetadataFor('stdClass'));
    }

    public function testGetMetadataForAbsentClass()
    {
        $this->expectException(MappingException::class);
        $this->cmf->getMetadataFor(__NAMESPACE__ . '\AbsentClass');
    }

    public function testGetParentMetadata()
    {
        $metadata = $this->cmf->getMetadataFor(ChildEntity::class);

        self::assertInstanceOf(ClassMetadata::class, $metadata);
        self::assertTrue($this->cmf->hasMetadataFor(ChildEntity::class));
        self::assertTrue($this->cmf->hasMetadataFor(RootEntity::class));
    }

    public function testGetCachedMetadata()
    {
        $metadata = $this->createMock(ClassMetadata::class);
        $cache    = new ArrayCache();
        $cache->save(ChildEntity::class . '$CLASSMETADATA', $metadata);

        $this->cmf->setCacheDriver($cache);

        self::assertSame($metadata, $this->cmf->getMetadataFor(ChildEntity::class));
    }

    public function testCacheGetMetadataFor()
    {
        $cache = new ArrayCache();
        $this->cmf->setCacheDriver($cache);

        $loadedMetadata = $this->cmf->getMetadataFor(ChildEntity::class);

        self::assertSame($loadedMetadata, $cache->fetch(ChildEntity::class . '$CLASSMETADATA'));
    }

    public function testGetAliasedMetadata()
    {
        $this->cmf->getMetadataFor('prefix:ChildEntity');

        self::assertTrue($this->cmf->hasMetadataFor(__NAMESPACE__ . '\ChildEntity'));
        self::assertTrue($this->cmf->hasMetadataFor('prefix:ChildEntity'));
    }

    /**
     * @group DCOM-270
     */
    public function testGetInvalidAliasedMetadata()
    {
        $this->expectException(MappingException::class);
        $this->expectExceptionMessage(
            'Class \'Doctrine\Tests\Persistence\Mapping\ChildEntity:Foo\' does not exist'
        );

        $this->cmf->getMetadataFor('prefix:ChildEntity:Foo');
    }

    /**
     * @group DCOM-270
     */
    public function testClassIsTransient()
    {
        self::assertTrue($this->cmf->isTransient('prefix:ChildEntity:Foo'));
    }

    public function testWillFallbackOnNotLoadedMetadata()
    {
        $classMetadata = $this->createMock(ClassMetadata::class);

        $this->cmf->fallbackCallback = static function () use ($classMetadata) {
            return $classMetadata;
        };

        $this->cmf->metadata = null;

        self::assertSame($classMetadata, $this->cmf->getMetadataFor('Foo'));
    }

    public function testWillFailOnFallbackFailureWithNotLoadedMetadata()
    {
        $this->cmf->fallbackCallback = static function () {
            return null;
        };

        $this->cmf->metadata = null;

        $this->expectException(MappingException::class);

        $this->cmf->getMetadataFor('Foo');
    }

    /**
     * @group 717
     */
    public function testWillIgnoreCacheEntriesThatAreNotMetadataInstances()
    {
        /** @var Cache|MockObject $cacheDriver */
        $cacheDriver = $this->createMock(Cache::class);

        $this->cmf->setCacheDriver($cacheDriver);

        $cacheDriver->expects(self::once())->method('fetch')->with('Foo$CLASSMETADATA')->willReturn(new stdClass());

        /** @var ClassMetadata $metadata */
        $metadata = $this->createMock(ClassMetadata::class);

        /** @var MockObject|stdClass|callable $fallbackCallback */
        $fallbackCallback = $this->getMockBuilder(stdClass::class)->setMethods(['__invoke'])->getMock();

        $fallbackCallback->expects(self::any())->method('__invoke')->willReturn($metadata);

        $this->cmf->fallbackCallback = $fallbackCallback;

        self::assertSame($metadata, $this->cmf->getMetadataFor('Foo'));
    }

    public function testFallbackMetadataShouldBeCached() : void
    {
        /** @var Cache|MockObject $cacheDriver */
        $cacheDriver = $this->createMock(Cache::class);
        $cacheDriver->expects(self::once())->method('save');

        $this->cmf->setCacheDriver($cacheDriver);

        $classMetadata = $this->createMock(ClassMetadata::class);

        $this->cmf->fallbackCallback = static function () use ($classMetadata) {
            return $classMetadata;
        };

        $this->cmf->getMetadataFor('Foo');
    }

    public function testSetMetadataForShouldUpdateCache() : void
    {
        /** @var Cache|MockObject $cacheDriver */
        $cacheDriver = $this->createMock(Cache::class);
        $cacheDriver->expects(self::once())->method('save');

        $this->cmf->setCacheDriver($cacheDriver);

        $classMetadata = $this->createMock(ClassMetadata::class);
        $this->cmf->setMetadataFor('Foo', $classMetadata);
    }
}

class TestClassMetadataFactory extends AbstractClassMetadataFactory
{
    /** @var MappingDriver */
    public $driver;

    /** @var ClassMetadata|null */
    public $metadata;

    /** @var callable|null */
    public $fallbackCallback;

    public function __construct($driver, $metadata)
    {
        $this->driver   = $driver;
        $this->metadata = $metadata;
    }

    /**
     * @param string[] $nonSuperclassParents
     */
    protected function doLoadMetadata($class, $parent, $rootEntityFound, array $nonSuperclassParents)
    {
    }

    protected function getFqcnFromAlias($namespaceAlias, $simpleClassName)
    {
        return __NAMESPACE__ . '\\' . $simpleClassName;
    }

    protected function initialize()
    {
    }

    protected function newClassMetadataInstance($className)
    {
        return $this->metadata;
    }

    protected function getDriver()
    {
        return $this->driver;
    }
    protected function wakeupReflection(ClassMetadata $class, ReflectionService $reflService)
    {
    }

    protected function initializeReflection(ClassMetadata $class, ReflectionService $reflService)
    {
    }

    protected function isEntity(ClassMetadata $class)
    {
        return true;
    }

    protected function onNotFoundMetadata($className)
    {
        if (! $this->fallbackCallback) {
            return null;
        }

        return ($this->fallbackCallback)();
    }

    public function isTransient($class)
    {
        return true;
    }
}

class RootEntity
{
}

class ChildEntity extends RootEntity
{
}
