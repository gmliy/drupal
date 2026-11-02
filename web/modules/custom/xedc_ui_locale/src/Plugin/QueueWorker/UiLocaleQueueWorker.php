<?php

declare(strict_types=1);

namespace Drupal\xedc_ui_locale\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\xedc_ui_locale\UiLocale;
use Drupal\xedc_ui_locale\UiTranslationManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Runs interface translation jobs outside the content translation flow.
 */
#[QueueWorker(
  id: UiLocale::QUEUE,
  title: new TranslatableMarkup('XEDC interface translation'),
  cron: ['time' => 60],
)]
final class UiLocaleQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    string $plugin_id,
    array $plugin_definition,
    private readonly UiTranslationManager $manager,
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
      $container->get(UiTranslationManager::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $this->manager->handleQueueItem(is_array($data) ? $data : []);
  }

}
