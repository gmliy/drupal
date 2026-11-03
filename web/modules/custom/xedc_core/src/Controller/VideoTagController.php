<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\PagerSelectExtender;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\xedc_core\Video\VideoCards;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Video-only tag pages. Image tag pages stay on their own controller.
 */
final class VideoTagController extends ControllerBase {

  public function __construct(
    private readonly Connection $database,
    private readonly EntityRepositoryInterface $repository,
    private readonly RequestStack $requests,
    private readonly VideoCards $cards,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('database'),
      $container->get('entity.repository'),
      $container->get('request_stack'),
      $container->get('xedc_core.video_cards'),
    );
  }

  public function title(TermInterface $taxonomy_term): string {
    if ($taxonomy_term->bundle() !== 'tags') {
      return '';
    }
    $term = $this->repository->getTranslationFromContext($taxonomy_term);
    return (string) $this->t('@name 视频', ['@name' => $term->label()]);
  }

  public function page(TermInterface $taxonomy_term): array {
    if ($taxonomy_term->bundle() !== 'tags') {
      throw new NotFoundHttpException();
    }
    $term = $this->repository->getTranslationFromContext($taxonomy_term);
    $tid = (int) $term->id();
    $sort = $this->requests->getCurrentRequest()?->query->get('sort') === 'hot' ? 'hot' : 'new';
    $total = (int) $this->entityTypeManager()->getStorage('node')->getQuery()
      ->accessCheck(TRUE)
      ->condition('type', 'video')
      ->condition('status', 1)
      ->condition('field_tags', $tid)
      ->count()
      ->execute();

    $query = $this->database->select('node__field_tags', 't');
    $query->join('node_field_data', 'n', 'n.nid = t.entity_id AND n.default_langcode = 1 AND n.status = 1 AND n.type = :vtype', [':vtype' => 'video']);
    $query->condition('t.field_tags_target_id', $tid);
    $query->condition('t.deleted', 0);
    $query->fields('n', ['nid', 'created']);
    $query->distinct();
    if ($sort === 'hot' && $this->database->schema()->tableExists('node_counter')) {
      $query->leftJoin('node_counter', 'c', 'c.nid = n.nid');
      $query->addExpression('COALESCE(c.totalcount, 0)', 'hot');
      $query->orderBy('hot', 'DESC');
    }
    $query->orderBy('n.created', 'DESC');
    $nids = $query->extend(PagerSelectExtender::class)->limit(12)->execute()->fetchCol();
    $built = $this->cards->build(array_map('intval', $nids));
    $description = [];
    if ($term->hasField('description') && !$term->get('description')->isEmpty()) {
      $description = $term->get('description')->view(['label' => 'hidden']);
    }
    $route_options = ['taxonomy_term' => $tid];

    return [
      'page' => [
        '#theme' => 'xedc_video_tag',
        '#term_name' => $term->label(),
        '#description' => $description,
        '#total' => $total,
        '#items' => $built['items'],
        '#sort' => $sort,
        '#sort_urls' => [
          'new' => Url::fromRoute('xedc_core.video_tag', $route_options, ['query' => ['sort' => 'new']])->toString(),
          'hot' => Url::fromRoute('xedc_core.video_tag', $route_options, ['query' => ['sort' => 'hot']])->toString(),
        ],
        '#video_home' => Url::fromUserInput('/video')->toString(),
        '#home_url' => Url::fromRoute('<front>')->toString(),
        '#attached' => [
          'library' => ['xedc/video_list', 'xedc/video-card'],
        ],
        '#cache' => [
          'contexts' => ['url.query_args', 'languages:language_interface', 'languages:language_content', 'user.permissions'],
          'tags' => array_merge(['node_list:video', 'taxonomy_term:' . $tid], $built['cache_tags']),
        ],
      ],
      'pager' => [
        '#type' => 'pager',
        '#quantity' => 5,
      ],
    ];
  }

}
