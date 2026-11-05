<?php

namespace Drupal\xedc_member\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\IntegrityConstraintViolationException;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\node\NodeInterface;

/**
 * Stores and retrieves member favorites.
 */
class FavoriteManager {

  /**
   * Content types that can be favorited.
   */
  public const ALLOWED_BUNDLES = ['photo', 'video', 'article'];

  /**
   * Number of favorites per page.
   */
  public const PER_PAGE = 12;

  /**
   * Pager element id, kept off the default so other blocks cannot steal it.
   */
  public const PAGER_ELEMENT = 10;

  /**
   * Constructs a FavoriteManager.
   */
  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
  ) {}

  /**
   * Checks whether a node can be favorited by members.
   */
  public function isAllowedNode(NodeInterface $node): bool {
    return in_array($node->bundle(), self::ALLOWED_BUNDLES, TRUE) && $node->isPublished();
  }

  /**
   * Checks whether a user has favorited a node.
   */
  public function isFavorited(int $uid, int $nid): bool {
    $query = $this->database->select('xedc_member_favorite', 'f');
    $query->condition('f.uid', $uid);
    $query->condition('f.entity_type', 'node');
    $query->condition('f.entity_id', $nid);
    return (bool) $query->countQuery()->execute()->fetchField();
  }

  /**
   * Adds a favorite. Repeated calls are idempotent.
   */
  public function add(int $uid, NodeInterface $node): void {
    if ($uid <= 0 || !$this->isAllowedNode($node)) {
      throw new \InvalidArgumentException('This favorite is not allowed.');
    }

    $added = FALSE;
    try {
      $this->database->insert('xedc_member_favorite')
        ->fields([
          'uid' => $uid,
          'entity_type' => 'node',
          'entity_id' => (int) $node->id(),
          'bundle' => $node->bundle(),
          'created' => $this->time->getRequestTime(),
        ])
        ->execute();
      $added = TRUE;
    }
    catch (IntegrityConstraintViolationException) {
      // The unique key already exists; keep the original favorite time.
    }
    if ($added && \Drupal::moduleHandler()->moduleExists('xedc_notify')) {
      xedc_notify_on_favorite($uid, $node);
    }
  }

  /**
   * Removes a favorite.
   */
  public function remove(int $uid, int $nid): bool {
    $deleted = $this->database->delete('xedc_member_favorite')
      ->condition('uid', $uid)
      ->condition('entity_type', 'node')
      ->condition('entity_id', $nid)
      ->execute();
    return (bool) $deleted;
  }

  /**
   * Counts visible favorites for a user.
   */
  public function countVisible(int $uid, ?string $bundle = NULL): int {
    $query = $this->baseQuery($uid, $bundle);
    return (int) $query->countQuery()->execute()->fetchField();
  }

  /**
   * Loads a paged list of visible favorites.
   *
   * @return object[]
   *   Rows with entity_id, bundle, created and title.
   */
  public function getFavoriteRows(int $uid, ?string $bundle = NULL): array {
    $query = $this->baseQuery($uid, $bundle);
    $query->orderBy('f.created', 'DESC');
    $query = $query->extend(PagerSelectExtender::class);
    $query->element(self::PAGER_ELEMENT);
    $query->limit(self::PER_PAGE);
    return $query->execute()->fetchAll();
  }

  /**
   * Loads the most recent visible favorites.
   *
   * @return object[]
   *   Rows with entity_id, bundle, created and title.
   */
  public function getRecentRows(int $uid, int $limit = 4): array {
    $query = $this->baseQuery($uid);
    $query->orderBy('f.created', 'DESC');
    $query->range(0, $limit);
    return $query->execute()->fetchAll();
  }

  /**
   * Deletes all favorites for a deleted entity.
   */
  public function deleteForEntity(string $entity_type, int $entity_id): void {
    $this->database->delete('xedc_member_favorite')
      ->condition('entity_type', $entity_type)
      ->condition('entity_id', $entity_id)
      ->execute();
  }

  /**
   * Deletes all favorites for a deleted user.
   */
  public function deleteForUser(int $uid): void {
    $this->database->delete('xedc_member_favorite')
      ->condition('uid', $uid)
      ->execute();
  }

  /**
   * Builds the base query for visible favorites.
   */
  protected function baseQuery(int $uid, ?string $bundle = NULL): SelectInterface {
    $query = $this->database->select('xedc_member_favorite', 'f');
    $query->join('node_field_data', 'n', 'n.nid = f.entity_id AND n.default_langcode = 1');
    $query->fields('f', ['entity_id', 'bundle', 'created']);
    $query->addField('n', 'title');
    $query->condition('f.uid', $uid);
    $query->condition('f.entity_type', 'node');
    $query->condition('f.bundle', self::ALLOWED_BUNDLES, 'IN');
    $query->condition('n.status', 1);
    if ($bundle !== NULL) {
      $query->condition('f.bundle', $bundle);
    }
    return $query;
  }

}
