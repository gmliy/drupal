<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * Chapter-scoped import workbench (ZIP / PDF / images).
 */
final class ComicChapterImportForm extends ComicImportHubForm {

  public function getFormId(): string {
    return 'xedc_comic_chapter_import';
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $chapter = NULL): array {
    if (!$chapter instanceof NodeInterface || $chapter->bundle() !== 'comic_chapter') {
      throw new \InvalidArgumentException('章节无效。');
    }
    $parent = $chapter->hasField('field_comic_parent') && !$chapter->get('field_comic_parent')->isEmpty()
      ? $chapter->get('field_comic_parent')->entity : NULL;
    if (!$parent instanceof NodeInterface) {
      throw new \InvalidArgumentException('章节未关联漫画。');
    }
    $form_state->set('locked_chapter_id', (int) $chapter->id());
    $form_state->set('locked_comic_id', (int) $parent->id());

    $pageCount = $chapter->hasField('field_comic_page_count') ? (int) $chapter->get('field_comic_page_count')->value : 0;
    $readerUrl = Url::fromRoute('xedc_comic.reader', [
      'comic' => $parent->id(),
      'chapter' => $chapter->id(),
    ]);

    $form = $this->buildImportFormElements($form, $form_state);
    unset($form['target']);

    $form['chapter_banner'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['xedc-comic-chapter-import-banner', 'xedc-admin-card']],
      '#weight' => -20,
      'meta' => [
        '#markup' => '<div class="xedc-comic-chapter-import-meta">'
          . '<h2 class="xedc-comic-chapter-import-title">' . htmlspecialchars($chapter->label(), ENT_QUOTES) . '</h2>'
          . '<p><strong>' . $this->t('所属漫画') . '</strong>：' . htmlspecialchars($parent->label(), ENT_QUOTES) . '</p>'
          . '<p><strong>' . $this->t('章节序号') . '</strong>：' . htmlspecialchars((string) ($chapter->get('field_comic_number')->value ?? ''), ENT_QUOTES) . '</p>'
          . '<p><strong>' . $this->t('当前页数') . '</strong>：' . $pageCount . '</p>'
          . '<p class="xedc-comic-chapter-import-links">'
          . '<a class="xedc-admin-button" href="' . Url::fromRoute('xedc_comic.admin_pages', [], ['query' => ['chapter' => $chapter->id()]])->toString() . '">' . $this->t('图片管理') . '</a> '
          . '<a class="xedc-admin-button" href="' . $readerUrl->toString() . '" target="_blank" rel="noopener">' . $this->t('阅读预览') . '</a>'
          . '</p></div>',
      ],
    ];

    return $form;
  }

  protected function resolveTarget(FormStateInterface $form_state): array {
    $chapterId = (int) $form_state->get('locked_chapter_id');
    $comicId = (int) $form_state->get('locked_comic_id');
    if ($chapterId > 0 && $comicId > 0) {
      $comic = $this->entities->getStorage('node')->load($comicId);
      $chapter = $this->entities->getStorage('node')->load($chapterId);
      if ($comic instanceof NodeInterface && $chapter instanceof NodeInterface) {
        return ['comic' => $comic, 'chapter_id' => $chapterId];
      }
    }
    return parent::resolveTarget($form_state);
  }

}