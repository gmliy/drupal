<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\xedc_ai\Form\DuplicateScanForm;
use Drupal\xedc_ai\Review\DuplicateFinder;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Stored exact and suspected duplicates. Opening the page does not hash files.
 */
final class DuplicateController extends ControllerBase {

  public function __construct(
    private readonly DuplicateFinder $duplicates,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('xedc_ai.duplicates'));
  }

  /**
   * Duplicate report.
   */
  public function page(): array {
    $distance = (int) $this->config('xedc_ai.rules')->get('phash_distance');
    if ($distance < 1) {
      $distance = 10;
    }
    $exact = $this->duplicates->exact();
    $similar = $this->duplicates->similar($distance);
    return [
      '#theme' => 'xedc_ai_duplicates',
      '#exact' => $exact['groups'],
      '#similar' => $similar['pairs'],
      '#scanned' => $exact['scanned'],
      '#similar_scanned' => $similar['scanned'],
      '#similar_limited' => $similar['limited'],
      '#distance' => $distance,
      '#scan' => $this->currentUser()->hasPermission('execute xedc ai reviews') ? $this->formBuilder()->getForm(DuplicateScanForm::class) : NULL,
      '#attached' => ['library' => ['xedc_ai/admin']],
      '#cache' => ['max-age' => 0],
    ];
  }

}
