<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Admin action log. API keys are not stored in these rows.
 */
final class LogController extends ControllerBase {

  public function __construct(
    private readonly Connection $database,
    private readonly DateFormatterInterface $dates,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Recent operations.
   */
  public function page(): array {
    $query = $this->database->select('xedc_ai_audit', 'a')
      ->extend('Drupal\Core\Database\Query\PagerSelectExtender')
      ->fields('a')
      ->orderBy('a.id', 'DESC')
      ->limit(30);
    $rows = [];
    $names = [];
    foreach ($query->execute() as $row) {
      $row = (array) $row;
      $uid = (int) $row['uid'];
      if (!isset($names[$uid])) {
        $account = $uid > 0 ? $this->entityTypeManager()->getStorage('user')->load($uid) : NULL;
        $names[$uid] = $account instanceof UserInterface ? (string) $account->getDisplayName() : ($uid > 0 ? (string) $uid : '系统');
      }
      $rows[] = [
        'time' => $this->dates->format((int) $row['created'], 'short'),
        'user' => $names[$uid],
        'action' => (string) $row['action'],
        'message' => (string) $row['message'],
        'target' => trim((string) ($row['target_type'] ?? '') . ' ' . (string) ($row['target_id'] ?? '')),
        'before' => (string) ($row['before_text'] ?? ''),
        'after' => (string) ($row['after_text'] ?? ''),
        'suggestion' => (string) ($row['suggestion'] ?? ''),
        'confirmed' => (int) ($row['confirmed'] ?? 0) === 1,
        'outcome' => (string) ($row['outcome'] ?? ''),
      ];
    }
    return [
      '#theme' => 'xedc_ai_logs',
      '#rows' => $rows,
      '#pager' => ['#type' => 'pager'],
      '#attached' => ['library' => ['xedc_ai/admin']],
      '#cache' => ['max-age' => 0],
    ];
  }

}
