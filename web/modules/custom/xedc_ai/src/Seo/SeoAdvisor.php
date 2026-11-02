<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Seo;

use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\node\NodeInterface;
use Drupal\xedc_ai\Usage\AuditLog;

/**
 * Explains saved SEO audits and applies a suggestion only after a safe check.
 */
final class SeoAdvisor {

  public function __construct(
    private readonly EntityTypeManagerInterface $entities,
    private readonly ModuleHandlerInterface $modules,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly AuditLog $audit,
    private readonly ModerationInformationInterface $moderation,
  ) {}

  /**
   * @return array<string, mixed>
   *   Stored Site Audit summary. This does not run a new audit.
   */
  public function storedAudit(): array {
    if (!$this->entities->hasDefinition('site_audit_report')) {
      return [
        'available' => FALSE,
        'message' => '当前没有可读取的 Site Audit 报告。请在 Site Audit 页面生成报告后再回来查看解释。',
        'items' => [],
      ];
    }
    $storage = $this->entities->getStorage('site_audit_report');
    $idKey = $storage->getEntityType()->getKey('id');
    $ids = $storage->getQuery()->accessCheck(FALSE)->sort($idKey, 'DESC')->range(0, 1)->execute();
    $report = $ids ? $storage->load(reset($ids)) : NULL;
    if ($report === NULL) {
      return [
        'available' => FALSE,
        'message' => '还没有保存过 Site Audit 报告。这里不会为了打开页面重新扫描全站。',
        'items' => [],
      ];
    }
    $map = [
      'field_meta_tags' => '元数据',
      'field_image_alt' => '图片 alt',
      'field_url_alias' => 'URL 别名',
      'field_sitemap' => '站点地图',
      'field_translation' => '翻译',
      'field_cache_status' => '缓存',
    ];
    $items = [];
    foreach ($map as $field => $label) {
      if (!$report->hasField($field)) {
        continue;
      }
      $value = (string) $report->get($field)->value;
      $detailName = $field . '_detail';
      if ($field === 'field_meta_tags') {
        $detailName = 'field_meta_detail';
      }
      $detail = $report->hasField($detailName) ? trim(strip_tags((string) $report->get($detailName)->value)) : '';
      $items[] = [
        'label' => $label,
        'value' => $value !== '' ? $value : '无',
        'detail' => $detail,
        'meaning' => match ($value) {
          'pass' => '已保存的检查没有发现问题。',
          'warn' => '已保存的检查提示需要处理。',
          'fail' => '已保存的检查未通过。',
          default => '这是已保存的结果，不是一次新的扫描。',
        },
      ];
    }
    return [
      'available' => TRUE,
      'message' => '下面是最近一份已保存的 Site Audit 报告，不是重新执行的全站审计。',
      'items' => $items,
    ];
  }

  /**
   * Returns a reason when saving the node could trigger content translation.
   */
  public function writeBlocked(NodeInterface $node): ?string {
    $targets = array_keys(array_filter((array) $this->configFactory->get('xedc_auto_translate.settings')->get('targets')));
    $languages = array_keys($node->getTranslationLanguages());
    $moderated = $node->hasField('moderation_state') && $this->moderation->isModeratedEntity($node);
    $state = $moderated ? (string) $node->get('moderation_state')->value : '';
    return self::blockedByTranslation($node->isPublished(), $moderated, $state, $languages, $targets);
  }

  /**
   * @param list<string> $languages
   *   Existing translation languages.
   * @param list<string> $targets
   *   Configured translation targets.
   */
  public static function blockedByTranslation(bool $published, bool $moderated, string $state, array $languages, array $targets): ?string {
    $live = $published && (!$moderated || $state === 'published');
    if (!$live) {
      return NULL;
    }
    foreach ($targets as $language) {
      if (!in_array($language, $languages, TRUE)) {
        return '内容已处于发布状态，并且还缺少自动翻译的目标语言。保存会走到现有翻译流程，所以这里不写入。请手工复制建议。';
      }
    }
    return NULL;
  }

  /**
   * Writes selected text fields. Returns an error message, or NULL on success.
   *
   * @param array<string, mixed> $result
   *   Stored SEO result.
   */
  public function apply(NodeInterface $node, array $result, bool $useTitle, bool $useSummary, int $uid): ?string {
    if (!$useTitle && !$useSummary) {
      return '没有选择要写入的字段。';
    }
    $blocked = $this->writeBlocked($node);
    if ($blocked !== NULL) {
      return $blocked;
    }
    $status = (int) $node->isPublished();
    $state = $node->hasField('moderation_state') ? (string) $node->get('moderation_state')->value : '';
    $before = [];
    $after = [];
    $suggestion = [];
    if ($useTitle && trim((string) ($result['seo_title'] ?? '')) !== '') {
      $before[] = '标题：' . $node->label();
      $node->setTitle((string) $result['seo_title']);
      $after[] = '标题：' . $node->label();
      $suggestion[] = (string) $result['seo_title'];
    }
    if ($useSummary && $node->hasField('field_summary') && trim((string) ($result['meta_description'] ?? '')) !== '') {
      $before[] = '简介：' . trim((string) $node->get('field_summary')->value);
      $node->set('field_summary', (string) $result['meta_description']);
      $after[] = '简介：' . (string) $result['meta_description'];
      $suggestion[] = (string) $result['meta_description'];
    }
    elseif ($useSummary && !$node->hasField('field_summary')) {
      return '这个内容类型没有简介字段，未写入。';
    }
    $node->set('status', $status);
    if ($state !== '' && $node->hasField('moderation_state')) {
      $node->set('moderation_state', $state);
    }
    $node->save();
    $this->audit->log('seo_apply', '已按确认写入内容 ' . $node->id() . ' 的文字字段。发布状态未改。', $uid, [
      'target_type' => 'node',
      'target_id' => (string) $node->id(),
      'before' => implode("\n", $before),
      'after' => implode("\n", $after),
      'suggestion' => implode("\n", $suggestion),
      'confirmed' => 1,
      'result' => 'saved',
    ]);
    return NULL;
  }

}
