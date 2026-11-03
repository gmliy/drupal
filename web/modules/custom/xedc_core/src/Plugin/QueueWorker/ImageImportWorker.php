<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\xedc_core\ImportProcessor;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes image import tasks during cron.
 */
#[QueueWorker(
  id: 'xedc_image_import',
  title: new TranslatableMarkup('XEDC image import'),
  cron: ['time' => 0],
)]
class ImageImportWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ImportProcessor $processor,
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
      $container->get('xedc_core.processor'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $taskId = (int) (is_array($data) ? ($data['task_id'] ?? 0) : 0);
    $result = $this->processor->processBatch($taskId);
    if ($result === 'more') {
      throw new RequeueException();
    }
    if ($result === 'busy') {
      throw new DelayedRequeueException(2);
    }
  }

}
