<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\xedc_ai\Review\ReviewRunner;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Runs queued text reviews during cron.
 */
#[QueueWorker(
  id: 'xedc_ai_review',
  title: new TranslatableMarkup('XEDC AI text review'),
  cron: ['time' => 120],
)]
final class AiReviewWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ReviewRunner $runner,
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
      $container->get('xedc_ai.runner'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $reviewId = (int) (is_array($data) ? ($data['review_id'] ?? 0) : 0);
    try {
      $result = $this->runner->process($reviewId);
    }
    catch (\Throwable $exception) {
      $this->runner->failUnexpected($reviewId, $exception);
      return;
    }
    if ($result === 'retry') {
      throw new DelayedRequeueException(60);
    }
    if ($result === 'busy') {
      throw new DelayedRequeueException(20);
    }
  }

}
