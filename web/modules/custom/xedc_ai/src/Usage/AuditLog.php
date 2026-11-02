<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Usage;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountInterface;
use Drupal\xedc_ai\Client\Redactor;
use Psr\Log\LoggerInterface;

/**
 * Sensitive admin actions. Messages are redacted before they are stored.
 */
final class AuditLog {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly AccountInterface $account,
    private readonly LoggerInterface $logger,
  ) {}

  public function log(string $action, string $message, ?int $uid = NULL, array $context = []): void {
    $message = Redactor::text($message);
    $uid = $uid ?? (int) $this->account->id();
    $this->database->insert('xedc_ai_audit')->fields([
      'created' => $this->time->getRequestTime(),
      'uid' => $uid,
      'action' => substr($action, 0, 64),
      'message' => $message,
      'target_type' => substr((string) ($context['target_type'] ?? ''), 0, 32),
      'target_id' => substr((string) ($context['target_id'] ?? ''), 0, 64),
      'before_text' => Redactor::secret((string) ($context['before'] ?? '')),
      'after_text' => Redactor::secret((string) ($context['after'] ?? '')),
      'suggestion' => Redactor::secret((string) ($context['suggestion'] ?? '')),
      'confirmed' => !empty($context['confirmed']) ? 1 : 0,
      'outcome' => substr((string) ($context['result'] ?? ''), 0, 32),
    ])->execute();
    $this->logger->info('@action by @uid: @message', [
      '@action' => $action,
      '@uid' => $uid,
      '@message' => $message,
    ]);
  }

}
