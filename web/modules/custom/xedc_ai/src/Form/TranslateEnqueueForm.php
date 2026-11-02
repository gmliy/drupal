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
 * Queues a translation suggestion. It does not write a translation.
 */
final class TranslateEnqueueForm extends FormBase {

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
    return 'xedc_ai_translate_enqueue';
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
    $form['langcode'] = [
      '#type' => 'select',
      '#title' => $this->t('建议语言'),
      '#options' => [
        'en' => 'English',
        'ja' => '日本語',
        'ru' => 'Русский',
      ],
      '#default_value' => 'en',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('加入翻译建议队列'),
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
      $this->messenger()->addError($this->t('只能为文章、美图或视频生成翻译建议。'));
      return;
    }
    $langcode = (string) $form_state->getValue('langcode');
    if (!in_array($langcode, ['en', 'ja', 'ru'], TRUE)) {
      $langcode = 'en';
    }
    $id = $this->enqueuer->request($node, (int) $this->currentUser()->id(), 'translation', 'translation', TRUE, FALSE, $langcode);
    $this->messenger()->addStatus($this->t('已加入翻译建议 #@id。结果只保存为建议，不会写入译文。', ['@id' => $id]));
  }

}
