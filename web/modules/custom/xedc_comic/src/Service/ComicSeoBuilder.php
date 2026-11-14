<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Service;

use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Comic detail meta tags and schema.org ComicSeries JSON-LD.
 */
final class ComicSeoBuilder {

  public function __construct(
    private readonly FileUrlGeneratorInterface $fileUrls,
  ) {}

  /**
   * @return array<string, mixed>
   */
  public function attachments(NodeInterface $comic): array {
    $description = '';
    if ($comic->hasField('field_comic_description') && !$comic->get('field_comic_description')->isEmpty()) {
      $description = strip_tags((string) $comic->get('field_comic_description')->value);
      $description = mb_substr(trim($description), 0, 300);
    }
    $canonical = Url::fromRoute('xedc_comic.detail', ['comic' => $comic->id()], ['absolute' => TRUE])->toString();
    $image = '';
    if ($comic->hasField('field_comic_cover') && !$comic->get('field_comic_cover')->isEmpty()) {
      $file = $comic->get('field_comic_cover')->entity;
      if ($file && method_exists($file, 'getFileUri')) {
        $image = $this->fileUrls->generateAbsoluteString($file->getFileUri());
      }
    }
    $schema = [
      '@context' => 'https://schema.org',
      '@type' => 'ComicSeries',
      'name' => $comic->label(),
      'description' => $description,
      'url' => $canonical,
    ];
    if ($image !== '') {
      $schema['image'] = $image;
    }
    $json = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $build = [
      '#attached' => [
        'html_head' => [
          [['#tag' => 'meta', '#attributes' => ['name' => 'description', 'content' => $description]], 'xedc_comic_description'],
          [['#tag' => 'link', '#attributes' => ['rel' => 'canonical', 'href' => $canonical]], 'xedc_comic_canonical'],
          [['#tag' => 'meta', '#attributes' => ['property' => 'og:title', 'content' => $comic->label()]], 'xedc_comic_og_title'],
          [['#tag' => 'meta', '#attributes' => ['property' => 'og:description', 'content' => $description]], 'xedc_comic_og_description'],
          [['#tag' => 'meta', '#attributes' => ['property' => 'og:url', 'content' => $canonical]], 'xedc_comic_og_url'],
        ],
      ],
    ];
    if ($image !== '') {
      $build['#attached']['html_head'][] = [
        ['#tag' => 'meta', '#attributes' => ['property' => 'og:image', 'content' => $image]],
        'xedc_comic_og_image',
      ];
    }
    if (is_string($json) && $json !== '') {
      $build['#attached']['html_head'][] = [
        [
          '#tag' => 'script',
          '#attributes' => ['type' => 'application/ld+json'],
          '#value' => $json,
        ],
        'xedc_comic_schema',
      ];
    }
    return $build;
  }

  /**
   * @return array<string, mixed>
   */
  public function listAttachments(): array {
    $canonical = Url::fromRoute('xedc_comic.home', [], ['absolute' => TRUE])->toString();
    $description = '浏览 XEDC 漫画中心，按连载、分类与标签发现作品。';
    return [
      '#attached' => [
        'html_head' => [
          [['#tag' => 'meta', '#attributes' => ['name' => 'description', 'content' => $description]], 'xedc_comic_list_description'],
          [['#tag' => 'link', '#attributes' => ['rel' => 'canonical', 'href' => $canonical]], 'xedc_comic_list_canonical'],
        ],
      ],
    ];
  }

  /**
   * @return array<string, mixed>
   */
  public function termAttachments(TermInterface $term, string $kind): array {
    $route = $kind === 'tag' ? 'xedc_comic.tag' : 'xedc_comic.category';
    $canonical = Url::fromRoute($route, ['taxonomy_term' => $term->id()], ['absolute' => TRUE])->toString();
    $description = $term->hasField('description') ? strip_tags((string) $term->get('description')->value) : '';
    $description = mb_substr(trim($description ?: $term->label() . ' 分类漫画'), 0, 300);
    return [
      '#attached' => [
        'html_head' => [
          [['#tag' => 'meta', '#attributes' => ['name' => 'description', 'content' => $description]], 'xedc_comic_term_description'],
          [['#tag' => 'link', '#attributes' => ['rel' => 'canonical', 'href' => $canonical]], 'xedc_comic_term_canonical'],
          [['#tag' => 'meta', '#attributes' => ['property' => 'og:title', 'content' => $term->label()]], 'xedc_comic_term_og_title'],
          [['#tag' => 'meta', '#attributes' => ['property' => 'og:url', 'content' => $canonical]], 'xedc_comic_term_og_url'],
        ],
      ],
    ];
  }

}