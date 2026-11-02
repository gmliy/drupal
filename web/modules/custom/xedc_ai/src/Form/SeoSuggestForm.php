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
 * Queues one SEO suggestion. It does not overwrite SEO fields.
 */
final class SeoSuggestForm extends FormBase {

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
    return 'xedc_ai_seo_suggest';
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
      '#description' => $this->t('只根据标题和正文生成建议。提交后进入队列，不会立即改写内容。'),
    ];
    $form['over_budget'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('我确认本次可以在超出预算后继续调用'),
      '#access' => $this->currentUser()->hasPermission('administer xedc ai') && (bool) $this->config('xedc_ai.settings')->get('allow_manual_over_budget'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('生成建议'),
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
      $this->messenger()->addError($this->t('只能为文章、美图或视频生成文字建议。'));
      return;
    }
    $over = (bool) $form_state->getValue('over_budget')
      && $this->currentUser()->hasPermission('administer xedc ai')
      && (bool) $this->config('xedc_ai.settings')->get('allow_manual_over_budget');
    $id = $this->enqueuer->request($node, (int) $this->currentUser()->id(), 'seo_optimization', 'seo', TRUE, $over);
    $this->messenger()->addStatus($this->t('已加入 SEO 建议队列 #@id。', ['@id' => $id]));
  }

}
