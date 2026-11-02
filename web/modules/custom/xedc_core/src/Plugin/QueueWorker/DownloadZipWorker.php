<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\xedc_core\Engagement\ZipBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Prepares a large photo archive outside the page request.
 */
#[QueueWorker(
  id: 'xedc_download_zip',
  title: new TranslatableMarkup('准备图片下载压缩包'),
  cron: ['time' => 90],
)]
final class DownloadZipWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ZipBuilder $zipBuilder,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('xedc_core.zip_builder'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $id = (int) ($data['id'] ?? 0);
    if ($id > 0) {
      $this->zipBuilder->buildQueued($id);
    }
  }

}
