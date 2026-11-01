<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\xedc_core\Ops\FileScanner;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Continues the file scan during cron, one batch at a time.
 */
#[QueueWorker(
  id: 'xedc_file_scan',
  title: new TranslatableMarkup('XEDC file scan'),
  cron: ['time' => 20],
)]
class FileScanWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected FileScanner $scanner,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('xedc_core.file_scanner'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $result = $this->scanner->step();
    if ($result === 'more') {
      throw new RequeueException();
    }
    if ($result === 'busy') {
      throw new DelayedRequeueException(15);
    }
  }

}
