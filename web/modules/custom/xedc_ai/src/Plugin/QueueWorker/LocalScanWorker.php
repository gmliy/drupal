<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Plugin\QueueWorker;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\xedc_ai\Review\LocalInspector;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Local file and text checks. This worker never calls a model.
 */
#[QueueWorker(
  id: 'xedc_ai_local',
  title: new TranslatableMarkup('XEDC AI local duplicate scan'),
  cron: ['time' => 30],
)]
final class LocalScanWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected LocalInspector $local,
    protected EntityTypeManagerInterface $entities,
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
      $container->get('xedc_ai.local'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $nid = (int) (is_array($data) ? ($data['nid'] ?? 0) : 0);
    $node = $nid > 0 ? $this->entities->getStorage('node')->load($nid) : NULL;
    if ($node instanceof NodeInterface) {
      $this->local->inspect($node);
    }
  }

}
