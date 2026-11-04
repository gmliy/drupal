<?php

namespace Drupal\xedc_tags\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\xedc_tags\TagMerger;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Rolls back one completed merge at a time.
 */
final class RollbackForm extends AdminFormBase {

  public function __construct(
    private TagMerger $merger,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('xedc_tags.merger'));
  }

  public function getFormId(): string {
    return 'xedc_tag_rollback';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $rows = \Drupal::service('xedc_tags.audit')->merges(30);
    $form['#tree'] = TRUE;
    if (!$rows) {
      $form['empty'] = ['#markup' => '<p>' . $this->t('还没有合并记录。') . '</p>'];
      return $form;
    }
    $header = [$this->t('时间'), $this->t('来源'), $this->t('目标'), $this->t('状态'), $this->t('迁移'), $this->t('说明'), $this->t('操作')];
    $form['table'] = ['#type' => 'table', '#header' => $header];
    foreach ($rows as $row) {
      $id = (int) $row->id;
      $form['table'][$id]['time'] = ['#plain_text' => $this->date((int) $row->created)];
      $form['table'][$id]['sources'] = ['#plain_text' => (string) $row->sources];
      $form['table'][$id]['target'] = ['#plain_text' => (string) $row->target_tid];
      $form['table'][$id]['status'] = ['#plain_text' => (string) $row->status];
      $form['table'][$id]['moved'] = ['#plain_text' => (string) $row->moved];
      $form['table'][$id]['message'] = ['#plain_text' => (string) $row->message];
      if ($row->status === 'completed' && $this->currentUser()->hasPermission('merge xedc tags')) {
        $form['table'][$id]['confirm'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('确认回滚'),
        ];
        $form['table'][$id]['submit'] = [
          '#type' => 'submit',
          '#value' => $this->t('回滚'),
          '#name' => 'rollback_' . $id,
          '#merge_id' => $id,
          '#submit' => ['::rollback'],
        ];
      }
      else {
        $form['table'][$id]['confirm'] = ['#markup' => ''];
        $form['table'][$id]['submit'] = ['#markup' => ''];
      }
    }
    return $form;
  }

  public function rollback(array &$form, FormStateInterface $form_state): void {
    $id = (int) ($form_state->getTriggeringElement()['#merge_id'] ?? 0);
    if (!$form_state->getValue(['table', $id, 'confirm'])) {
      $form_state->setErrorByName('table][' . $id . '][confirm', $this->t('请先确认回滚。'));
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
