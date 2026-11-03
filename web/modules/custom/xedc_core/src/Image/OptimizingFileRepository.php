<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Image;

use Drupal\Core\File\FileExists;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
use Drupal\file\FileInterface;
use Drupal\file\FileRepositoryInterface;

/**
 * Optimizes a temporary image before it is moved into public storage.
 *
 * Copies and writes still go through the original repository. Private files
 * are not rewritten here.
 */
final class OptimizingFileRepository implements FileRepositoryInterface {

  public function __construct(
    protected FileRepositoryInterface $inner,
    protected ImageOptimizeApplier $applier,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function writeData(string $data, string $destination, FileExists|int $fileExists = FileExists::Rename): FileInterface {
    return $this->inner->writeData($data, $destination, $fileExists);
  }

  /**
   * {@inheritdoc}
   */
  public function copy(FileInterface $source, string $destination, FileExists|int $fileExists = FileExists::Rename): FileInterface {
    return $this->inner->copy($source, $destination, $fileExists);
  }

  /**
   * {@inheritdoc}
   */
  public function move(FileInterface $source, string $destination, FileExists|int $fileExists = FileExists::Rename): FileInterface {
    if ($this->publicDestination($destination) && StreamWrapperManager::getScheme($source->getFileUri()) === 'temporary') {
      $this->applier->beforePublicTransfer($source);
    }
    return $this->inner->move($source, $destination, $fileExists);
  }

  /**
   * {@inheritdoc}
   */
  public function loadByUri(string $uri): ?FileInterface {
    return $this->inner->loadByUri($uri);
  }

  private function publicDestination(string $destination): bool {
    return StreamWrapperManager::getScheme($destination) === 'public';
  }

}
