<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\xedc_core\Video\TranscodeManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Runs one playback transcode at a time. The manager holds the lock.
 */
#[QueueWorker(
  id: 'xedc_video_transcode',
  title: new TranslatableMarkup('Video playback transcode'),
  cron: ['time' => 60],
)]
final class VideoTranscodeWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly TranscodeManager $transcodes,
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
      $container->get('xedc_core.video_transcode'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (!is_array($data)) {
      return;
    }
    $this->transcodes->run((int) ($data['fid'] ?? 0));
  }

}
