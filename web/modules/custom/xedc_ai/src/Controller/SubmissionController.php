<?php

declare(strict_types=1);

namespace Drupal\xedc_ai\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Url;
use Drupal\xedc_ai\Form\SubmissionActionForm;
use Drupal\xedc_ai\Labels;
use Drupal\xedc_ai\Review\SubmissionModerator;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Member submissions waiting in the existing moderation workflow.
 */
final class SubmissionController extends ControllerBase {

  public function __construct(
    private readonly SubmissionModerator $moderator,
    private readonly DateFormatterInterface $dates,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_ai.submissions'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Submission queue.
   */
  public function page(): array {
    $rows = [];
    foreach ($this->moderator->waiting() as $row) {
      $rows[] = [
        'nid' => $row['nid'],
        'title' => $row['title'],
        'bundle' => Labels::bundle((string) $row['bundle']),
        'author' => $row['author'] !== '' ? $row['author'] : ('用户 ' . $row['uid']),
        'time' => $this->dates->format((int) $row['created'], 'short'),
        'gaps' => $row['gaps'],
        'review' => $row['review_id'] > 0 ? Labels::verdict((string) $row['review_status'], (string) $row['risk_level'], '', '') : '还没有 AI 审核',
        'review_url' => $row['review_id'] > 0 ? Url::fromRoute('xedc_ai.review', ['review' => $row['review_id']])->toString() : '',
        'edit_url' => Url::fromRoute('entity.node.edit_form', ['node' => $row['nid']])->toString(),
        'raw' => $row,
      ];
    }
    $canAct = $this->currentUser()->hasPermission('moderate xedc ai submissions') || $this->currentUser()->hasPermission('execute xedc ai reviews');
    return [
      '#theme' => 'xedc_ai_submissions',
      '#rows' => $rows,
      '#action' => $canAct ? $this->formBuilder()->getForm(SubmissionActionForm::class, array_map(static fn (array $row): array => $row['raw'], $rows)) : NULL,
      '#moderation_url' => Url::fromRoute('view.moderated_content.moderated_content')->toString(),
      '#attached' => ['library' => ['xedc_ai/admin']],
      '#cache' => ['max-age' => 0],
    ];
  }

}
