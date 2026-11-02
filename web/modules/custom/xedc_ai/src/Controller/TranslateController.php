<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\xedc_ai\Form\TranslateEnqueueForm;
use Drupal\xedc_ai\Labels;
use Drupal\xedc_ai\Provider\ModelRepository;
use Drupal\xedc_ai\Review\ReviewRepository;
use Drupal\xedc_auto_translate\Translator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Translation suggestions. Publishing a translation stays on the existing module.
 */
final class TranslateController extends ControllerBase {

  public function __construct(
    private readonly Translator $translator,
    private readonly ModelRepository $models,
    private readonly ReviewRepository $reviews,
    private readonly DateFormatterInterface $dates,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_auto_translate.translator'),
      $container->get('xedc_ai.models'),
      $container->get('xedc_ai.reviews'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Translation management page.
   */
  public function page(): array {
    $provider = (string) $this->config('xedc_auto_translate.settings')->get('provider');
    $profile = $this->models->forTask('translation');
    $rows = [];
    foreach ($this->reviews->recent(12, 'translation') as $row) {
      $rows[] = [
        'title' => (string) $row['title'],
        'status' => Labels::status((string) $row['status']),
        'time' => $this->dates->format((int) $row['created'], 'short'),
        'url' => Url::fromRoute('xedc_ai.review', ['review' => $row['id']])->toString(),
      ];
    }
    return [
      '#theme' => 'xedc_ai_translate',
      '#provider' => $provider === 'deepl' ? 'DeepL' : 'Microsoft Translator',
      '#configured' => $this->translator->hasStoredApiKey($provider),
      '#settings_url' => Url::fromRoute('xedc_auto_translate.settings')->toString(),
      '#model' => $profile ? $profile->providerLabel . ' / ' . $profile->modelName : '未设置',
      '#rows' => $rows,
      '#enqueue' => $this->currentUser()->hasPermission('execute xedc ai reviews') ? $this->formBuilder()->getForm(TranslateEnqueueForm::class) : NULL,
      '#attached' => ['library' => ['xedc_ai/admin']],
      '#cache' => ['max-age' => 0],
    ];
  }

}
