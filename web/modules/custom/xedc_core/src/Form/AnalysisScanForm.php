<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\xedc_core\Ops\Cleanup\FileAnalysisRunner;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Starts the read-only duplicate and reference analysis.
 */
class AnalysisScanForm extends FormBase {

  public function __construct(
    protected FileAnalysisRunner $runner,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('xedc_core.file_analysis'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_core_file_analysis';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $status = (string) ($this->runner->summary()['status'] ?? 'idle');
    $form['#attributes']['class'][] = 'xedc-ops-form';
    if ($status === 'running') {
      $form['pause'] = ['#type' => 'submit', '#name' => 'pause', '#value' => $this->t('暂停分析')];
      $form['step'] = ['#type' => 'submit', '#name' => 'step', '#value' => $this->t('分析下一批')];
    }
    elseif ($status === 'paused' || $status === 'error') {
      $form['resume'] = ['#type' => 'submit', '#name' => 'resume', '#value' => $this->t('继续分析')];
    }
    else {
      $form['start'] = [
        '#type' => 'submit',
        '#name' => 'start',
        '#value' => $status === 'done' ? $this->t('重新分析') : $this->t('开始分析'),
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $name = (string) ($form_state->getTriggeringElement()['#name'] ?? 'start');
    if ($name === 'pause') {
      $this->messenger()->addStatus($this->runner->pause() === 'paused' ? $this->t('分析已暂停。') : $this->t('现在不能暂停。'));
      return;
    }
    if ($name === 'resume') {
      $result = $this->runner->resume();
      if ($result === 'resumed') {
        $this->runner->step();
        $this->messenger()->addStatus($this->t('已继续分析一批。'));
      }
      return;
    }
    if ($name === 'step') {
      $this->runner->step();
      $this->messenger()->addStatus($this->t('已处理一批。'));
      return;
    }
    $result = $this->runner->start();
    if ($result === 'started') {
      $this->runner->step();
      $this->messenger()->addStatus($this->t('分析已开始。这一步只读取和记录，不会删除文件。'));
    }
    elseif ($result === 'running') {
      $this->messenger()->addWarning($this->t('已有分析在进行。'));
    }
    else {
      $this->messenger()->addError($this->t('暂时不能开始分析。'));
    }
  }

}
