<?php

declare(strict_types=1);

namespace Drupal\xedc_mail;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Site\Settings;

/**
 * Turns a plain-text core user mail into HTML without changing its words.
 */
final class MailWrapper {

  /**
   * Mail to wrap, keyed by module then mail key. The value is the button label.
   *
   * An empty label means the message is wrapped but has no button.
   */
  public const MAILS = [
    'user' => [
      'register_admin_created' => '激活账户',
      'register_no_approval_required' => '登录并设置密码',
      'register_pending_approval' => '',
      'register_pending_approval_admin' => '',
      'password_reset' => '重置密码',
      'status_activated' => '登录账户',
      'status_blocked' => '',
      'status_canceled' => '',
      'cancel_confirm' => '确认取消账户',
    ],
    'user_registrationpassword' => [
      'register_confirmation_with_pass' => '验证并登录',
    ],
  ];

  /**
   * @return string[]
   *   Every allowed mail key.
   */
  public static function keys(): array {
    $keys = [];
    foreach (self::MAILS as $group) {
      foreach (array_keys($group) as $key) {
        $keys[] = $key;
      }
    }
    return $keys;
  }

  public static function moduleOf(string $key): string {
    foreach (self::MAILS as $module => $group) {
      if (array_key_exists($key, $group)) {
        return $module;
      }
    }
    return '';
  }

  /**
   * When TRUE, the next apply() throws before editing the message.
   */
  public static bool $failNext = FALSE;

  public function __construct(
    private readonly RendererInterface $renderer,
    private readonly ConfigFactoryInterface $configFactory,
  ) {
  }

  /**
   * @param array<string, mixed> $message
   *   A Drupal mail message.
   */
  public function apply(array &$message): void {
    if (self::$failNext) {
      self::$failNext = FALSE;
      throw new \RuntimeException('Simulated mail wrapper failure.');
    }
    $module = (string) ($message['module'] ?? '');
    $key = (string) ($message['key'] ?? '');
    if (!isset(self::MAILS[$module][$key])) {
      return;
    }
    $plain = $this->plainBody($message['body'] ?? '');
    if ($plain === '') {
      return;
    }
    $html = $this->renderHtml($key, $plain, (string) ($message['subject'] ?? ''));
    $boundary = 'xedc-mail-' . bin2hex(random_bytes(8));
    $message['headers']['Content-Type'] = 'multipart/alternative; charset=UTF-8; boundary="' . $boundary . '"';
    $message['body'] = [$this->multipart($plain, $html, $boundary)];
  }

  /**
   * Renders one mail for the preview page or an export.
   */
  public function renderHtml(string $key, string $plain, string $subject): string {
    $site = $this->configFactory->get('system.site');
    $siteName = (string) ($site->get('name') ?: '站点');
    $siteUrl = $this->siteUrl();
    $buttonLabel = $this->buttonLabel($key);
    $buttonUrl = $buttonLabel === '' ? '' : $this->firstUrl($plain);
    $build = [
      '#theme' => 'xedc_mail',
      '#site_name' => $siteName,
      '#site_url' => $siteUrl,
      '#preheader' => $this->preheader($key, $siteName),
      '#heading' => $subject !== '' ? $subject : $this->preheader($key, $siteName),
      '#body' => Markup::create($this->bodyHtml($plain)),
      '#button_label' => $buttonLabel,
      '#button_url' => $buttonUrl,
      '#footer' => '此邮件由 ' . $siteName . ' 自动发送，请勿直接回复。如果这不是你本人的操作，请忽略此邮件。',
    ];
    return (string) $this->renderer->renderInIsolation($build);
  }

  /**
   * Example plain text for a key, using the saved template and fake tokens.
   */
  public function examplePlain(string $key): string {
    $config = self::moduleOf($key) === 'user_registrationpassword' ? 'user_registrationpassword.mail' : 'user.mail';
    $item = $this->configFactory->get($config)->get($key);
    $body = is_array($item) ? (string) ($item['body'] ?? '') : '';
    $site = $this->configFactory->get('system.site');
    $name = (string) ($site->get('name') ?: 'DcLili');
    $url = $this->siteUrl();
    $map = [
      '[site:name]' => $name,
      '[site:url]' => $url,
      '[site:url-brief]' => (string) (parse_url($url, PHP_URL_HOST) ?: $url),
      '[site:login-url]' => $url . '/user/login',
      '[user:display-name]' => '张三',
      '[user:name]' => 'zhangsan',
      '[user:mail]' => 'zhangsan@example.com',
      '[user:one-time-login-url]' => $url . '/user/reset/example',
      '[user:registrationpassword-url]' => $url . '/user/register/confirm/example',
      '[user:cancel-url]' => $url . '/user/cancel/example',
      '[user:edit-url]' => $url . '/user/1/edit',
    ];
    return strtr($body, $map);
  }

  public function exampleSubject(string $key): string {
    $config = self::moduleOf($key) === 'user_registrationpassword' ? 'user_registrationpassword.mail' : 'user.mail';
    $item = $this->configFactory->get($config)->get($key);
    $subject = is_array($item) ? (string) ($item['subject'] ?? '') : '';
    $name = (string) ($this->configFactory->get('system.site')->get('name') ?: 'DcLili');
    return strtr($subject, [
      '[site:name]' => $name,
      '[user:display-name]' => '张三',
    ]);
  }

  /**
   * @param mixed $body
   *   A string or a list of strings.
   */
  private function plainBody(mixed $body): string {
    if (is_string($body)) {
      return trim($body);
    }
    if (!is_array($body)) {
      return '';
    }
    $parts = [];
    foreach ($body as $part) {
      if (is_string($part) && trim($part) !== '') {
        $parts[] = trim($part);
      }
    }
    return trim(implode("\n\n", $parts));
  }

  private function multipart(string $plain, string $html, string $boundary): string {
    return "--{$boundary}\r\n" .
      "Content-Type: text/plain; charset=UTF-8\r\n" .
      "Content-Transfer-Encoding: 8bit\r\n\r\n" .
      $plain . "\r\n\r\n" .
      "--{$boundary}\r\n" .
      "Content-Type: text/html; charset=UTF-8\r\n" .
      "Content-Transfer-Encoding: 8bit\r\n\r\n" .
      $html . "\r\n\r\n" .
      "--{$boundary}--\r\n";
  }

  private function bodyHtml(string $plain): string {
    $normalized = str_replace(["\r\n", "\r"], "\n", $plain);
    $blocks = preg_split("/\n{2,}/", $normalized) ?: [];
    $html = '';
    foreach ($blocks as $block) {
      $block = trim($block);
      if ($block === '') {
        continue;
      }
      $html .= '<p style="margin:0 0 16px;font-size:15px;line-height:1.7;color:#3F3F46;">' . nl2br($this->linkify($block), FALSE) . '</p>';
    }
    return $html;
  }

  private function linkify(string $text): string {
    $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $linked = preg_replace_callback('#https?://[^\s&<]+#', function (array $match): string {
      $url = rtrim(htmlspecialchars_decode($match[0], ENT_QUOTES), '.,);]');
      $safe = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      return '<a href="' . $safe . '" style="color:#27272A;text-decoration:underline;word-break:break-all;">' . $safe . '</a>';
    }, $escaped);
    return is_string($linked) ? $linked : $escaped;
  }

  private function firstUrl(string $plain): string {
    if (!preg_match('#https?://[^\s<>]+#', $plain, $match)) {
      return '';
    }
    return rtrim($match[0], '.,);]');
  }

  private function buttonLabel(string $key): string {
    $module = self::moduleOf($key);
    return $module === '' ? '' : (string) self::MAILS[$module][$key];
  }

  private function preheader(string $key, string $siteName): string {
    return match ($key) {
      'password_reset' => '重置你在' . $siteName . '的密码。',
      'register_no_approval_required' => '欢迎加入' . $siteName . '，请设置密码。',
      'register_admin_created' => '管理员为你创建了' . $siteName . '账户。',
      'register_pending_approval' => '你的' . $siteName . '账户正在等待审核。',
      'register_pending_approval_admin' => '有新用户申请加入' . $siteName . '。',
      'status_activated' => '你的' . $siteName . '账户已激活。',
      'status_blocked' => '你的' . $siteName . '账户已被冻结。',
      'status_canceled' => '你的' . $siteName . '账户已注销。',
      'cancel_confirm' => '确认取消你在' . $siteName . '的账户。',
      'register_confirmation_with_pass' => '请验证你在' . $siteName . '的账户并登录。',
      default => $siteName,
    };
  }

  private function siteUrl(): string {
    $request = \Drupal::request();
    $host = $request->getHost();
    if ($host !== '' && !in_array($host, ['default', 'localhost'], TRUE)) {
      return $request->getSchemeAndHttpHost();
    }
    $trusted = Settings::get('trusted_host_patterns', []);
    if (is_array($trusted) && isset($trusted[0]) && is_string($trusted[0])) {
      $name = str_replace('\\.', '.', trim($trusted[0], '^$'));
      if ($name !== '') {
        return 'https://' . $name;
      }
    }
    return 'https://gitxx.xyz';
  }

}
