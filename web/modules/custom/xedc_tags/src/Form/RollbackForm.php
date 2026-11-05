<?php

namespace Drupal\xedc_tags\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\xedc_tags\TagCatalog;
use Drupal\xedc_tags\TagLabels;
use Drupal\xedc_tags\TagMerger;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Rolls back one completed merge at a time.
 */
final class RollbackForm extends AdminFormBase {

  public function __construct(
    private TagMerger $merger,
    private TagLabels $labels,
    private TagCatalog $catalog,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_tags.merger'),
      $container->get('xedc_tags.labels'),
      $container->get('xedc_tags.catalog'),
    );
  }

  public function getFormId(): string {
    return 'xedc_tag_rollback';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $rows = \Drupal::service('xedc_tags.audit')->merges(30);
    $form['#tree'] = TRUE;
    $form['#attached']['library'][] = 'xedc_tags/admin';
    if (!$rows) {
      $form['empty'] = ['#markup' => '<p class="xt-empty">' . $this->t('还没有合并记录。') . '</p>'];
      return $form;
    }
    $terms = $this->catalog->terms();
    $header = [
      $this->t('时间'),
      $this->t('来源标签'),
      $this->t('目标标签'),
      $this->t('状态'),
      $this->t('迁移数量'),
      $this->t('说明'),
      $this->t('操作'),
    ];
    $form['notice'] = $this->callout((string) $this->t('回滚会恢复合并时快照中的标签引用。危险操作，请逐条确认。'), 'danger');
    $form['table_wrap'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['xt-table-wrap']],
    ];
    $form['table_wrap']['table'] = ['#type' => 'table', '#attributes' => ['class' => ['xt-table']], '#header' => $header];
    foreach ($rows as $row) {
      $id = (int) $row->id;
      $sourceIds = array_filter(array_map('intval', explode(',', (string) $row->sources)));
      $sourceNames = [];
      foreach ($sourceIds as $sid) {
        $sourceNames[] = ($terms[$sid]['name'] ?? ('#' . $sid));
      }
      $targetTid = (int) $row->target_tid;
      $targetName = (string) ($terms[$targetTid]['name'] ?? ('#' . $targetTid));
      $form['table_wrap']['table'][$id]['time'] = ['#plain_text' => $this->date((int) $row->created)];
      $form['table_wrap']['table'][$id]['sources'] = ['#plain_text' => $sourceNames ? implode('、', $sourceNames) : (string) $row->sources];
      $form['table_wrap']['table'][$id]['target'] = ['#markup' => $this->tagNameMarkup($targetName, $targetTid)];
      $form['table_wrap']['table'][$id]['status'] = ['#markup' => $this->badge((string) $this->labels->mergeStatus((string) $row->status), $this->labels->statusTone((string) $row->status))];
      $form['table_wrap']['table'][$id]['moved'] = ['#markup' => '<span class="xt-num">' . (int) $row->moved . '</span>'];
      $form['table_wrap']['table'][$id]['message'] = ['#plain_text' => (string) ($row->message !== '' ? $row->message : '—')];
      if ($row->status === 'completed' && $this->currentUser()->hasPermission('merge xedc tags')) {
        $form['table_wrap']['table'][$id]['ops'] = [
          '#type' => 'container',
          '#attributes' => ['class' => ['xt-ops']],
          'confirm' => [
            '#type' => 'checkbox',
            '#title' => $this->t('确认回滚'),
          ],
          'submit' => [
            '#type' => 'submit',
            '#value' => $this->t('回滚'),
            '#name' => 'rollback_' . $id,
            '#merge_id' => $id,
            '#submit' => ['::rollback'],
            '#attributes' => ['class' => ['button--danger']],
          ],
        ];
      }
      else {
        $form['table_wrap']['table'][$id]['ops'] = ['#markup' => '—'];
      }
    }
    return $form;
  }

  public function rollback(array &$form, FormStateInterface $form_state): void {
    $id = (int) ($form_state->getTriggeringElement()['#merge_id'] ?? 0);
    if (!$form_state->getValue(['table_wrap', 'table', $id, 'ops', 'confirm'])) {
      $form_state->setErrorByName('table_wrap][table][' . $id . '][ops][confirm', $this->t('请先确认回滚。'));
      return;
    }
    $result = $this->merger->rollback($id, (int) $this->currentUser()->id());
    if ($result['ok']) {
      $this->messenger()->addStatus($result['message']);
    }
    else {
      $this->messenger()->addError($result['message']);
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
  }

}
