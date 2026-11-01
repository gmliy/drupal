<?php

declare(strict_types=1);

namespace Drupal\xedc_admin_ui;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\content_moderation\StateTransitionValidationInterface;
use Drupal\Core\Access\AccessException;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\node\NodeInterface;
use Drupal\workflows\TransitionInterface;

/**
 * Applies xedc_editorial transitions through Content Moderation.
 *
 * This class never writes node status directly. A save only happens after
 * the workflow exposes that transition to the account and the account can
 * update the revision.
 */
final class ModerationGuard {

  use StringTranslationTrait;

  public function __construct(
    private readonly ContentOps $contentOps,
    private readonly ModerationInformationInterface $moderationInfo,
    private readonly StateTransitionValidationInterface $validation,
    private readonly TimeInterface $time,
  ) {}

  /**
   * Transitions the account may use on the latest affected default-language revision.
   *
   * @return list<\Drupal\workflows\TransitionInterface>
   *   Allowed transitions.
   */
  public function validTransitions(int $nid, string $langcode, AccountInterface $account): array {
    $revision = $this->contentOps->loadAffectedRevision($nid, $langcode);
    if (!$revision instanceof NodeInterface) {
      return [];
    }
    return $this->transitionsForRevision($revision, $account);
  }

  /**
   * Picks one allowed transition whose target state is $toState.
   */
  public function transitionTo(NodeInterface $revision, AccountInterface $account, string $toState): ?TransitionInterface {
    $current = (string) $revision->get('moderation_state')->value;
    $matches = [];
    foreach ($this->transitionsForRevision($revision, $account) as $transition) {
      if ($transition->to()->id() === $toState) {
        $matches[$transition->id()] = $transition;
      }
    }
    if ($matches === []) {
      return NULL;
    }
    if ($toState === 'published' && $current === 'needs_review' && isset($matches['approve'])) {
      return $matches['approve'];
    }
    if ($toState === 'published' && isset($matches['publish'])) {
      return $matches['publish'];
    }
    return reset($matches) ?: NULL;
  }

  /**
   * Saves one workflow transition on the latest affected revision.
   */
  public function apply(int $nid, string $langcode, string $transitionId, AccountInterface $account): void {
    $revision = $this->contentOps->loadAffectedRevision($nid, $langcode);
    if (!$revision instanceof NodeInterface) {
      throw new AccessException('Missing moderated revision.');
    }
    $transition = NULL;
    foreach ($this->transitionsForRevision($revision, $account) as $candidate) {
      if ($candidate->id() === $transitionId) {
        $transition = $candidate;
        break;
      }
    }
    if (!$transition instanceof TransitionInterface) {
      throw new AccessException('Transition is not allowed.');
    }

    $revision->setNewRevision(TRUE);
    $revision->setRevisionLogMessage((string) $this->t('内容运营中心：@action', [
      '@action' => (string) $transition->label(),
    ]));
    $revision->setRevisionUserId((int) $account->id());
    $revision->setRevisionCreationTime($this->time->getRequestTime());
    $revision->set('moderation_state', $transition->to()->id());
    $revision->save();
  }

  /**
   * @return list<\Drupal\workflows\TransitionInterface>
   *   Transitions permitted for this revision.
   */
  private function transitionsForRevision(NodeInterface $revision, AccountInterface $account): array {
    if (!$revision->hasField('moderation_state') || !$this->moderationInfo->isModeratedEntity($revision)) {
      return [];
    }
    if (!$revision->access('update', $account)) {
      return [];
    }
    return array_values($this->validation->getValidTransitions($revision, $account));
  }

}
