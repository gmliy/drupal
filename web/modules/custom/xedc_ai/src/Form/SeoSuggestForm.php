<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\xedc_ai\Review\ReviewEnqueuer;
use Drupal\xedc_ai\Review\ReviewRepository;
use Drupal\xedc_ai\Review\TextExtractor;
use Drupal\xedc_ai\Seo\ContentLookup;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Queues one SEO analysis for the current revision. It does not call a model.
 */
final class SeoSuggestForm extends FormBase {

  public function __construct(
    private readonly ReviewEnqueuer $enqueuer,
    private readonly TextExtractor $extractor,
    private readonly ContentLookup $lookup,
    private readonly ReviewRepository $reviews,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_ai.enqueuer'),
      $container->get('xedc_ai.extractor'),
      $container->get('xedc_ai.lookup'),
      $container->get('xedc_ai.reviews'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_ai_seo_suggest';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attributes']['class'][] = 'xedc-ai-form';
    $open = $this->reviews->latestOpenForUser((int) $this->currentUser()->id(), 'seo_optimization');
    $openId = (int) ($form_state->get('seo_review_id') ?: ($open['id'] ?? 0));
    $form['nid'] = [
      '#type' => 'number',
      '#title' => $this->t('内容 ID'),
      '#placeholder' => (string) $this->t('请输入内容 ID'),
      '#min' => 1,
      '#required' => TRUE,
    ];
    $form['preview'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'xedc-ai-seo-preview',
        'class' => ['xedc-ai-seo-preview'],
        'data-xedc-seo-preview' => '1',
        'data-xedc-seo-lookup' => Url::fromRoute('xedc_ai.seo_lookup')->toString(),
        'hidden' => 'hidden',
      ],
    ];
    $form['help'] = [
      '#markup' => '<p class="xedc-ai-detail">' . $this->t('输入需要进行 SEO 分析的内容 ID，系统将自动读取当前修订版本。') . '</p>',
    ];
    $form['job'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'xedc-ai-seo-job',
        'data-xedc-seo-job' => '1',
        'data-xedc-seo-status-base' => Url::fromRoute('xedc_ai.seo')->toString() . '/status/',
        'data-review-id' => $openId > 0 ? (string) $openId : '',
      ],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('开始 SEO 分析'),
      '#button_type' => 'primary',
      '#access' => $this->currentUser()->hasPermission('execute xedc ai reviews'),
      '#ajax' => [
        'callback' => '::ajaxSubmit',
        'wrapper' => 'xedc-ai-seo-job',
        'progress' => ['type' => 'none'],
      ],
      '#attributes' => [
        'data-xedc-seo-start' => '1',
        'data-xedc-seo-idle-label' => (string) $this->t('开始 SEO 分析'),
        'data-xedc-seo-busy-label' => (string) $this->t('分析中'),
      ],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $found = $this->lookup->identify((int) $form_state->getValue('nid'), 0, $this->currentUser(), '');
    if (!$found['ok']) {
      $form_state->setErrorByName('nid', (string) $found['message']);
      return;
    }
    $form_state->set('seo_content', $found['content']);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->currentUser()->hasPermission('execute xedc ai reviews')) {
      return;
    }
    $content = $form_state->get('seo_content');
    if (!is_array($content)) {
      return;
    }
    $node = $this->extractor->loadRevision('node', (int) $content['nid'], (int) $content['current_vid'], TRUE);
    if (!$node instanceof NodeInterface || !$node->access('view')) {
      $form_state->setErrorByName('nid', (string) $this->t('没有可分析的内容。'));
      return;
    }
    $id = $this->enqueuer->request($node, (int) $this->currentUser()->id(), 'seo_optimization', 'seo', FALSE, FALSE);
    $form_state->set('seo_review_id', $id);
    $row = $this->reviews->load($id);
    if ($row !== NULL && in_array((string) $row['status'], ['pending', 'processing'], TRUE)) {
      \Drupal::state()->set('xedc_ai.queue_kick', $id);
    }
    $form_state->setRebuild(TRUE);
  }

  public function ajaxSubmit(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    if ($form_state->getErrors()) {
      $message = implode(' ', array_map('strval', $form_state->getErrors()));
      $response->addCommand(new ReplaceCommand('#xedc-ai-seo-job', '<div id="xedc-ai-seo-job" data-xedc-seo-job="1"><p class="xedc-ai-error">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p></div>'));
      return $response;
    }
    $response->addCommand(new ReplaceCommand('#xedc-ai-seo-job', (string) \Drupal::service('renderer')->renderRoot($form['job'])));
    return $response;
  }

}
