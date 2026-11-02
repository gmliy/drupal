<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\xedc_core\Ops\Cleanup\FileAnalysisRunner;
use Drupal\xedc_core\Ops\Cleanup\FileCleanupManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Continues read-only analysis, and confirmed cleanup tasks only.
 */
#[QueueWorker(
  id: 'xedc_file_cleanup',
  title: new TranslatableMarkup('XEDC file cleanup'),
  cron: ['time' => 15],
)]
class FileCleanupWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected FileAnalysisRunner $runner,
    protected FileCleanupManager $manager,
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
      $container->get('xedc_core.file_analysis'),
      $container->get('xedc_core.cleanup_manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $op = is_array($data) ? (string) ($data['op'] ?? '') : '';
    if ($op === 'analyze') {
      $result = $this->runner->step();
      if ($result === 'more' || $result === 'busy') {
        throw new RequeueException();
      }
      return;
    }
    if ($op === 'task') {
      $result = $this->manager->step((int) ($data['id'] ?? 0));
      if ($result === 'more' || $result === 'busy') {
        throw new RequeueException();
      }
    }
  }

}
