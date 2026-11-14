<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\xedc_comic\Service\ComicImportManager;
use Drupal\xedc_comic\Service\ComicPdfImportManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes comic import extract, PDF convert and image upload phases.
 *
 * @QueueWorker(
 *   id = "xedc_comic_import",
 *   title = @Translation("漫画章节导入"),
 *   cron = {"time" = 60}
 * )
 */
final class ComicImportWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly ComicImportManager $imports,
    private readonly ComicPdfImportManager $pdfImports,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('xedc_comic.import'),
      $container->get('xedc_comic.pdf_import'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (!is_array($data) || empty($data['import_id'])) {
      return;
    }
    $importId = (int) $data['import_id'];
    $phase = (string) ($data['phase'] ?? 'process');
    match ($phase) {
      'extract' => $this->imports->runExtract($importId),
      'pdf_prepare' => $this->pdfImports->runPrepare($importId),
      'pdf_convert' => $this->pdfImports->runConvertBatch($importId),
      default => $this->imports->runProcessBatch($importId),
    };
  }

}