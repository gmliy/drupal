<?php

namespace Drupal\xedc_tags\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\xedc_tags\TagAliasManager;
use Drupal\xedc_tags\TagCatalog;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Manage synonym / history aliases that map to existing tags.
 */
final class AliasForm extends AdminFormBase {

  public function __construct(
    private TagAliasManager $aliases,
    private TagCatalog $catalog,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_tags.aliases'),
      $container->get('xedc_tags.catalog'),
    );
  }

  public function getFormId(): string {
    return 'xedc_tag_alias';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = $this->chrome($form);
    $request = $this->getRequest()->query;
    $q = trim((string) $request->get('q', ''));
    $tid = (int) $request->get('tid', 0);
    $status = (string) $request->get('status', '');
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('别名映射到标准标签，不会新建 Taxonomy Term，也不会自动改已发布内容的标签。') . '</p>',
    ];
    $options = [];
    foreach ($this->catalog->terms() as $term) {
      $options[(int) $term['tid']] = $term['name'] . ' (#' . $term['tid'] . ')';
    }
    $form['alias'] = [
      '#type' => 'textfield',
      '#title' => $this->t('别名'),
      '#required' => TRUE,
      '#maxlength' => 191,
    ];
    $form['tid'] = [
      '#type' => 'select',
      '#title' => $this->t('标准标签'),
      '#options' => $options,
      '#required' => TRUE,
      '#default_value' => $tid ?: NULL,
    ];
    $form['type'] = [
      '#type' => 'select',
      '#title' => $this->t('类型'),
      '#options' => TagAliasManager::TYPES,
      '#default_value' => 'synonym',
    ];
    $form['source'] = [
      '#type' => 'textfield',
      '#title' => $this->t('来源说明'),
      '#default_value' => 'manual',
      '#maxlength' => 64,
    ];
    $form['create'] = [
      '#type' => 'submit',
      '#value' => $this->t('新增别名'),
      '#submit' => ['::createAlias'],
    ];
    if ($form_state->getValue('alias') || $q) {
      $suggestInput = trim((string) ($form_state->getValue('alias') ?: $q));
      $suggestions = $this->aliases->suggestTargets($suggestInput);
      if ($suggestions) {
        $rows = [];
        foreach ($suggestions as $item) {
          $rows[] = [$item['name'], $item['tid'], $item['score'], $item['reason']];
        }
        $form['suggest'] = [
          '#type' => 'table',
          '#caption' => $this->t('可能的标准标签（仅推荐）'),
          '#header' => [$this->t('标签'), 'ID', $this->t('分'), $this->t('原因')],
          '#rows' => $rows,
        ];
      }
    }
    $form['filter_q'] = [
      '#type' => 'textfield',
      '#title' => $this->t('搜索别名'),
      '#default_value' => $q,
    ];
    $form['filter'] = [
      '#type' => 'submit',
      '#value' => $this->t('筛选'),
      '#submit' => ['::filter'],
      '#limit_validation_errors' => [['filter_q']],
    ];
    $header = [$this->t('别名'), $this->t('标准标签'), $this->t('类型'), $this->t('状态'), $this->t('来源'), $this->t('操作')];
    $form['table'] = ['#type' => 'table', '#header' => $header, '#empty' => $this->t('还没有别名。')];
    $terms = $this->catalog->terms();
    foreach ($this->aliases->list($q, $tid, $status) as $row) {
      $id = (int) $row->id;
      $form['table'][$id]['alias'] = ['#plain_text' => (string) $row->alias];
      $form['table'][$id]['term'] = ['#plain_text' => ($terms[(int) $row->tid]['name'] ?? '') . ' (#' . $row->tid . ')'];
      $form['table'][$id]['type'] = ['#plain_text' => TagAliasManager::TYPES[$row->type] ?? $row->type];
      $form['table'][$id]['status'] = ['#plain_text' => $row->status === 'active' ? (string) $this->t('启用') : (string) $this->t('停用')];
      $form['table'][$id]['source'] = ['#plain_text' => (string) $row->source];
      $form['table'][$id]['ops'] = [
        'disable' => [
          '#type' => 'submit',
          '#value' => $row->status === 'active' ? $this->t('停用') : $this->t('启用'),
          '#name' => 'toggle_' . $id,
          '#alias_id' => $id,
          '#alias_status' => $row->status === 'active' ? 'disabled' : 'active',
          '#submit' => ['::toggle'],
          '#limit_validation_errors' => [],
        ],
        'delete' => [
          '#type' => 'submit',
          '#value' => $this->t('删除'),
          '#name' => 'delete_' . $id,
          '#alias_id' => $id,
          '#submit' => ['::deleteAlias'],
          '#limit_validation_errors' => [],
          '#attributes' => ['onclick' => 'return confirm("确认删除这个别名？");'],
        ],
      ];
    }
    return $form;
  }

  public function createAlias(array &$form, FormStateInterface $form_state): void {
    $result = $this->aliases->save([
      'alias' => (string) $form_state->getValue('alias'),
      'tid' => (int) $form_state->getValue('tid'),
      'type' => (string) $form_state->getValue('type'),
      'source' => (string) $form_state->getValue('source'),
      'status' => 'active',
    ], (int) $this->currentUser()->id());
    $result['ok'] ? $this->messenger()->addStatus($result['message']) : $this->messenger()->addError($result['message']);
  }

  public function filter(array &$form, FormStateInterface $form_state): void {
    $form_state->setRedirect('xedc_tags.admin_aliases', [], [
      'query' => array_filter(['q' => trim((string) $form_state->getValue('filter_q'))]),
    ]);
  }

  public function toggle(array &$form, FormStateInterface $form_state): void {
    $id = (int) ($form_state->getTriggeringElement()['#alias_id'] ?? 0);
    $status = (string) ($form_state->getTriggeringElement()['#alias_status'] ?? 'disabled');
    $result = $this->aliases->setStatus($id, $status, (int) $this->currentUser()->id());
    $result['ok'] ? $this->messenger()->addStatus($result['message']) : $this->messenger()->addError($result['message']);
  }

  public function deleteAlias(array &$form, FormStateInterface $form_state): void {
    $id = (int) ($form_state->getTriggeringElement()['#alias_id'] ?? 0);
    $result = $this->aliases->delete($id, (int) $this->currentUser()->id());
    $result['ok'] ? $this->messenger()->addStatus($result['message']) : $this->messenger()->addError($result['message']);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
  }

}
