<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Engagement;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Security\TrustedCallbackInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;

/**
 * User-specific action bar and VIP badge, kept out of the shared page cache.
 */
final class EngagementBuilder implements TrustedCallbackInterface {

  public function __construct(
    protected AccountProxyInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LikeManager $likes,
    protected MembershipManager $membership,
    protected ResourcePresenter $resources,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function trustedCallbacks() {
    return ['bar', 'badge'];
  }

  public function bar(string $nid): array {
    $node = $this->entityTypeManager->getStorage('node')->load((int) $nid);
    if (!$node instanceof NodeInterface || !$node->isPublished() || !in_array($node->bundle(), LikeManager::ALLOWED_BUNDLES, TRUE)) {
      return ['#cache' => ['tags' => ['node:' . (int) $nid]]];
    }
    if (!$node->access('view', $this->currentUser)) {
      return ['#cache' => ['contexts' => ['user'], 'tags' => ['node:' . $node->id()]]];
    }
    $favorite = [];
    if (\Drupal::moduleHandler()->moduleExists('xedc_member') && \Drupal::hasService('xedc_member.lazy_builders')) {
      $favorite = \Drupal::service('xedc_member.lazy_builders')->favorite((string) $node->id());
      if (($favorite['#type'] ?? '') === 'link') {
        $favorite['#title'] = new TranslatableMarkup('收藏');
        $favorite['#attributes']['class'][] = 'xedc-act__btn';
      }
    }
    $uid = (int) $this->currentUser->id();
    $snapshot = $this->membership->snapshot($this->currentUser);
    $canonical = $node->toUrl('canonical', ['absolute' => TRUE])->toString();
    $maxAge = 0;
    if ($snapshot['active'] && $snapshot['expires'] > \Drupal::time()->getRequestTime()) {
      $maxAge = min(300, (int) $snapshot['expires'] - \Drupal::time()->getRequestTime());
    }
    return [
      '#theme' => 'xedc_engagement_bar',
      '#favorite' => $favorite,
      '#like' => [
        'count' => $this->likes->count('node', (int) $node->id()),
        'liked' => $uid > 0 && $this->likes->isLiked($uid, (int) $node->id()),
        'url' => Url::fromRoute('xedc_core.like_toggle', ['node' => $node->id()])->toString(),
        'login' => Url::fromRoute('user.login', [], [
          'query' => ['destination' => $node->toUrl()->toString()],
        ])->toString(),
      ],
      '#download' => $this->resources->forNode($node, $this->currentUser),
      '#share' => [
        'title' => $node->label(),
        'url' => $canonical,
        'qr' => Url::fromRoute('xedc_core.share_qr', ['node' => $node->id()])->toString(),
        'record' => Url::fromRoute('xedc_core.share_record', ['node' => $node->id()])->toString(),
      ],
      '#tier' => $snapshot,
      '#attached' => [
        'library' => ['xedc/engagement'],
      ],
      '#cache' => [
        'contexts' => ['user', 'languages:language_interface', 'url'],
        'tags' => [
          'node:' . $node->id(),
          $this->likes->tag('node', (int) $node->id()),
          'xedc_download_list',
          $this->membership->tag($uid),
        ],
        'max-age' => $maxAge,
      ],
    ];
  }

  public function badge(string $uid): array {
    $uid = (int) $uid;
    if ($uid < 1) {
      return [];
    }
    $account = $this->entityTypeManager->getStorage('user')->load($uid);
    if (!$account) {
      return [];
    }
    $active = $this->membership->isActive($account);
    return [
      '#markup' => $active ? '<span class="xedc-vip-mark">VIP</span>' : '<span class="xedc-member-mark">普通会员</span>',
      '#allowed_tags' => ['span'],
      '#cache' => [
        'tags' => [$this->membership->tag($uid), 'user:' . $uid],
        'max-age' => 0,
      ],
    ];
  }

}
