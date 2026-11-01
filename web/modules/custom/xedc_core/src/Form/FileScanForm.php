<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\xedc_core\Ops\FileScanner;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Starts, pauses, and continues the read-only file scan.
 */
class FileScanForm extends FormBase {

  public function __construct(
    protected FileScanner $scanner,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('xedc_core.file_scanner'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_core_file_scan';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $status = $this->scanner->jobSummary()['status'];
    $form['#attributes']['class'][] = 'xedc-ops-form';
    if ($status === 'running') {
      $form['pause'] = [
        '#type' => 'submit',
        '#name' => 'pause',
        '#value' => $this->t('暂停扫描'),
      ];
      $form['step'] = [
        '#type' => 'submit',
        '#name' => 'step',
        '#value' => $this->t('处理下一批'),
      ];
    }
    elseif ($status === 'paused' || $status === 'error') {
      $form['resume'] = [
        '#type' => 'submit',
        '#name' => 'resume',
        '#value' => $this->t('继续扫描'),
      ];
    }
    else {
      $form['start'] = [
        '#type' => 'submit',
        '#name' => 'start',
        '#value' => $status === 'done' ? $this->t('重新扫描') : $this->t('开始扫描'),
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
      $result = $this->scanner->pause();
      $this->messenger()->addStatus($result === 'paused' ? $this->t('扫描已暂停。') : $this->t('现在不能暂停。'));
    }
    elseif ($name === 'resume') {
      $result = $this->scanner->resume();
      if ($result === 'resumed') {
        $this->scanner->step();
        $this->messenger()->addStatus($this->t('已继续扫描一批。计划任务会接着处理。'));
      }
      else {
        $this->messenger()->addWarning($this->t('没有可继续的扫描。'));
      }
    }
    elseif ($name === 'step') {
      $result = $this->scanner->step();
      $this->messenger()->addStatus($result === 'busy' ? $this->t('已有一批正在处理。') : $this->t('已处理一批。'));
    }
    else {
      $result = $this->scanner->start();
      if ($result === 'started') {
        $this->scanner->step();
        $this->messenger()->addStatus($this->t('扫描已开始。这一步只读取文件，不会删除或修改。'));
      }
      elseif ($result === 'running') {
        $this->messenger()->addWarning($this->t('扫描已在进行，没有另起一个任务。'));
      }
      else {
        $this->messenger()->addError($this->t('暂时不能开始扫描。'));
      }
    }
  }

}
