<?php

declare(strict_types=1);

namespace Drupal\xedc_search\Form;

use Drupal\Core\Database\Connection;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\xedc_search\Query\QueryNormalizer;
use Drupal\xedc_search\Repository\KeywordRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Create or edit an admin keyword.
 */
final class KeywordForm extends FormBase {

  public function __construct(
    private readonly KeywordRepository $keywords,
    private readonly QueryNormalizer $normalizer,
    private readonly Connection $database,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_search.keywords'),
      $container->get('xedc_search.normalizer'),
      $container->get('database'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'xedc_search_keyword';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $id = NULL): array {
    $row = NULL;
    if ($id) {
      $row = $this->keywords->load((int) $id);
      if (!$row) {
        $this->messenger()->addError($this->t('搜索词不存在。'));
        return $form;
      }
    }
    $form['#attributes']['class'][] = 'xedc-search-admin';
    $form['#attached']['library'][] = 'xedc_search/admin';
    $form['id'] = ['#type' => 'value', '#value' => $row ? (int) $row['id'] : 0];
    $form['keyword'] = [
      '#type' => 'textfield',
      '#title' => $this->t('关键词'),
      '#required' => TRUE,
      '#maxlength' => 100,
      '#default_value' => $row['keyword'] ?? '',
    ];
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('显示名称'),
      '#description' => $this->t('留空时前台显示关键词本身。'),
      '#maxlength' => 100,
      '#default_value' => $row['label'] ?? '',
    ];
    $form['weight'] = [
      '#type' => 'number',
      '#title' => $this->t('权重'),
      '#min' => -100,
      '#max' => 100,
      '#default_value' => isset($row['weight']) ? (int) $row['weight'] : 0,
    ];
    $form['status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('启用'),
      '#default_value' => $row ? (int) $row['status'] : 1,
    ];
    $form['tags'] = [
      '#type' => 'textfield',
      '#title' => $this->t('关联标签'),
      '#description' => $this->t('填写标签名称，用逗号或换行分隔。名称必须已经存在。'),
      '#default_value' => $row ? $this->names((string) $row['tags']) : '',
    ];
    $form['categories'] = [
      '#type' => 'textfield',
      '#title' => $this->t('关联分类'),
      '#description' => $this->t('图片、视频或文章分类名称。'),
      '#default_value' => $row ? $this->names((string) $row['categories']) : '',
    ];
    $form['synonyms'] = [
      '#type' => 'textarea',
      '#title' => $this->t('同义词'),
      '#description' => $this->t('每行一个，最多 20 个。搜索这些词时会带上关键词对应的内容。'),
      '#rows' => 4,
      '#default_value' => $row['synonyms'] ?? '',
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('保存')];
    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('返回'),
      '#url' => Url::fromRoute('xedc_search.admin.keywords'),
      '#attributes' => ['class' => ['button']],
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $keyword = $this->normalizer->clean((string) $form_state->getValue('keyword'));
    if ($keyword === '') {
      $form_state->setErrorByName('keyword', $this->t('请填写关键词。'));
      return;
    }
    $existing = $this->keywords->findByKey($this->normalizer->key($keyword));
    $id = (int) $form_state->getValue('id');
    if ($existing && (int) $existing['id'] !== $id) {
      $form_state->setErrorByName('keyword', $this->t('已经有相同的关键词。'));
    }
    [$tagIds, $missingTags] = $this->resolve((string) $form_state->getValue('tags'), ['tags']);
    [$categoryIds, $missingCategories] = $this->resolve((string) $form_state->getValue('categories'), ['photo_category', 'video_category', 'article_category']);
    if ($missingTags) {
      $form_state->setErrorByName('tags', $this->t('找不到标签：@names', ['@names' => implode('、', $missingTags)]));
    }
    if ($missingCategories) {
      $form_state->setErrorByName('categories', $this->t('找不到分类：@names', ['@names' => implode('、', $missingCategories)]));
    }
    $form_state->setValue('tag_ids', $tagIds);
    $form_state->setValue('category_ids', $categoryIds);
    $form_state->setValue('keyword', $keyword);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $id = (int) $form_state->getValue('id');
    $saved = $this->keywords->save([
      'keyword' => $form_state->getValue('keyword'),
      'label' => $form_state->getValue('label'),
      'weight' => $form_state->getValue('weight'),
      'status' => $form_state->getValue('status'),
      'tags' => $form_state->getValue('tag_ids'),
      'categories' => $form_state->getValue('category_ids'),
      'synonyms' => $form_state->getValue('synonyms'),
    ], $id ?: NULL);
    $this->messenger()->addStatus($this->t('已保存搜索词。'));
    $form_state->setRedirect('xedc_search.admin.keyword_edit', ['id' => $saved]);
  }

  /**
   * @param list<string> $vids
   * @return array{0: list<int>, 1: list<string>}
   */
  private function resolve(string $text, array $vids): array {
    $parts = preg_split('/[,，\n]+/u', $text) ?: [];
    $ids = [];
    $missing = [];
    foreach ($parts as $part) {
      $name = trim($part);
      if ($name === '') {
        continue;
      }
      $tid = $this->database->select('taxonomy_term_field_data', 't')
        ->fields('t', ['tid'])
        ->condition('vid', $vids, 'IN')
        ->condition('name', $name)
        ->condition('status', 1)
        ->range(0, 1)
        ->execute()
        ->fetchField();
      if ($tid) {
        $ids[] = (int) $tid;
      }
      else {
        $missing[] = $name;
      }
    }
    return [array_values(array_unique($ids)), $missing];
  }

  private function names(string $ids): string {
    $tids = array_values(array_filter(array_map('intval', explode(',', $ids))));
    if (!$tids) {
      return '';
    }
    $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($tids);
    $names = [];
    foreach ($tids as $tid) {
      if (isset($terms[$tid])) {
        $names[] = $terms[$tid]->label();
      }
    }
    return implode('，', $names);
  }

}
