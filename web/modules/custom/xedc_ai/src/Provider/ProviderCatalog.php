<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Provider;

/**
 * Preset OpenAI-compatible providers. Model names are suggestions only.
 */
final class ProviderCatalog {

  /**
   * @return array<string, array{label: string, base_url: string, suggested_model: string}>
   *   Presets keyed by driver id.
   */
  public static function presets(): array {
    return [
      'deepseek' => [
        'label' => 'DeepSeek',
        'base_url' => 'https://api.deepseek.com/v1',
        'suggested_model' => 'deepseek-chat',
      ],
      'openai' => [
        'label' => 'OpenAI',
        'base_url' => 'https://api.openai.com/v1',
        'suggested_model' => 'gpt-4o-mini',
      ],
      'dashscope' => [
        'label' => '阿里云百炼',
        'base_url' => 'https://dashscope.aliyuncs.com/compatible-mode/v1',
        'suggested_model' => 'qwen-plus',
      ],
      'siliconflow' => [
        'label' => '硅基流动',
        'base_url' => 'https://api.siliconflow.cn/v1',
        'suggested_model' => '',
      ],
      'volcengine' => [
        'label' => '火山引擎',
        'base_url' => 'https://ark.cn-beijing.volces.com/api/v3',
        'suggested_model' => '',
      ],
      'zhipu' => [
        'label' => '智谱 AI',
        'base_url' => 'https://open.bigmodel.cn/api/paas/v4',
        'suggested_model' => 'glm-4-flash',
      ],
      'moonshot' => [
        'label' => 'Moonshot AI',
        'base_url' => 'https://api.moonshot.cn/v1',
        'suggested_model' => 'moonshot-v1-8k',
      ],
      'custom' => [
        'label' => '自定义 OpenAI Compatible API',
        'base_url' => '',
        'suggested_model' => '',
      ],
    ];
  }

  /**
   * @return array<string, string>
   *   Driver id to label.
   */
  public static function options(): array {
    $options = [];
    foreach (self::presets() as $id => $preset) {
      $options[$id] = $preset['label'];
    }
    return $options;
  }

  public static function validDriver(string $driver): bool {
    return isset(self::presets()[$driver]);
  }

  public static function validId(string $id): bool {
    return (bool) preg_match('/^[a-z][a-z0-9_]{0,31}$/', $id);
  }

  /**
   * HTTPS endpoints, plus loopback HTTP for a local mock.
   */
  public static function validBaseUrl(string $url): bool {
    $url = trim($url);
    if (filter_var($url, FILTER_VALIDATE_URL) === FALSE) {
      return FALSE;
    }
    $parts = parse_url($url);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($scheme === 'https') {
      return TRUE;
    }
    return $scheme === 'http' && in_array($host, ['127.0.0.1', 'localhost'], TRUE);
  }

}
