<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\xedc_core\Image\ImageOptimizeApplier;
use Drupal\xedc_core\Image\ImageOptimizeRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Optimizes one queued public image. Cron handles a single item at a time.
 */
#[QueueWorker(
  id: 'xedc_image_optimize',
  title: new TranslatableMarkup('XEDC image optimization'),
  cron: ['time' => 30],
)]
class ImageOptimizeWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ImageOptimizeApplier $applier,
    protected ImageOptimizeRepository $repository,
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
      $container->get('xedc_core.image_optimize'),
      $container->get('xedc_core.image_optimize_repository'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $id = (int) ($data['id'] ?? 0);
    $fid = (int) ($data['fid'] ?? 0);
    $row = $id > 0 ? $this->repository->load($id) : NULL;
    if (!is_array($row) || $row['status'] === 'cancelled' || $row['status'] === 'success') {
      return;
    }
    $limit = max(1, (int) ($this->repository->settings()['concurrency'] ?? 1));
    if ((string) ($row['status'] ?? '') !== 'running' && $this->repository->runningCount() >= $limit) {
      throw new RequeueException();
    }
    $this->applier->process($fid, $id);
  }

}
