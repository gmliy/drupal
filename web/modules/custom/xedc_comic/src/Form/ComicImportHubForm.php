<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\file\Upload\FormUploadedFile;
use Drupal\file\Upload\FileUploadHandlerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Drupal\node\NodeInterface;
use Drupal\xedc_comic\Service\ComicImportManager;
use Drupal\xedc_comic\Service\ComicPageManager;
use Drupal\xedc_comic\Service\ComicPdfConverter;
use Drupal\xedc_comic\Service\ComicPdfImportManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Unified comic chapter import center (ZIP, multi, single).
 */
class ComicImportHubForm extends FormBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly ComicImportManager $imports,
    private readonly ComicPageManager $pages,
    private readonly ComicPdfConverter $pdfConverter,
    private readonly ComicPdfImportManager $pdfImports,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('xedc_comic.import'),
      $container->get('xedc_comic.page_manager'),
      $container->get('xedc_comic.pdf_converter'),
      $container->get('xedc_comic.pdf_import'),
      $container->get('file_system'),
    );
  }

  public function getFormId(): string {
    return 'xedc_comic_import_hub';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    return $this->buildImportFormElements($form, $form_state);
  }

  protected function buildImportFormElements(array $form, FormStateInterface $form_state): array {
    $form['#attributes']['class'][] = 'xedc-comic-import-hub';
    $form['#attributes']['enctype'] = 'multipart/form-data';
    $form['target'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('目标'),
    ];
    $form['target']['comic'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('漫画'),
      '#target_type' => 'node',
      '#selection_settings' => ['target_bundles' => ['comic' => 'comic']],
      '#required' => TRUE,
    ];
    $form['target']['chapter_mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('章节'),
      '#options' => [
        'existing' => $this->t('选择现有章节'),
        'new' => $this->t('创建新章节'),
      ],
      '#default_value' => 'existing',
    ];
    $form['target']['chapter'] = [
      '#type' => 'entity_autocomplete',
      '#title' => $this->t('现有章节'),
      '#target_type' => 'node',
      '#selection_settings' => ['target_bundles' => ['comic_chapter' => 'comic_chapter']],
      '#states' => ['visible' => [':input[name="chapter_mode"]' => ['value' => 'existing']]],
    ];
    $form['target']['chapter_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('新章节标题'),
      '#states' => ['visible' => [':input[name="chapter_mode"]' => ['value' => 'new']]],
    ];
    $form['target']['chapter_number'] = [
      '#type' => 'number',
      '#title' => $this->t('章节序号'),
      '#step' => '0.01',
      '#states' => ['visible' => [':input[name="chapter_mode"]' => ['value' => 'new']]],
    ];

    $form['zip'] = [
      '#type' => 'details',
      '#title' => $this->t('ZIP 压缩包'),
      '#open' => TRUE,
    ];
    $form['zip']['archive'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('ZIP 文件'),
      '#upload_location' => 'private://xedc-comic-import',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'zip'],
      ],
    ];
    $form['zip']['submit_zip'] = [
      '#type' => 'submit',
      '#value' => $this->t('开始 ZIP 导入'),
      '#submit' => ['::submitZip'],
      '#button_type' => 'primary',
    ];

    $form['multi'] = [
      '#type' => 'details',
      '#title' => $this->t('多张图片'),
      '#open' => FALSE,
    ];
    $form['multi']['images'] = [
      '#type' => 'file',
      '#title' => $this->t('选择图片'),
      '#multiple' => TRUE,
      '#attributes' => ['accept' => 'image/png,image/jpeg,image/jpg,image/gif,image/webp'],
      '#description' => $this->t('可一次选择多张；将按文件名自然排序后分批队列处理。'),
    ];
    $form['multi']['submit_multi'] = [
      '#type' => 'submit',
      '#value' => $this->t('开始多图上传'),
      '#submit' => ['::submitMulti'],
    ];

    $form['single'] = [
      '#type' => 'details',
      '#title' => $this->t('单张图片'),
      '#open' => FALSE,
    ];
    $form['single']['image'] = [
      '#type' => 'file',
      '#title' => $this->t('图片'),
      '#attributes' => ['accept' => 'image/png,image/jpeg,image/jpg,image/gif,image/webp'],
    ];
    $form['single']['insert_page'] = [
      '#type' => 'number',
      '#title' => $this->t('插入页码（留空追加到末尾）'),
      '#min' => 1,
    ];
    $form['single']['submit_single'] = [
      '#type' => 'submit',
      '#value' => $this->t('上传单图'),
      '#submit' => ['::submitSingle'],
    ];

    $pdfReady = $this->pdfConverter->isReady();
    $env = $this->pdfConverter->environmentReport();
    $form['pdf'] = [
      '#type' => 'details',
      '#title' => $this->t('PDF 转漫画页'),
      '#open' => FALSE,
    ];
    $form['pdf']['env'] = [
      '#type' => 'item',
      '#title' => $this->t('转换环境'),
      '#markup' => '<pre class="xedc-comic-pdf-env">' . htmlspecialchars(json_encode($env, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), ENT_QUOTES) . '</pre>',
      '#description' => $pdfReady
        ? $this->t('已检测到 pdftoppm 与 pdfinfo。')
        : $this->t('缺少 poppler-utils（pdftoppm、pdfinfo），无法开始 PDF 导入。'),
    ];
    $form['pdf']['pipeline'] = [
      '#markup' => '<p class="xedc-comic-pdf-steps">' . $this->t('上传 → 识别页数 → 逐页转换 → 图片优化 → Storage Hub/R2 → ComicPage') . '</p>',
    ];
    $defaults = \Drupal::config('xedc_comic.settings');
    $form['pdf']['pdf_file'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('PDF 文件'),
      '#upload_location' => 'private://xedc-comic-import/pdf',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'pdf'],
        'FileSizeLimit' => ['fileLimit' => (int) $defaults->get('pdf.max_bytes') ?: 104857600],
      ],
      '#description' => $this->t('提交后将自动识别页数；单页单独转换，不一次性载入内存。'),
      '#disabled' => !$pdfReady,
    ];
    $form['pdf']['pdf_meta'] = [
      '#type' => 'item',
      '#title' => $this->t('上传后显示'),
      '#markup' => $this->t('文件名、大小、页数将在创建任务时校验并写入进度页。'),
    ];
    $form['pdf']['pdf_dpi'] = [
      '#type' => 'select',
      '#title' => $this->t('分辨率 (DPI)'),
      '#options' => [96 => '96', 150 => '150', 200 => '200'],
      '#default_value' => 150,
      '#disabled' => !$pdfReady,
    ];
    $form['pdf']['pdf_format'] = [
      '#type' => 'select',
      '#title' => $this->t('输出格式'),
      '#options' => ['jpg' => 'JPG', 'webp' => 'WebP（经优化器输出）'],
      '#default_value' => 'jpg',
      '#disabled' => !$pdfReady,
    ];
    $form['pdf']['pdf_quality'] = [
      '#type' => 'number',
      '#title' => $this->t('图片质量'),
      '#default_value' => 85,
      '#min' => 50,
      '#max' => 95,
      '#disabled' => !$pdfReady,
    ];
    $form['pdf']['pdf_keep_source'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('转换成功后保留原始 PDF（private）'),
      '#default_value' => (int) $defaults->get('pdf.keep_source_default') !== 0,
      '#disabled' => !$pdfReady,
    ];
    $form['pdf']['submit_pdf'] = [
      '#type' => 'submit',
      '#value' => $this->t('开始 PDF 导入'),
      '#submit' => ['::submitPdf'],
      '#disabled' => !$pdfReady,
    ];

    $form['#attached']['library'][] = 'xedc_comic/admin';
    $form['#attached']['library'][] = 'xedc_comic/import_hub';
    return $form;
  }

  protected function wrapImportAdminShell(array $form, string $route = 'xedc_comic.import'): array {
    return \Drupal::service('xedc_comic.admin_shell')->page(
      $form,
      $route,
      (string) $this->t('内容导入'),
      (string) $this->t('ZIP、多图/单图与 PDF 导入到指定章节。'),
      array_values(array_filter([
        \Drupal::service('xedc_comic.admin_shell')->action((string) $this->t('导入任务'), 'xedc_comic.import_status'),
      ])),
    );
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Handled by mode-specific submit handlers.
  }

  protected function kickImportJob(array $job): void {
    if (\Drupal::hasService('xedc_comic.import_runner')) {
      \Drupal::service('xedc_comic.import_runner')->drainForJob((int) $job['id']);
      $fresh = $this->imports->loadJob((int) $job['id']);
      if ($fresh && in_array((string) $fresh['status'], ['completed', 'partial'], TRUE)) {
        $this->messenger()->addStatus($this->t('导入已完成（成功 @ok/@total）。', [
          '@ok' => $fresh['success_count'],
          '@total' => $fresh['total'],
        ]));
      }
      elseif ($fresh && (string) $fresh['status'] === 'failed') {
        $this->messenger()->addError($this->t('导入失败：@err', ['@err' => $fresh['error'] ?? '未知']));
      }
    }
  }

  /**
   * @return array{comic: NodeInterface, chapter_id: int}
   */
  protected function resolveTarget(FormStateInterface $form_state): array {
    $comic = $this->entities->getStorage('node')->load((int) $form_state->getValue('comic'));
    if (!$comic instanceof NodeInterface || $comic->bundle() !== 'comic') {
      throw new \InvalidArgumentException('漫画无效。');
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
        throw new \InvalidArgumentException('章节无效。');
      }
    }
    return ['comic' => $comic, 'chapter_id' => $chapterId];
  }

  public function submitZip(array &$form, FormStateInterface $form_state): void {
    try {
      $target = $this->resolveTarget($form_state);
    }
    catch (\InvalidArgumentException $e) {
      $this->messenger()->addError($e->getMessage());
      return;
    }
    $fids = $form_state->getValue('archive');
    $fid = is_array($fids) ? (int) reset($fids) : 0;
    $file = File::load($fid);
    if (!$file) {
      $this->messenger()->addError($this->t('请上传 ZIP 压缩包。'));
      return;
    }
    $file->setPermanent();
    $file->save();
    try {
      $job = $this->imports->createJob((int) $target['comic']->id(), $target['chapter_id'], (int) $this->currentUser()->id(), $file->getFileUri(), $file->getFilename(), $fid);
      $this->messenger()->addStatus($this->t('导入任务 @id 已创建。', ['@id' => $job['id']]));
      $this->kickImportJob($job);
      $form_state->setRedirect('xedc_comic.import_status', [], ['query' => ['job' => $job['id']]]);
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($e->getMessage());
    }
  }

  public function submitMulti(array &$form, FormStateInterface $form_state): void {
    try {
      $target = $this->resolveTarget($form_state);
    }
    catch (\InvalidArgumentException $e) {
      $this->messenger()->addError($e->getMessage());
      return;
    }
    $validators = ['FileExtension' => ['extensions' => 'png gif jpg jpeg webp']];
    $raw = $form_state->getValue('images');
    if ($raw === NULL || $raw === '' || $raw === []) {
      $legacy = file_save_upload('images', $validators, 'private://xedc-comic-import', FileExists::Rename);
      $raw = $legacy;
    }
    if ($raw === NULL || $raw === '' || $raw === []) {
      $this->messenger()->addError($this->t('请选择至少一张图片。'));
      return;
    }
    $list = is_array($raw) ? $raw : [$raw];
    /** @var \Drupal\file\Upload\FileUploadHandlerInterface $handler */
    $handler = \Drupal::service(FileUploadHandlerInterface::class);
    $files = [];
    foreach ($list as $item) {
      if ($item instanceof File) {
        $item->setPermanent();
        $item->save();
        $files[] = ['uri' => $item->getFileUri(), 'name' => $item->getFilename()];
        continue;
      }
      if (!$item instanceof UploadedFile) {
        continue;
      }
      $result = $handler->handleFileUpload(
        new FormUploadedFile($item),
        $validators,
        'private://xedc-comic-import',
        FileExists::Rename,
      );
      if ($result->hasViolations()) {
        foreach ($result->getViolations() as $violation) {
          $this->messenger()->addError($violation->getMessage());
        }
        continue;
      }
      $file = $result->getFile();
      $file->setPermanent();
      $file->save();
      $files[] = ['uri' => $file->getFileUri(), 'name' => $file->getFilename()];
    }
    if ($files === []) {
      $this->messenger()->addError($this->t('没有有效的图片文件。'));
      return;
    }
    try {
      $job = $this->imports->createImagesJob((int) $target['comic']->id(), $target['chapter_id'], (int) $this->currentUser()->id(), 'multi', $files);
      $this->messenger()->addStatus($this->t('多图任务 @id 已创建（@n 张）。', ['@id' => $job['id'], '@n' => count($files)]));
      $this->kickImportJob($job);
      $form_state->setRedirect('xedc_comic.import_status', [], ['query' => ['job' => $job['id']]]);
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($e->getMessage());
    }
  }

  public function submitPdf(array &$form, FormStateInterface $form_state): void {
    try {
      $target = $this->resolveTarget($form_state);
    }
    catch (\InvalidArgumentException $e) {
      $this->messenger()->addError($e->getMessage());
      return;
    }
    try {
      $this->pdfConverter->assertReady();
    }
    catch (\InvalidArgumentException $e) {
      $this->messenger()->addError($e->getMessage());
      return;
    }
    $fids = $form_state->getValue('pdf_file');
    $fid = is_array($fids) ? (int) reset($fids) : 0;
    $file = File::load($fid);
    if (!$file) {
      $this->messenger()->addError($this->t('请上传 PDF。'));
      return;
    }
    $file->setPermanent();
    $file->save();
    \Drupal::service('file.usage')->add($file, 'xedc_comic', 'comic_pdf_import', $target['chapter_id']);
    try {
      $job = $this->pdfImports->createPdfJob(
        (int) $target['comic']->id(),
        $target['chapter_id'],
        (int) $this->currentUser()->id(),
        $file->getFileUri(),
        $file->getFilename(),
        $fid,
        [
          'dpi' => (int) $form_state->getValue('pdf_dpi'),
          'format' => (string) $form_state->getValue('pdf_format'),
          'quality' => (int) $form_state->getValue('pdf_quality'),
          'keep_source' => (bool) $form_state->getValue('pdf_keep_source'),
        ],
      );
      $pages = (int) ($job['pdf_page_count'] ?? $job['total'] ?? 0);
      $bytes = format_size((int) ($job['archive_bytes'] ?? 0));
      $this->messenger()->addStatus($this->t('PDF 任务 @id：@name（@size，@pages 页）', [
        '@id' => $job['id'],
        '@name' => $file->getFilename(),
        '@size' => $bytes,
        '@pages' => $pages,
      ]));
      $this->kickImportJob($job);
      $form_state->setRedirect('xedc_comic.import_status', [], ['query' => ['job' => $job['id']]]);
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($e->getMessage());
    }
  }

  public function submitSingle(array &$form, FormStateInterface $form_state): void {
    try {
      $target = $this->resolveTarget($form_state);
    }
    catch (\InvalidArgumentException $e) {
      $this->messenger()->addError($e->getMessage());
      return;
    }
    $validators = ['FileExtension' => ['extensions' => 'png gif jpg jpeg webp']];
    $uploaded = file_save_upload('image', $validators, 'private://xedc-comic-import', FileSystemInterface::EXISTS_RENAME);
    if (!$uploaded) {
      $this->messenger()->addError($this->t('请选择一张图片。'));
      return;
    }
    $file = is_array($uploaded) ? reset($uploaded) : $uploaded;
    if (!$file) {
      $this->messenger()->addError($this->t('图片无效。'));
      return;
    }
    $file->setPermanent();
    $file->save();
    $insert = (int) $form_state->getValue('insert_page');
    $chapterId = $target['chapter_id'];
    if ($insert > 0) {
      $this->pages->makeRoomAt($chapterId, $insert);
      $weight = $insert;
    }
    else {
      $weight = \Drupal::service('xedc_comic.page_repository')->maxPageNumber($chapterId) + 1;
    }
    $files = [['uri' => $file->getFileUri(), 'name' => $file->getFilename()]];
    try {
      $job = $this->imports->createImagesJob((int) $target['comic']->id(), $chapterId, (int) $this->currentUser()->id(), 'single', $files, '单图');
      if ($insert > 0) {
        $this->imports->patchJob((int) $job['id'], ['message' => '插入页码 ' . $insert]);
        \Drupal::database()->update('xedc_comic_import_item')
          ->fields(['weight' => $weight])
          ->condition('import_id', (int) $job['id'])
          ->execute();
      }
      $this->messenger()->addStatus($this->t('单图任务 @id 已创建。', ['@id' => $job['id']]));
      $this->kickImportJob($job);
      $form_state->setRedirect('xedc_comic.import_status', [], ['query' => ['job' => $job['id']]]);
    }
    catch (\Throwable $e) {
      $this->messenger()->addError($e->getMessage());
    }
  }

}