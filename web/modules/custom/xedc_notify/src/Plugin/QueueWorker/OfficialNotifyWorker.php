<?php

declare(strict_types=1);

namespace Drupal\xedc_notify\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\xedc_notify\Service\OfficialCampaignManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Delivers official notification campaigns in batches.
 */
#[QueueWorker(
  id: 'xedc_official_notify',
  title: new TranslatableMarkup('XEDC official notifications'),
  cron: ['time' => 30],
)]
final class OfficialNotifyWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected OfficialCampaignManager $campaigns,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('xedc_notify.official'),
    );
  }

  public function processItem($data): void {
    $cid = (int) ($data['cid'] ?? 0);
    if ($cid < 1) {
      return;
    }
    $result = $this->campaigns->processBatch($cid);
    if ($result === 'more') {
      throw new RequeueException();
    }
  }

}
