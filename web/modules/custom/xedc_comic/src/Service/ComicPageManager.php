<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Service;

use Drupal\xedc_comic\Repository\ComicPageRepository;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\file\FileUsage\FileUsageInterface;
use Drupal\xedc_comic\Entity\ComicPage;

/**
 * Chapter page reorder, replace and safe delete.
 */
final class ComicPageManager {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entities,
    private readonly ComicStorageManager $storage,
    private readonly FileUsageInterface $fileUsage,
    private readonly ComicPageRepository $pages,
  ) {}

  public function chapterHasSourceHash(int $chapterId, string $sourceHash): bool {
    $sourceHash = strtolower(trim($sourceHash));
    if (!preg_match('/^[a-f0-9]{64}$/', $sourceHash) || !$this->database->schema()->tableExists('xedc_image_file')) {
      return FALSE;
    }
    $fids = $this->database->select('comic_page', 'p')
      ->fields('p', ['image_file_id'])
      ->condition('chapter_id', $chapterId)
      ->execute()
      ->fetchCol();
    if ($fids === []) {
      return FALSE;
    }
    $hit = $this->database->select('xedc_image_file', 'x')
      ->condition('fid', $fids, 'IN')
      ->condition('source_sha256', $sourceHash)
      ->countQuery()
      ->execute()
      ->fetchField();
    return (int) $hit > 0;
  }

  /**
   * @param list<int> $orderedPageIds page entity ids in desired order
   */
  public function reorderPages(int $chapterId, array $orderedPageIds): void {
    $number = 1;
    foreach ($orderedPageIds as $pageId) {
      $page = $this->entities->getStorage('comic_page')->load($pageId);
      if (!$page instanceof ComicPage || $page->getChapterId() !== $chapterId) {
        continue;
      }
      if ($page->getPageNumber() !== $number) {
        $page->set('page_number', $number);
        $page->save();
      }
      $number++;
    }
    \Drupal::service('xedc_comic.manager')->syncChapterPageCount($chapterId);
  }

  public function deletePageSafe(ComicPage $page, bool $renumber = FALSE): void {
    $chapterId = $page->getChapterId();
    $fid = $page->getFileId();
    $page->delete();
    if ($fid < 1) {
      if ($renumber) {
        $this->renumberChapter($chapterId);
      }
      return;
    }
    $file = File::load($fid);
    if (!$file instanceof FileInterface) {
      return;
    }
    $this->fileUsage->delete($file, 'xedc_comic');
    $remaining = $this->fileUsage->listUsage($file);
    $usedElsewhere = FALSE;
    foreach ($remaining as $module => $types) {
      if ($module !== 'xedc_comic' && $types !== []) {
        $usedElsewhere = TRUE;
        break;
      }
    }
    if ($usedElsewhere) {
      return;
    }
    if ($this->fileUsage->listUsage($file) === []) {
      $file->delete();
    }
    if ($renumber) {
      $this->renumberChapter($chapterId);
    }
  }

  /**
   * @return array{page: ComicPage, file: FileInterface}
   */
  public function replacePageFile(ComicPage $page, string $sourcePath, string $originalName): array {
    $chapterId = $page->getChapterId();
    $chapter = $this->entities->getStorage('node')->load($chapterId);
    if (!$chapter || !$chapter->hasField('field_comic_parent') || $chapter->get('field_comic_parent')->isEmpty()) {
      throw new \InvalidArgumentException('章节无效。');
    }
    $comicId = (int) $chapter->get('field_comic_parent')->target_id;
    $oldFid = $page->getFileId();
    $created = $this->storage->replacePageImage($page, $comicId, $sourcePath, $originalName);
    if ($oldFid > 0 && $oldFid !== $created['file']->id()) {
      $old = File::load($oldFid);
      if ($old instanceof FileInterface) {
        $this->fileUsage->delete($old, 'xedc_comic');
        if ($this->fileUsage->listUsage($old) === []) {
          $old->delete();
        }
      }
    }
    return $created;
  }

  public function makeRoomAt(int $chapterId, int $pageNumber): void {
    if ($pageNumber < 1) {
      return;
    }
    $ids = $this->pages->pageIdsForChapter($chapterId);
    $toBump = [];
    foreach ($ids as $id) {
      $page = $this->entities->getStorage('comic_page')->load($id);
      if ($page instanceof ComicPage && $page->getPageNumber() >= $pageNumber) {
        $toBump[] = $page;
      }
    }
    usort($toBump, static fn(ComicPage $a, ComicPage $b) => $b->getPageNumber() <=> $a->getPageNumber());
    foreach ($toBump as $page) {
      $page->set('page_number', $page->getPageNumber() + 1);
      $page->save();
    }
  }

  public function renumberChapter(int $chapterId): void {
    $ids = $this->pages->pageIdsForChapter($chapterId);
    $ordered = [];
    foreach ($ids as $id) {
      $page = $this->entities->getStorage('comic_page')->load($id);
      if ($page instanceof ComicPage) {
        $ordered[] = $page;
      }
    }
    usort($ordered, static fn(ComicPage $a, ComicPage $b) => $a->getPageNumber() <=> $b->getPageNumber());
    $this->reorderPages($chapterId, array_map(static fn(ComicPage $p) => (int) $p->id(), $ordered));
  }

  /**
   * @param list<int> $pageIds
   */
  public function deletePages(int $chapterId, array $pageIds): int {
    $deleted = 0;
    foreach ($pageIds as $pageId) {
      $page = $this->entities->getStorage('comic_page')->load($pageId);
      if ($page instanceof ComicPage && $page->getChapterId() === $chapterId) {
        $this->deletePageSafe($page, FALSE);
        $deleted++;
      }
    }
    if ($deleted > 0) {
      $this->renumberChapter($chapterId);
      \Drupal::service('xedc_comic.manager')->syncChapterPageCount($chapterId);
    }
    return $deleted;
  }

}