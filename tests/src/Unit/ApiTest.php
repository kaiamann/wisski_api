<?php

namespace Drupal\Tests\wisski_api\Unit;

use Drupal\Component\Serialization\Json;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeRepositoryInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\wisski_api\Plugin\wisski_api\NoSuchEntityException;
use Drupal\wisski_api\Plugin\wisski_api\WisskiApiV0;
use Drupal\wisski_core\Entity\WisskiEntity;
use Drupal\wisski_core\WisskiStorage;
use Drupal\wisski_pathbuilder\Entity\WisskiPathbuilderEntity;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Test class for API Plugins.
 */
class ApiTest extends UnitTestCase {

  /**
   * The container.
   *
   * @var \Drupal\Core\DependencyInjection\ContainerInjectionInterface
   */
  protected $container;

  /**
   * Mocks services, duh.
   */
  protected function mockServices() {
    /** @var \PHPUnit\Framework\MockObject\MockObject */
    $wisskiStorage = $this->createMock(WisskiStorage::class);

    // Mock entityTypeManager.
    /** @var \PHPUnit\Framework\MockObject\MockObject */
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->expects($this->any())
      ->method('getStorage')
      ->willReturn($wisskiStorage);

    // Mock accountProxyInterface.
    $accountProxyInterface = $this->createMock(AccountProxyInterface::class);

    // Mock serializer.
    /** @var \PHPUnit\Framework\MockObject\MockObject */
    $serializer = $this->createMock(SerializerInterface::class);
    $serializer->expects($this->any())
      ->method('serialize')
      ->will(
        $this->returnCallback(function ($data, $format, $context = []) {
          if ($format == "json") {
            return Json::encode($data);
          }
          else {
            return [];
          }
        })
      );

    /** @var \PHPUnit\Framework\MockObject\MockObject */
    $entityTypeRepository = $this->createMock(EntityTypeRepositoryInterface::class);
    $entityTypeRepository->expects($this->any())
      ->method('getEntityTypeFromClass')
      ->will(
        $this->returnCallback(function ($class) {
          switch ($class) {
            case WisskiEntity::class:
              return 'wisski_individual';

            case WisskiPathbuilderEntity::class:
              return 'wisski_pathbuilder';
          }
        })
      );

    $container = new ContainerBuilder();
    $container->set('entity_type.manager', $entityTypeManager);
    $container->set('current_user', $accountProxyInterface);
    $container->set('serializer', $serializer);
    $container->set('entity_type.repository', $entityTypeRepository);
    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  public function testPatbuilderList(): void {
    $this->mockServices();

    $expected = [
      "pb1",
      "pb2",
      "pb3",
      "pb4",
    ];

    // Mock pb query.
    $queryRet = array_combine($expected, $expected);
    /** @var \PHPUnit\Framework\MockObject\MockObject */
    $pbQuery = $this->createMock(QueryInterface::class);
    $pbQuery->expects($this->any())
      ->method('execute')
      ->willReturn($queryRet);
    $pbQuery->expects($this->any())
      ->method('range')
      ->will(
          $this->returnCallback(function ($start, $limit) use ($pbQuery) {
            return $pbQuery;
          })
        );

    // Attach query to Storage.
    /** @var \PHPUnit\Framework\MockObject\MockObject */
    $storage = \Drupal::service('entity_type.manager')->getStorage('wisski_pathbuilder');
    $storage->expects($this->any())
      ->method('getQuery')
      ->willReturn($pbQuery);

    $container = \Drupal::getContainer();
    $api = WisskiApiV0::create($container, [], "wisski_api.v0", []);
    $actual = $api->getPathbuilderIds();
    $this->assertEqualsCanonicalizing($expected, $actual);
  }

  /**
   * Undocumented function.
   */
  public function testGetPathbuilder() {
    $this->mockServices();
    $pbId = "someRandomId";
    /** @var \PHPUnit\Framework\MockObject\MockObject */
    $expected = $this->createMock(WisskiPathbuilderEntity::class);

    /** @var \PHPUnit\Framework\MockObject\MockObject */
    $storage = \Drupal::service('entity_type.manager')->getStorage('wisski_pathbuilder');
    $storage->expects($this->any())
      ->method('load')
      ->will(
        $this->returnCallback(
          function ($id) use ($pbId, $expected) {
            return $id === $pbId ? $expected : [];
          }
        )
      );

    $container = \Drupal::getContainer();
    $api = WisskiApiV0::create($container, [], "wisski_api.v0", []);
    $actual = $api->getPathbuilder($pbId);
    $this->assertEqualsCanonicalizing($expected, $actual);

    // Test with invalid input.
    $this->expectException(NoSuchEntityException::class);
    $actual = $api->getPathbuilder("bogus");
  }

}
