<?php

declare(strict_types=1);

namespace Drupal\xedc_search\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Search settings. Every value is read by the API or the front-end library.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_search_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['xedc_search.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('xedc_search.settings');
    $form['#attributes']['class'][] = 'xedc-search-admin';
    $form['#attached']['library'][] = 'xedc_search/admin';
    $form['switches'] = [
      '#type' => 'details',
      '#title' => $this->t('功能开关'),
      '#open' => TRUE,
    ];
    foreach ([
      'enabled' => '搜索功能',
      'suggest_enabled' => '自动联想',
      'complete_enabled' => '自动补全',
      'hot_enabled' => '热门搜索',
      'history_enabled' => '搜索历史',
    ] as $key => $title) {
      $form['switches'][$key] = [
        '#type' => 'checkbox',
        '#title' => $title,
        '#default_value' => (bool) $config->get($key),
      ];
    }
    $form['limits'] = [
      '#type' => 'details',
      '#title' => $this->t('交互和性能'),
      '#open' => TRUE,
    ];
    $form['limits']['min_length'] = [
      '#type' => 'number',
      '#title' => $this->t('最小搜索长度'),
      '#min' => 1,
      '#max' => 10,
      '#default_value' => (int) $config->get('min_length'),
    ];
    $form['limits']['debounce_ms'] = [
      '#type' => 'number',
      '#title' => $this->t('防抖毫秒'),
      '#description' => $this->t('前台等待输入停止后再请求建议。'),
      '#min' => 200,
      '#max' => 800,
      '#default_value' => (int) $config->get('debounce_ms'),
    ];
    $form['limits']['group_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('每组建议数量'),
      '#min' => 3,
      '#max' => 5,
      '#default_value' => (int) $config->get('group_limit'),
    ];
    $form['limits']['cache_seconds'] = [
      '#type' => 'number',
      '#title' => $this->t('缓存秒数'),
      '#min' => 0,
      '#max' => 3600,
      '#default_value' => (int) $config->get('cache_seconds'),
    ];
    $form['limits']['page_size'] = [
      '#type' => 'number',
      '#title' => $this->t('搜索结果每页条数'),
      '#min' => 6,
      '#max' => 24,
      '#default_value' => (int) $config->get('page_size'),
    ];
    $form['limits']['log_retention_days'] = [
      '#type' => 'number',
      '#title' => $this->t('搜索日志保留天数'),
      '#min' => 7,
      '#max' => 365,
      '#default_value' => (int) $config->get('log_retention_days'),
    ];
    $form['limits']['hot_window'] = [
      '#type' => 'select',
      '#title' => $this->t('热门榜默认周期'),
      '#options' => [
        'today' => $this->t('今日'),
        'week' => $this->t('本周'),
        'month' => $this->t('本月'),
      ],
      '#default_value' => (string) $config->get('hot_window'),
    ];
    $form['limits']['flood_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('接口频率上限'),
      '#min' => 30,
      '#max' => 600,
      '#default_value' => (int) $config->get('flood_limit'),
    ];
    $form['limits']['flood_window'] = [
      '#type' => 'number',
      '#title' => $this->t('频率窗口（秒）'),
      '#min' => 10,
      '#max' => 600,
      '#default_value' => (int) $config->get('flood_window'),
    ];
    $form['limits']['track_limit'] = [
      '#type' => 'number',
      '#title' => $this->t('统计写入上限'),
      '#description' => $this->t('同一 IP 在频率窗口内最多记多少次搜索，用来防止刷榜。'),
      '#min' => 5,
      '#max' => 120,
      '#default_value' => (int) $config->get('track_limit'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $keys = [
      'enabled', 'suggest_enabled', 'complete_enabled', 'hot_enabled', 'history_enabled',
      'min_length', 'debounce_ms', 'group_limit', 'cache_seconds', 'page_size',
      'log_retention_days', 'hot_window', 'flood_limit', 'flood_window', 'track_limit',
    ];
    $config = $this->config('xedc_search.settings');
    foreach ($keys as $key) {
      $value = $form_state->getValue($key);
      if (is_numeric($value) && $key !== 'hot_window') {
        $value = (int) $value;
      }
      if (in_array($key, ['enabled', 'suggest_enabled', 'complete_enabled', 'hot_enabled', 'history_enabled'], TRUE)) {
        $value = (bool) $value;
      }
      $config->set($key, $value);
    }
    $config->save();
    parent::submitForm($form, $form_state);
  }

}
