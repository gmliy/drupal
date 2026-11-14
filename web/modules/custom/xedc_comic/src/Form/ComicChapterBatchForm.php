<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Batch-create comic chapters from a textarea list.
 */
final class ComicChapterBatchForm extends FormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('entity_type.manager'));
  }

  public function getFormId(): string {
    return 'xedc_comic_chapter_batch';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['comic'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('漫画'),
      '#target_type' => 'node',
      '#selection_settings' => ['target_bundles' => ['comic' => 'comic']],
      '#required' => TRUE,
    ];
    $form['start_number'] = [
      '#type' => 'number',
      '#title' => $this->t('起始章节序号'),
      '#default_value' => 1,
      '#step' => '0.01',
    ];
    $form['titles'] = [
      '#type' => 'textarea',
      '#title' => $this->t('章节标题（一行一章）'),
      '#required' => TRUE,
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('批量创建'),
    ];
    return \Drupal::service('xedc_comic.admin_shell')->page(
      $form,
      'xedc_comic.admin_chapter_batch',
      (string) $this->t('批量创建章节'),
      (string) $this->t('按行录入章节标题，自动递增序号。'),
    );
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $comic = $this->entities->getStorage('node')->load((int) $form_state->getValue('comic'));
    if (!$comic instanceof NodeInterface) {
      $this->messenger()->addError($this->t('漫画无效。'));
      return;
    }
    $number = (float) $form_state->getValue('start_number');
    $lines = array_filter(array_map('trim', explode("\n", (string) $form_state->getValue('titles'))));
    $count = 0;
    foreach ($lines as $title) {
      $chapter = $this->entities->getStorage('node')->create([
        'type' => 'comic_chapter',
        'title' => $title,
        'status' => 1,
        'field_comic_parent' => (int) $comic->id(),
        'field_comic_number' => $number,
        'field_comic_chapter_status' => 'published',
        'field_comic_publish_time' => \Drupal::time()->getRequestTime(),
      ]);
      $chapter->save();
      $number += 1;
      $count++;
    }
    $this->messenger()->addStatus($this->t('已创建 @count 个章节。', ['@count' => $count]));
    $form_state->setRedirect('xedc_comic.admin_chapters', [], ['query' => ['comic' => $comic->id()]]);
  }

}