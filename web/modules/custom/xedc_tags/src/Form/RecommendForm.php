<?php

namespace Drupal\xedc_tags\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\node\NodeInterface;
use Drupal\xedc_tags\TagAudit;
use Drupal\xedc_tags\TagRecommender;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Suggests existing tags and writes them only after confirmation.
 */
final class RecommendForm extends AdminFormBase {

  private const LIMIT = 20;

  public function __construct(
    private TagRecommender $recommender,
    private TagAudit $audit,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_tags.recommender'),
      $container->get('xedc_tags.audit'),
    );
  }

  public function getFormId(): string {
    return 'xedc_tag_recommend';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = $this->chrome($form, [
      'title' => $this->t('智能推荐'),
      'description' => $this->t('查看并人工确认内容级标签推荐。'),
    ]);
    $form['intro'] = $this->callout((string) $this->t('只从现有标签里推荐。分析不会保存，也不会删除原标签。匹配程度来自本地规则，不是语义模型。一次最多 @count 篇。', ['@count' => self::LIMIT]));
    $form['input_panel'] = $this->panel([
      'mode' => [
        '#type' => 'select',
        '#title' => $this->t('范围'),
        '#options' => [
          'single' => $this->t('单篇内容或一段文字'),
          'batch' => $this->t('最近 @count 篇已发布内容', ['@count' => self::LIMIT]),
        ],
        '#default_value' => 'single',
      ],
      'nid' => [
        '#type' => 'number',
        '#title' => $this->t('内容 ID'),
        '#min' => 1,
        '#states' => ['visible' => [':input[name="mode"]' => ['value' => 'single']]],
      ],
      'title' => ['#type' => 'textfield', '#title' => $this->t('标题')],
      'description' => ['#type' => 'textarea', '#title' => $this->t('描述'), '#rows' => 2],
      'body' => ['#type' => 'textarea', '#title' => $this->t('正文'), '#rows' => 4],
      'analyze' => [
        '#type' => 'submit',
        '#value' => $this->t('分析'),
        '#submit' => ['::analyze'],
        '#limit_validation_errors' => [['mode'], ['nid'], ['title'], ['description'], ['body']],
        '#attributes' => ['class' => ['button--primary']],
      ],
    ], ['xt-form-narrow']);
    $groups = $form_state->get('suggestions');
    if (is_array($groups) && $groups) {
      foreach ($groups as $group) {
        $nid = (int) $group['nid'];
        $key = $nid > 0 ? (string) $nid : 'text';
        $form['result'][$key] = [
          '#type' => 'details',
          '#title' => $group['title'] !== '' ? $group['title'] : $this->t('未保存的文字'),
          '#open' => TRUE,
        ];
        if (!$group['items']) {
          $form['result'][$key]['empty'] = ['#markup' => '<p>' . $this->t('没有可推荐的现有标签。') . '</p>'];
          continue;
        }
        foreach ($group['items'] as $item) {
          $form['result'][$key]['pick'][$item['tid']] = [
            '#type' => 'checkbox',
            '#title' => $item['name'] . ' · ' . $item['score'],
            '#description' => implode('；', $item['reasons']),
          ];
        }
      }
      $form['again'] = [
        '#type' => 'submit',
        '#value' => $this->t('重新分析'),
        '#submit' => ['::analyze'],
        '#limit_validation_errors' => [['mode'], ['nid'], ['title'], ['description'], ['body']],
      ];
      if ($this->currentUser()->hasPermission('apply xedc tag recommendations')) {
        $form['confirm'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('我确认把勾选的标签添加到对应内容，不删除原标签'),
        ];
        $form['apply'] = [
          '#type' => 'submit',
          '#value' => $this->t('写入勾选的标签'),
          '#submit' => ['::applyPicks'],
        ];
        $form['ignore'] = [
          '#type' => 'submit',
          '#value' => $this->t('忽略未勾选的建议'),
          '#submit' => ['::ignoreRest'],
          '#limit_validation_errors' => [],
        ];
      }
      else {
        $form['readonly'] = ['#markup' => '<p>' . $this->t('当前账号只能查看建议，不能写回内容。') . '</p>'];
      }
    }
    return $form;
  }

  public function analyze(array &$form, FormStateInterface $form_state): void {
    $groups = [];
    if ($form_state->getValue('mode') === 'batch') {
      $ids = \Drupal::entityTypeManager()->getStorage('node')->getQuery()
        ->accessCheck(TRUE)
        ->condition('status', 1)
        ->sort('created', 'DESC')
        ->range(0, self::LIMIT)
        ->execute();
      $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadMultiple($ids);
      foreach ($ids as $id) {
        $node = $nodes[$id] ?? NULL;
        if (!$node instanceof NodeInterface || !$node->access('view')) {
          continue;
        }
        $groups[] = $this->group($node, []);
      }
    }
    else {
      $nid = (int) $form_state->getValue('nid');
      $node = NULL;
      if ($nid > 0) {
        $loaded = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
        if (!$loaded instanceof NodeInterface || !$loaded->access('view')) {
          $this->messenger()->addError($this->t('找不到可查看的内容。'));
          $form_state->setRebuild(TRUE);
          return;
        }
        $node = $loaded;
      }
      $input = $node ? $this->recommender->fromNode($node) : [
        'title' => '',
        'body' => '',
        'description' => '',
        'current' => [],
        'bundle' => '',
      ];
      foreach (['title', 'description', 'body'] as $field) {
        $value = trim((string) $form_state->getValue($field));
        if ($value !== '') {
          $input[$field] = $value;
        }
      }
      if ($input['title'] === '' && $input['body'] === '' && $input['description'] === '') {
        $this->messenger()->addError($this->t('请填写内容 ID，或填写标题、描述、正文中的至少一项。'));
        $form_state->setRebuild(TRUE);
        return;
      }
      $groups[] = [
        'nid' => $nid,
        'title' => $input['title'],
        'items' => $this->recommender->suggest($input['title'], $input['body'], $input['description'], $input['current'], $input['bundle'], $nid ? $this->recommender->ignored($nid) : []),
      ];
    }
    $form_state->set('suggestions', $groups);
    $form_state->setRebuild(TRUE);
  }

  public function applyPicks(array &$form, FormStateInterface $form_state): void {
    if (!$this->currentUser()->hasPermission('apply xedc tag recommendations')) {
      $this->messenger()->addError($this->t('没有写入权限。'));
      return;
    }
    if (!$form_state->getValue('confirm')) {
      $form_state->setErrorByName('confirm', $this->t('请先确认。'));
      $form_state->setRebuild(TRUE);
      return;
    }
    $added = 0;
    $groups = $form_state->get('suggestions') ?: [];
    foreach ($groups as $group) {
      $nid = (int) $group['nid'];
      if ($nid <= 0) {
        continue;
      }
      $key = (string) $nid;
      $picks = (array) ($form_state->getValue(['result', $key, 'pick']) ?? []);
      $tids = array_map('intval', array_keys(array_filter($picks)));
      if (!$tids) {
        continue;
      }
      $node = \Drupal::entityTypeManager()->getStorage('node')->load($nid);
      if (!$node instanceof NodeInterface || !$node->access('update')) {
        $this->messenger()->addWarning($this->t('内容 @id 不能修改，已跳过。', ['@id' => $nid]));
        continue;
      }
      $added += $this->recommender->apply($node, $tids, (int) $this->currentUser()->id());
    }
    $this->messenger()->addStatus($this->t('已添加 @count 个标签。未勾选的标签没有写入。', ['@count' => $added]));
    $form_state->set('suggestions', NULL);
  }

  public function ignoreRest(array &$form, FormStateInterface $form_state): void {
    $groups = $form_state->get('suggestions') ?: [];
    $count = 0;
    foreach ($groups as $group) {
      $nid = (int) $group['nid'];
      if ($nid <= 0) {
        continue;
      }
      $picks = (array) ($form_state->getValue(['result', (string) $nid, 'pick']) ?? []);
      foreach ($group['items'] as $item) {
        if (empty($picks[$item['tid']])) {
          $this->audit->decide('recommend', $nid . ':' . $item['tid'], 'ignore');
          $count++;
        }
      }
    }
    $this->messenger()->addStatus($this->t('已忽略 @count 条建议。', ['@count' => $count]));
    $form_state->setRebuild(TRUE);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
  }

  /**
   * @param array<string, mixed> $override
   * @return array{nid: int, title: string, items: array<int, array<string, mixed>>}
   */
  private function group(NodeInterface $node, array $override): array {
    $input = $this->recommender->fromNode($node);
    return [
      'nid' => (int) $node->id(),
      'title' => $input['title'],
      'items' => $this->recommender->suggest($input['title'], $input['body'], $input['description'], $input['current'], $input['bundle'], $this->recommender->ignored((int) $node->id())),
    ];
  }

}
