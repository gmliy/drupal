<?php

declare(strict_types=1);

namespace Drupal\xedc_ui_locale\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\xedc_ui_locale\TranslationTaskRepository;
use Drupal\xedc_ui_locale\UiTranslationManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows interface-translation status and manual actions.
 */
final class ManageForm extends FormBase {

  public function __construct(
    private readonly UiTranslationManager $manager,
    private readonly TranslationTaskRepository $repository,
    private readonly PagerManagerInterface $pagerManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(UiTranslationManager::class),
      $container->get(TranslationTaskRepository::class),
      $container->get('pager.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_ui_locale_manage';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $platform = $this->manager->platform();
    $stats = $this->manager->statistics();
    $request = $this->getRequest();
    $filters = [
      'module' => trim((string) $request->query->get('module', '')),
      'status' => trim((string) $request->query->get('status', '')),
      'untranslated' => $request->query->get('untranslated') ? 1 : 0,
    ];
    $labels = [
      'enabled' => '已启用模块',
      'scanned' => '已扫描模块',
      'official' => '官方翻译',
      'ai' => 'AI 翻译',
      'existing' => '已有翻译',
      'remaining' => '待翻译',
      'failed' => '翻译失败',
    ];
    $rows = [];
    foreach ($labels as $key => $label) {
      $rows[] = [$label, (string) ($stats[$key] ?? 0)];
    }
    $form['summary'] = [
      '#type' => 'table',
      '#header' => [$this->t('统计'), $this->t('数量')],
      '#rows' => $rows,
      '#caption' => $this->t('界面翻译使用 @provider。密钥来自自动翻译的 State 配置，这里不需要再填写。', [
        '@provider' => $platform['provider_label'],
      ]),
    ];
    $form['platform'] = [
      '#markup' => '<p>' . $this->t('翻译服务：@service，调用 @method。配置对象：@config。密钥状态：@key。', [
        '@service' => $platform['service'],
        '@method' => $platform['method'],
        '@config' => $platform['config'],
        '@key' => $platform['key_configured'] ? $this->t('已配置') : $this->t('未配置'),
      ]) . '</p>',
    ];
    $form['module'] = [
      '#type' => 'textfield',
      '#title' => $this->t('模块机器名'),
      '#default_value' => $filters['module'],
      '#description' => $this->t('用于重新扫描、导入官方语言包或翻译指定模块。'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['scan_all'] = [
      '#type' => 'submit',
      '#name' => 'scan_all',
      '#value' => $this->t('重新扫描全部已启用模块'),
    ];
    $form['actions']['scan_module'] = [
      '#type' => 'submit',
      '#name' => 'scan_module',
      '#value' => $this->t('重新扫描指定模块'),
    ];
    $form['actions']['official'] = [
      '#type' => 'submit',
      '#name' => 'official',
      '#value' => $this->t('重新导入官方语言包'),
    ];
    $form['actions']['missing'] = [
      '#type' => 'submit',
      '#name' => 'missing',
      '#value' => $this->t('翻译剩余缺失字符串'),
    ];
    $form['actions']['retry'] = [
      '#type' => 'submit',
      '#name' => 'retry',
      '#value' => $this->t('重试失败任务'),
    ];

    $form['filters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['form--inline']],
    ];
    $form['filters']['status'] = [
      '#type' => 'select',
      '#title' => $this->t('状态'),
      '#options' => [
        '' => $this->t('全部'),
        'pending' => $this->t('待处理'),
        'completed' => $this->t('已完成'),
        'failed' => $this->t('翻译失败'),
        'retry' => $this->t('等待重试'),
        'skipped' => $this->t('无需翻译'),
      ],
      '#default_value' => $filters['status'],
    ];
    $form['filters']['untranslated'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('只看未翻译'),
      '#default_value' => $filters['untranslated'],
    ];
    $form['filters']['filter'] = [
      '#type' => 'submit',
      '#name' => 'filter',
      '#value' => $this->t('筛选'),
      '#limit_validation_errors' => [['module'], ['status'], ['untranslated']],
    ];

    $limit = 40;
    $header = [
      $this->t('模块'),
      $this->t('原始英文'),
      $this->t('简体中文译文'),
      $this->t('来源'),
      $this->t('状态'),
      $this->t('失败原因'),
      $this->t('最近执行'),
    ];
    $filter_query = array_filter([
      'module' => $filters['module'],
      'status' => $filters['status'],
      'untranslated' => $filters['untranslated'] ? '1' : NULL,
    ]);
    $total = $this->repository->browse($filter_query, 1, 0)['total'];
    $pager = $this->pagerManager->createPager($total, $limit);
    $page_rows = $this->repository->browse($filter_query, $limit, $pager->getCurrentPage() * $limit)['rows'];
    $table_rows = [];
    foreach ($page_rows as $row) {
      $table_rows[] = [
        $row['module'],
        $this->clip((string) $row['source']),
        $this->clip((string) $row['translation']),
        $this->originLabel((string) $row['origin']),
        $this->statusLabel((string) $row['status']),
        $this->clip((string) $row['reason']),
        !empty($row['changed']) ? date('Y-m-d H:i', (int) $row['changed']) : '',
      ];
    }
    $form['items'] = [
      '#type' => 'table',
      '#header' => $header,
      '#rows' => $table_rows,
      '#empty' => $this->t('还没有翻译记录。新模块启用后会自动加入队列。'),
    ];
    $form['pager'] = ['#type' => 'pager'];

    $log_rows = [];
    foreach ($this->repository->recentLogs(30) as $log) {
      $log_rows[] = [
        date('Y-m-d H:i', (int) $log['created']),
        $log['module'],
        $log['level'],
        $this->clip((string) $log['message']),
      ];
    }
    $form['logs'] = [
      '#type' => 'details',
      '#title' => $this->t('翻译日志'),
      '#open' => FALSE,
    ];
    $form['logs']['table'] = [
      '#type' => 'table',
      '#header' => [$this->t('时间'), $this->t('模块'), $this->t('级别'), $this->t('说明')],
      '#rows' => $log_rows,
      '#empty' => $this->t('暂无日志。'),
    ];
    $protected = $this->config('xedc_ui_locale.settings')->get('protected_terms');
    $form['protected_terms'] = [
      '#type' => 'textarea',
      '#title' => $this->t('额外受保护名称'),
      '#default_value' => implode("\n", is_array($protected) ? $protected : []),
      '#description' => $this->t('每行一个。DcLili、Drupal 和 XEDC 始终保护。模块项目名称、机器名和 Composer 包名会自动加入，只锁定完整名称，不跳过模块里的其他界面文字。'),
      '#rows' => 4,
    ];
    $form['save_terms'] = [
      '#type' => 'submit',
      '#name' => 'save_terms',
      '#value' => $this->t('保存受保护名称'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $trigger = (string) ($form_state->getTriggeringElement()['#name'] ?? '');
    $module = trim((string) $form_state->getValue('module'));
    if (in_array($trigger, ['scan_module', 'official'], TRUE) && $module === '') {
      $form_state->setErrorByName('module', $this->t('请填写模块机器名。'));
      return;
    }
    if ($module === '' || in_array($trigger, ['filter', 'scan_all', 'save_terms'], TRUE)) {
      return;
    }
    if (!preg_match('/^[a-z][a-z0-9_]*$/', $module) || !$this->moduleExists($module)) {
      $form_state->setErrorByName('module', $this->t('模块 @module 未启用。', ['@module' => $module !== '' ? $module : '']));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $trigger = (string) ($form_state->getTriggeringElement()['#name'] ?? '');
    $module = trim((string) $form_state->getValue('module'));
    $query = array_filter([
      'module' => $module,
      'status' => (string) $form_state->getValue('status'),
      'untranslated' => $form_state->getValue('untranslated') ? '1' : NULL,
    ]);
    if ($trigger === 'filter') {
      $form_state->setRedirect('xedc_ui_locale.manage', [], ['query' => $query]);
      return;
    }
    if ($trigger === 'save_terms') {
      $terms = preg_split('/\R/u', (string) $form_state->getValue('protected_terms')) ?: [];
      $clean = [];
      foreach ($terms as $term) {
        $term = trim($term);
        if ($term !== '') {
          $clean[$term] = $term;
        }
      }
      $this->configFactory()->getEditable('xedc_ui_locale.settings')
        ->set('protected_terms', array_values($clean))
        ->set('batch_size', 20)
        ->save();
      $this->messenger()->addStatus($this->t('受保护名称已保存。'));
      return;
    }
    if ($trigger === 'scan_all') {
      $this->manager->enqueueModules($this->manager->enabledModules(), 'full');
      $this->messenger()->addStatus($this->t('已把全部已启用模块加入界面翻译队列。'));
    }
    elseif ($trigger === 'scan_module') {
      $this->manager->enqueueModules([$module], 'full');
      $this->messenger()->addStatus($this->t('已加入队列：@module。', ['@module' => $module]));
    }
    elseif ($trigger === 'official') {
      $this->manager->enqueueModules([$module], 'official');
      $this->messenger()->addStatus($this->t('已加入官方语言包导入队列：@module。', ['@module' => $module]));
    }
    elseif ($trigger === 'missing' || $trigger === 'retry') {
      $targets = $module !== '' ? [$module] : $this->repository->jobModules();
      if ($targets === []) {
        $this->messenger()->addWarning($this->t('还没有扫描记录。请先扫描模块。'));
        return;
      }
      $this->manager->enqueueModules($targets, $trigger === 'retry' ? 'retry' : 'missing');
      $this->messenger()->addStatus($trigger === 'retry'
        ? $this->t('已加入失败任务重试队列。')
        : $this->t('已加入缺失字符串翻译队列。'));
    }
    $this->manager->processQueue(6);
    $form_state->setRedirect('xedc_ui_locale.manage', [], ['query' => $query]);
  }

  private function moduleExists(string $module): bool {
    return \Drupal::moduleHandler()->moduleExists($module);
  }

  private function clip(string $text): string {
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    if (mb_strlen($text) <= 80) {
      return $text;
    }
    return mb_substr($text, 0, 77) . '...';
  }

  private function originLabel(string $origin): string {
    return match ($origin) {
      'official' => (string) $this->t('官方'),
      'ai' => (string) $this->t('AI'),
      'existing' => (string) $this->t('已有'),
      'protected' => (string) $this->t('受保护'),
      default => '',
    };
  }

  private function statusLabel(string $status): string {
    return match ($status) {
      'pending' => (string) $this->t('待处理'),
      'official' => (string) $this->t('官方翻译处理中'),
      'ai' => (string) $this->t('AI 翻译处理中'),
      'completed' => (string) $this->t('已完成'),
      'failed' => (string) $this->t('翻译失败'),
      'retry' => (string) $this->t('等待重试'),
      'skipped' => (string) $this->t('无需翻译'),
      default => $status,
    };
  }

}
