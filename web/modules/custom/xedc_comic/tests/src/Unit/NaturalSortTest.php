<?php

declare(strict_types=1);

namespace Drupal\Tests\xedc_comic\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\xedc_core\ArchiveManager;

/**
 * Ensures comic ZIP scan uses natural sort like core ArchiveManager.
 *
 * @group xedc_comic
 */
class NaturalSortTest extends UnitTestCase {

  public function testNaturalSort(): void {
    $manager = new ArchiveManager(
      $this->createMock(\Drupal\Core\File\FileSystemInterface::class),
      $this->createMock(\Drupal\Core\StreamWrapper\StreamWrapperManagerInterface::class),
      $this->createMock(\Drupal\file\FileUsage\FileUsageInterface::class),
      $this->createMock(\Drupal\Core\Config\ConfigFactoryInterface::class),
      $this->createMock(\Drupal\Core\Session\AccountProxyInterface::class),
    );
    $files = [
      ['relative' => '10.jpg', 'bytes' => 1, 'uri' => ''],
      ['relative' => '2.jpg', 'bytes' => 1, 'uri' => ''],
      ['relative' => '1.jpg', 'bytes' => 1, 'uri' => ''],
    ];
    $sorted = $manager->sortFiles($files);
    $names = array_column($sorted, 'relative');
    $this->assertSame(['1.jpg', '2.jpg', '10.jpg'], $names);
  }

}