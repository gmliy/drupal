<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\node\NodeInterface;
use Drupal\xedc_comic\Service\ComicImportManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * ZIP import form for comic chapters.
 */
final class ComicImportForm extends FormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly ComicImportManager $imports,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('xedc_comic.import'),
    );
  }

  public function getFormId(): string {
    return 'xedc_comic_import';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['comic'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('漫画'),
      '#target_type' => 'node',
      '#selection_settings' => ['target_bundles' => ['comic' => 'comic']],
      '#required' => TRUE,
    ];
    $form['chapter_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('章节'),
      '#options' => [
        'new' => $this->t('创建新章节'),
        'existing' => $this->t('选择现有章节'),
      ],
      '#default_value' => 'existing',
    ];
    $form['chapter'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('现有章节'),
      '#target_type' => 'node',
      '#selection_settings' => ['target_bundles' => ['comic_chapter' => 'comic_chapter']],
      '#states' => ['visible' => [':input[name="chapter_mode"]' => ['value' => 'existing']]],
    ];
    $form['chapter_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('新章节标题'),
      '#states' => ['visible' => [':input[name="chapter_mode"]' => ['value' => 'new']]],
    ];
    $form['chapter_number'] = [
      '#type' => 'number',
      '#title' => $this->t('章节序号'),
      '#step' => '0.01',
      '#states' => ['visible' => [':input[name="chapter_mode"]' => ['value' => 'new']]],
    ];
    $form['archive'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('ZIP 压缩包'),
      '#upload_location' => 'private://xedc-comic-import',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'zip'],
      ],
      '#required' => TRUE,
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('开始导入'),
    ];
    $form['#attached']['library'][] = 'xedc_comic/admin';
    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $comic = $this->entities->getStorage('node')->load((int) $form_state->getValue('comic'));
    if (!$comic instanceof NodeInterface || $comic->bundle() !== 'comic') {
      $this->messenger()->addError($this->t('漫画无效。'));
      return;
    }
    $chapterId = (int) $form_state->getValue('chapter');
    if ($form_state->getValue('chapter_mode') === 'new') {
      $chapter = $this->entities->getStorage('node')->create([
        'type' => 'comic_chapter',
        'title' => (string) $form_state->getValue('chapter_title') ?: $this->t('新章节')->render(),
        'status' => 1,
        'field_comic_parent' => (int) $comic->id(),
        'field_comic_number' => (float) $form_state->getValue('chapter_number') ?: 1,
        'field_comic_chapter_status' => 'published',
        'field_comic_publish_time' => \Drupal::time()->getRequestTime(),
      ]);
      $chapter->save();
      $chapterId = (int) $chapter->id();
    }
    else {
      $chapter = $this->entities->getStorage('node')->load($chapterId);
      if (!$chapter instanceof NodeInterface || $chapter->bundle() !== 'comic_chapter') {
        $this->messenger()->addError($this->t('章节无效。'));
        return;
      }
    }
    $fids = $form_state->getValue('archive');
    $fid = is_array($fids) ? (int) reset($fids) : 0;
    $file = File::load($fid);
    if (!$file) {
      $this->messenger()->addError($this->t('压缩包无效。'));
      return;
    }
    $file->setPermanent();
    $file->save();
    try {
      $job = $this->imports->createJob((int) $comic->id(), $chapterId, (int) $this->currentUser()->id(), $file->getFileUri(), $file->getFilename(), $fid);
      $this->messenger()->addStatus($this->t('导入任务 @id 已创建。', ['@id' => $job['id']]));
      $form_state->setRedirect('xedc_comic.import_status', [], ['query' => ['job' => $job['id']]]);
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($e->getMessage());
    }
  }

}