<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\xedc_comic\Entity\ComicPage;
use Drupal\xedc_comic\Service\ComicPageManager;
use Drupal\xedc_comic\Service\ComicStorageManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reorder, bulk delete and batch page number form for a chapter.
 */
final class ComicPageManageForm extends FormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly ComicPageManager $pages,
    private readonly ComicStorageManager $storage,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('xedc_comic.page_manager'),
      $container->get('xedc_comic.storage'),
      $container->get('file_system'),
    );
  }

  public function getFormId(): string {
    return 'xedc_comic_page_manage';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $chapterId = (int) \Drupal::request()->query->get('chapter', 0);
    if ($chapterId < 1) {
      $picker = [
        '#type' => 'container',
        '#attributes' => ['class' => ['xedc-admin-card', 'xedc-comic-pages-picker']],
      ];
      $picker['chapter'] = [
        '#type' => 'entity_autocomplete',
        '#title' => $this->t('选择章节'),
        '#target_type' => 'node',
        '#selection_settings' => ['target_bundles' => ['comic_chapter' => 'comic_chapter']],
        '#required' => TRUE,
      ];
      $picker['go'] = [
        '#type' => 'submit',
        '#value' => $this->t('进入图片管理'),
        '#submit' => ['::submitPickChapter'],
      ];
      return \Drupal::service('xedc_comic.admin_shell')->page(
        $picker,
        'xedc_comic.admin_pages',
        (string) $this->t('图片管理'),
        (string) $this->t('按章节浏览漫画图片，支持排序与替换。'),
      );
    }
    $chapter = $this->entities->getStorage('node')->load($chapterId);
    $pages = $this->entities->getStorage('comic_page')->loadByProperties(['chapter_id' => $chapterId]);
    usort($pages, static fn(ComicPage $a, ComicPage $b) => $a->getPageNumber() <=> $b->getPageNumber());

    $form['#chapter_id'] = $chapterId;
    $form['chapter_label'] = [
      '#markup' => '<h2>' . ($chapter ? $chapter->label() : $chapterId) . '</h2>',
    ];
    $form['toolbar'] = [
      '#type' => 'details',
      '#title' => $this->t('单张替换与插入'),
      '#open' => FALSE,
    ];
    $form['toolbar']['replace_page'] = [
      '#type' => 'number',
      '#title' => $this->t('页码'),
      '#min' => 1,
    ];
    $form['toolbar']['replace_file'] = [
      '#type' => 'file',
      '#title' => $this->t('新图片'),
      '#attributes' => ['accept' => 'image/*'],
    ];
    $form['toolbar']['replace_submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('替换该页'),
      '#submit' => ['::submitReplace'],
    ];

    $form['order'] = [
      '#type' => 'textarea',
      '#title' => $this->t('页序（逗号分隔的页面 ID，从上到下）'),
      '#description' => $this->t('保存后按此顺序重排页码为 1…N。'),
      '#default_value' => implode(',', array_map(static fn(ComicPage $p) => (int) $p->id(), $pages)),
      '#rows' => 3,
    ];
    $form['delete_ids'] = [
      '#type' => 'textfield',
      '#title' => $this->t('批量删除（页面 ID，逗号分隔）'),
    ];

    $rows = [];
    foreach ($pages as $page) {
      $file = $page->getFile();
      $payload = $this->storage->pagePayload($page);
      $url = (string) $payload['url'];
      $rows[] = [
        'id' => (int) $page->id(),
        'page' => $page->getPageNumber(),
        'thumb' => $url,
        'name' => (string) $page->get('original_filename')->value,
        'size' => (int) $page->get('image_width')->value . '×' . (int) $page->get('image_height')->value,
        'bytes' => format_size((int) $page->get('file_size')->value),
        'created' => $file ? \Drupal::service('date.formatter')->format((int) $file->getCreatedTime()) : '',
      ];
    }

    $form['pages'] = [
      '#theme' => 'xedc_comic_admin_pages',
      '#rows' => $rows,
      '#chapter_id' => $chapterId,
    ];
    $form['actions']['save_order'] = [
      '#type' => 'submit',
      '#value' => $this->t('保存排序'),
    ];
    $form['actions']['delete_bulk'] = [
      '#type' => 'submit',
      '#value' => $this->t('批量删除'),
      '#submit' => ['::submitBulkDelete'],
      '#attributes' => ['class' => ['button--danger']],
    ];
    $form['actions']['back'] = [
      '#type' => 'link',
      '#title' => $this->t('章节列表'),
      '#url' => Url::fromRoute('xedc_comic.admin_chapters'),
      '#attributes' => ['class' => ['button']],
    ];

    $form['#attributes']['enctype'] = 'multipart/form-data';
    $form['#attached']['library'][] = 'xedc_comic/admin';
    $form['#attached']['library'][] = 'xedc_comic/page_manage';
    $form['#attributes']['class'][] = 'xedc-comic-page-manage-form';
    return \Drupal::service('xedc_comic.admin_shell')->page(
      $form,
      'xedc_comic.admin_pages',
      (string) $this->t('图片管理'),
      (string) $this->t('本章图片资源：网格/列表视图、排序与批量操作。'),
    );
  }

  public function submitPickChapter(array &$form, FormStateInterface $form_state): void {
    $chapterId = (int) $form_state->getValue('chapter');
    $form_state->setRedirect('xedc_comic.admin_pages', [], ['query' => ['chapter' => $chapterId]]);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $chapterId = (int) $form['#chapter_id'];
    $raw = (string) $form_state->getValue('order');
    $ids = array_values(array_filter(array_map('intval', preg_split('/\s*,\s*/', $raw) ?: [])));
    if ($ids === []) {
      $this->messenger()->addWarning($this->t('未提供排序 ID。'));
      return;
    }
    $this->pages->reorderPages($chapterId, $ids);
    $this->messenger()->addStatus($this->t('页序已更新。'));
  }

  public function submitBulkDelete(array &$form, FormStateInterface $form_state): void {
    $chapterId = (int) $form['#chapter_id'];
    $raw = (string) $form_state->getValue('delete_ids');
    if ($raw === '') {
      $this->messenger()->addWarning($this->t('请填写要删除的页面 ID。'));
      return;
    }
    $ids = array_values(array_filter(array_map('intval', preg_split('/\s*,\s*/', $raw) ?: [])));
    $n = $this->pages->deletePages($chapterId, $ids);
    $this->messenger()->addStatus($this->t('已删除 @n 页。', ['@n' => $n]));
    $form_state->setRedirect('xedc_comic.admin_pages', [], ['query' => ['chapter' => $chapterId]]);
  }

  public function submitReplace(array &$form, FormStateInterface $form_state): void {
    $chapterId = (int) $form['#chapter_id'];
    $pageNumber = (int) $form_state->getValue('replace_page');
    if ($pageNumber < 1) {
      $this->messenger()->addError($this->t('请填写有效页码。'));
      return;
    }
    $validators = ['FileExtension' => ['extensions' => 'png gif jpg jpeg webp']];
    $uploaded = file_save_upload('replace_file', $validators, 'temporary://', FileSystemInterface::EXISTS_RENAME);
    if (!$uploaded) {
      $this->messenger()->addError($this->t('请选择图片。'));
      return;
    }
    $file = is_array($uploaded) ? reset($uploaded) : $uploaded;
    $real = $this->fileSystem->realpath($file->getFileUri());
    if (!is_string($real) || !is_file($real)) {
      $this->messenger()->addError($this->t('无法读取上传文件。'));
      return;
    }
    $pages = $this->entities->getStorage('comic_page')->loadByProperties([
      'chapter_id' => $chapterId,
      'page_number' => $pageNumber,
    ]);
    $page = $pages ? reset($pages) : NULL;
    if (!$page instanceof ComicPage) {
      $this->messenger()->addError($this->t('该页码不存在。'));
      return;
    }
    try {
      $this->pages->replacePageFile($page, $real, $file->getFilename());
      $this->messenger()->addStatus($this->t('第 @p 页已替换。', ['@p' => $pageNumber]));
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($e->getMessage());
    }
    finally {
      $this->fileSystem->delete($file->getFileUri());
    }
    $form_state->setRedirect('xedc_comic.admin_pages', [], ['query' => ['chapter' => $chapterId]]);
  }

}