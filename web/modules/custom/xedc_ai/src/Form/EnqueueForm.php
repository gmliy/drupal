<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Drupal\xedc_ai\Review\ReviewEnqueuer;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Manually queues a text review. Submitting does not publish the node.
 */
final class EnqueueForm extends FormBase {

  public function __construct(
    private readonly ReviewEnqueuer $enqueuer,
    private readonly EntityTypeManagerInterface $entities,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_ai.enqueuer'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_ai_enqueue';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attributes']['class'][] = 'xedc-ai-form';
    $form['nid'] = [
      '#type' => 'number',
      '#title' => $this->t('内容 ID'),
      '#min' => 1,
      '#required' => TRUE,
    ];
    $depths = ['quick' => (string) $this->t('初审')];
    if ($this->config('xedc_ai.settings')->get('deep_analysis')) {
      $depths['deep'] = (string) $this->t('深度分析');
    }
    $form['depth'] = [
      '#type' => 'select',
      '#title' => $this->t('审核深度'),
      '#options' => $depths,
      '#default_value' => 'quick',
    ];
    $form['source'] = [
      '#type' => 'select',
      '#title' => $this->t('任务类型'),
      '#options' => [
        'content_review' => (string) $this->t('内容审核'),
        'seo_optimization' => (string) $this->t('SEO 建议'),
      ],
      '#default_value' => 'content_review',
    ];
    $form['over_budget'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('我确认本次可以在超出预算后继续调用'),
      '#access' => $this->currentUser()->hasPermission('administer xedc ai') && (bool) $this->config('xedc_ai.settings')->get('allow_manual_over_budget'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('加入队列'),
      '#access' => $this->currentUser()->hasPermission('execute xedc ai reviews'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->currentUser()->hasPermission('execute xedc ai reviews')) {
      return;
    }
    $node = $this->entities->getStorage('node')->load((int) $form_state->getValue('nid'));
    if (!$node instanceof NodeInterface || !in_array($node->bundle(), ['article', 'photo', 'video'], TRUE)) {
      $this->messenger()->addError($this->t('只能审核文章、美图或视频。'));
      return;
    }
    $over = (bool) $form_state->getValue('over_budget')
      && $this->currentUser()->hasPermission('administer xedc ai')
      && (bool) $this->config('xedc_ai.settings')->get('allow_manual_over_budget');
    $source = (string) $form_state->getValue('source') === 'seo_optimization' ? 'seo_optimization' : 'content_review';
    $depth = (string) $form_state->getValue('depth');
    if ($source === 'seo_optimization') {
      $depth = 'seo';
    }
    $id = $this->enqueuer->request($node, (int) $this->currentUser()->id(), $source, $depth, TRUE, $over);
    $this->messenger()->addStatus($this->t('已加入队列 #@id。模型会在队列中调用，这个页面没有等待模型返回。', ['@id' => $id]));
  }

}
