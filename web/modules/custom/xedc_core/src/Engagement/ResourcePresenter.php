<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Engagement;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\xedc_core\Entity\DownloadResource;
use Drupal\xedc_core\Ops\OpsFormat;

/**
 * Builds the download sheet without exposing protected or external URLs.
 */
final class ResourcePresenter {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected DownloadAccess $access,
    protected OriginalFiles $originals,
    protected MembershipManager $membership,
  ) {}

  /**
   * @return array<string, mixed>
   *   Sheet data for one node.
   */
  public function forNode(NodeInterface $node, AccountInterface $account): array {
    $rows = [];
    if (!$this->entityTypeManager->hasDefinition('xedc_download')) {
      return $this->payload($account, $rows);
    }
    $ids = $this->entityTypeManager->getStorage('xedc_download')->getQuery()
      ->accessCheck(FALSE)
      ->condition('parent', $node->id())
      ->condition('status', 1)
      ->sort('weight')
      ->sort('id')
      ->execute();
    if (!$ids) {
      return $this->payload($account, $rows);
    }
    $resources = $this->entityTypeManager->getStorage('xedc_download')->loadMultiple($ids);
    foreach ($resources as $resource) {
      if (!$resource instanceof DownloadResource) {
        continue;
      }
      $rows = array_merge($rows, $this->rows($resource, $node, $account));
    }
    return $this->payload($account, $rows);
  }

  /**
   * @return list<array<string, mixed>>
   *   One sheet row, or one row per original image.
   */
  protected function rows(DownloadResource $resource, NodeInterface $node, AccountInterface $account): array {
    $source = (string) $resource->get('source_type')->value;
    $level = (string) $resource->get('access_level')->value;
    $decision = $this->access->decide($resource, $account, $source === 'originals' ? $this->firstFid($node) : NULL);
    if ($source === 'originals') {
      $items = $this->originals->forNode($node);
      $rows = [];
      foreach ($items as $item) {
        $one = $this->access->decide($resource, $account, $item['fid']);
        $rows[] = $this->row($resource, $item['filename'], $item['extension'], (int) $item['size'], $one, $source === 'originals' ? Url::fromRoute('xedc_core.download_original', [
          'xedc_download' => $resource->id(),
          'fid' => $item['fid'],
        ])->toString() : '');
      }
      return $rows;
    }
    $extension = (string) $resource->get('file_extension')->value;
    $size = (int) $resource->get('file_size')->value;
    if ($source === 'zip') {
      $plan = $this->originals->forNode($node);
      $size = 0;
      foreach ($plan as $item) {
        $size += (int) $item['size'];
      }
      $extension = 'zip';
      $decision = $this->access->decide($resource, $account);
    }
    $href = match ($source) {
      'zip' => Url::fromRoute('xedc_core.download_archive', ['xedc_download' => $resource->id()])->toString(),
      'upload', 'external' => Url::fromRoute('xedc_core.download_file', ['xedc_download' => $resource->id()])->toString(),
      default => '',
    };
    $title = (string) $resource->label();
    if ($source === 'zip' && $decision['allow']) {
      $count = count($this->originals->forNode($node));
      $title .= '（' . $count . ' 张）';
    }
    return [$this->row($resource, $title, $extension, $size, $decision, $href)];
  }

  /**
   * @param array{allow: bool, status: int, message: string, tier: string, required: string} $decision
   *   Access decision.
   *
   * @return array<string, mixed>
   *   One row safe to print.
   */
  protected function row(DownloadResource $resource, string $title, string $extension, int $size, array $decision, string $href): array {
    $level = (string) $resource->get('access_level')->value;
    $sources = [
      'upload' => '上传文件',
      'external' => '外部链接',
      'originals' => '原图',
      'zip' => '整组 ZIP',
    ];
    $source = (string) $resource->get('source_type')->value;
    return [
      'title' => $title,
      'extension' => strtoupper($extension ?: '文件'),
      'size' => $size > 0 ? OpsFormat::bytes($size) : '',
      'source' => $source,
      'source_label' => $sources[$source] ?? $source,
      'access' => $level,
      'access_label' => DownloadResource::LEVELS[$level] ?? $level,
      'description' => (string) $resource->get('description')->value,
      'allowed' => $decision['allow'],
      'message' => $decision['message'],
      'href' => $decision['allow'] ? $href : '',
    ];
  }

  /**
   * @param list<array<string, mixed>> $rows
   *   Sheet rows.
   *
   * @return array<string, mixed>
   *   Payload including the viewer's tier and VIP copy.
   */
  protected function payload(AccountInterface $account, array $rows): array {
    $snapshot = $this->membership->snapshot($account);
    return [
      'rows' => $rows,
      'empty' => $rows === [],
      'tier' => $snapshot['tier'],
      'tier_label' => $snapshot['label'],
      'benefits' => $snapshot['benefits'],
      'login' => Url::fromRoute('user.login', [], [
        'query' => ['destination' => \Drupal::request()->getRequestUri()],
      ])->toString(),
    ];
  }

  protected function firstFid(NodeInterface $node): ?int {
    $items = $this->originals->forNode($node);
    return $items[0]['fid'] ?? NULL;
  }

}
