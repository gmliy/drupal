<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\xedc_core\Engagement\VipCodeManager;
use Drupal\xedc_core\Form\VipCodeFilterForm;
use Drupal\xedc_core\Form\VipCodeGenerateForm;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Admin UI for VIP redeem codes.
 */
final class VipCodeAdminController extends ControllerBase {

  public function __construct(
    protected VipCodeManager $codes,
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_core.vip_codes'),
      $container->get('tempstore.private'),
      $container->get('date.formatter'),
    );
  }

  public function overview(Request $request): array {
    $batchFilter = (int) $request->query->get('batch_id', 0);
    $statusFilter = (string) $request->query->get('status', '');
    $prefix = trim((string) $request->query->get('prefix', ''));
    $allowedStatus = [
      '',
      VipCodeManager::STATUS_UNUSED,
      VipCodeManager::STATUS_REDEEMED,
      VipCodeManager::STATUS_REVOKED,
      VipCodeManager::STATUS_EXPIRED,
    ];
    if (!in_array($statusFilter, $allowedStatus, TRUE)) {
      $statusFilter = '';
    }

    $batches = $this->codes->listBatches(40);
    $batchRows = [];
    foreach ($batches as $batch) {
      $counts = $this->codes->batchCounts((int) $batch->batch_id);
      $batchRows[] = [
        Link::fromTextAndUrl('#' . $batch->batch_id, Url::fromRoute('xedc_core.vip_codes', [], [
          'query' => ['batch_id' => $batch->batch_id],
        ]))->toString(),
        (int) $batch->vip_days,
        (int) $batch->quantity,
        $this->dateFormatter->format((int) $batch->created, 'short'),
        ((int) $batch->expires_at > 0) ? $this->dateFormatter->format((int) $batch->expires_at, 'short') : $this->t('不限制'),
        $this->accountName((int) $batch->created_uid),
        $counts['redeemed'],
        $counts['unused'],
        $counts['expired'] + $counts['revoked'],
        (string) $batch->note,
      ];
    }

    $codeRows = [];
    foreach ($this->codes->listCodes($batchFilter ?: NULL, $statusFilter !== '' ? $statusFilter : NULL, $prefix, 100) as $row) {
      $status = $this->codes->effectiveStatus($row);
      $ops = [];
      if ($status === VipCodeManager::STATUS_UNUSED) {
        $ops[] = Link::fromTextAndUrl($this->t('作废'), Url::fromRoute('xedc_core.vip_code_revoke', [
          'code_id' => (int) $row->code_id,
        ]))->toString();
      }
      $codeRows[] = [
        $this->codes->maskCode($row),
        (int) $row->batch_id,
        (int) $row->vip_days,
        $this->codes->statusLabel($status),
        $this->dateFormatter->format((int) $row->created, 'short'),
        ((int) $row->expires_at > 0) ? $this->dateFormatter->format((int) $row->expires_at, 'short') : $this->t('不限制'),
        ((int) $row->redeemed_uid > 0) ? $this->accountName((int) $row->redeemed_uid) . ' (#' . (int) $row->redeemed_uid . ')' : '—',
        ((int) $row->redeemed > 0) ? $this->dateFormatter->format((int) $row->redeemed, 'short') : '—',
        ['data' => ['#markup' => implode(' · ', $ops) ?: '—']],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['xedc-vip-codes']],
      'intro' => [
        '#markup' => '<p class="xedc-vip-hub__note">' . $this->t('列表仅展示脱敏卡密。明文只在生成后安全展示一次，不会出现在普通列表中。') . '</p>',
      ],
      'actions' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['xedc-vip-code-actions']],
        'generate' => [
          '#type' => 'link',
          '#title' => $this->t('生成卡密'),
          '#url' => Url::fromRoute('xedc_core.vip_codes_generate'),
          '#attributes' => ['class' => ['button', 'button--primary']],
        ],
      ],
      'filter' => $this->formBuilder()->getForm(VipCodeFilterForm::class),
      'batches' => [
        '#type' => 'table',
        '#caption' => $this->t('卡密批次'),
        '#header' => [
          $this->t('批次'),
          $this->t('VIP 天数'),
          $this->t('数量'),
          $this->t('创建时间'),
          $this->t('兑换截止'),
          $this->t('创建人'),
          $this->t('已兑换'),
          $this->t('未兑换'),
          $this->t('过期/作废'),
          $this->t('备注'),
        ],
        '#rows' => $batchRows,
        '#empty' => $this->t('暂无批次。'),
      ],
      'codes' => [
        '#type' => 'table',
        '#caption' => $this->t('卡密列表（脱敏）'),
        '#header' => [
          $this->t('卡密'),
          $this->t('批次'),
          $this->t('天数'),
          $this->t('状态'),
          $this->t('创建时间'),
          $this->t('兑换截止'),
          $this->t('兑换会员'),
          $this->t('兑换时间'),
          $this->t('操作'),
        ],
        '#rows' => $codeRows,
        '#empty' => $this->t('没有匹配的卡密。'),
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  public function generate(): array {
    return $this->formBuilder()->getForm(VipCodeGenerateForm::class);
  }

  public function reveal(int $batch_id): array {
    $batch = $this->codes->loadBatch($batch_id);
    if (!$batch) {
      throw new NotFoundHttpException();
    }
    $store = $this->tempStoreFactory->get('xedc_vip_codes');
    $payload = $store->get('batch_' . $batch_id);
    $codes = is_array($payload) ? ($payload['codes'] ?? []) : [];
    if ($codes === []) {
      return [
        '#markup' => '<p>' . $this->t('本批次明文卡密已不再可查看。如需新卡密，请重新生成一批。数据库中仅保存哈希，无法还原。') . '</p>',
        'back' => [
          '#type' => 'link',
          '#title' => $this->t('返回卡密管理'),
          '#url' => Url::fromRoute('xedc_core.vip_codes'),
          '#attributes' => ['class' => ['button']],
        ],
      ];
    }
    return [
      'warning' => [
        '#markup' => '<p><strong>' . $this->t('请立即复制或导出。离开本页后将无法再次查看明文。') . '</strong></p>',
      ],
      'meta' => [
        '#markup' => '<p>' . $this->t('批次 #@id · @days 天 · 共 @n 张', [
          '@id' => $batch_id,
          '@days' => (int) $batch->vip_days,
          '@n' => count($codes),
        ]) . '</p>',
      ],
      'codes' => [
        '#type' => 'textarea',
        '#title' => $this->t('明文卡密'),
        '#value' => implode("\n", $codes),
        '#rows' => min(20, max(6, count($codes))),
        '#attributes' => ['readonly' => 'readonly'],
      ],
      'actions' => [
        '#type' => 'container',
        'export' => [
          '#type' => 'link',
          '#title' => $this->t('导出 CSV'),
          '#url' => Url::fromRoute('xedc_core.vip_codes_export', ['batch_id' => $batch_id]),
          '#attributes' => ['class' => ['button', 'button--primary']],
        ],
        'back' => [
          '#type' => 'link',
          '#title' => $this->t('返回列表'),
          '#url' => Url::fromRoute('xedc_core.vip_codes'),
          '#attributes' => ['class' => ['button']],
        ],
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  public function exportCsv(int $batch_id): Response {
    $batch = $this->codes->loadBatch($batch_id);
    if (!$batch) {
      throw new NotFoundHttpException();
    }
    $store = $this->tempStoreFactory->get('xedc_vip_codes');
    $payload = $store->get('batch_' . $batch_id);
    $codes = is_array($payload) ? ($payload['codes'] ?? []) : [];
    if ($codes === []) {
      throw new NotFoundHttpException();
    }
    $filename = 'xedc-vip-codes-batch-' . $batch_id . '.csv';
    $response = new StreamedResponse(function () use ($codes, $batch) {
      $out = fopen('php://output', 'w');
      if ($out === FALSE) {
        return;
      }
      fputcsv($out, ['batch_id', 'vip_days', 'code']);
      foreach ($codes as $code) {
        fputcsv($out, [(int) $batch->batch_id, (int) $batch->vip_days, $code]);
      }
      fclose($out);
    });
    $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
    $response->headers->set('Cache-Control', 'private, no-store');
    return $response;
  }

  protected function accountName(int $uid): string {
    if ($uid < 1) {
      return '—';
    }
    $user = $this->entityTypeManager()->getStorage('user')->load($uid);
    return $user ? $user->getAccountName() : ('#' . $uid);
  }

}
