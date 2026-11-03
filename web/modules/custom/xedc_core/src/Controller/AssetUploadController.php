<?php

declare(strict_types=1);

namespace Drupal\xedc_core\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\xedc_core\ArchiveManager;
use Drupal\xedc_core\ImageBatchIntake;
use Drupal\xedc_core\ImportException;
use Drupal\xedc_core\ImportKick;
use Drupal\xedc_core\ImportProcessor;
use Drupal\xedc_core\ImportStatus;
use Drupal\xedc_core\TaskRepository;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Receives one archive or image without posting the whole node form.
 */
final class AssetUploadController extends ControllerBase {

  public function __construct(
    protected ArchiveManager $archive,
    protected ImportProcessor $processor,
    protected ImageBatchIntake $images,
    protected TaskRepository $tasks,
    protected ImportKick $kick,
    protected FileSystemInterface $fileSystem,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('xedc_core.archive'),
      $container->get('xedc_core.processor'),
      $container->get('xedc_core.image_batch'),
      $container->get('xedc_core.tasks'),
      $container->get('xedc_core.import_kick'),
      $container->get('file_system'),
    );
  }

  /**
   * Saves a ZIP or 7z and creates an import task.
   */
  public function archive(Request $request): JsonResponse {
    try {
      return $this->saveArchive($request);
    }
    catch (\Throwable $e) {
      $this->getLogger('xedc_core')->error('压缩包上传异常：@message', ['@message' => $e->getMessage()]);
      return $this->json($this->phase(FALSE, FALSE, FALSE, '压缩包上传失败，文件没有保存。'), 500);
    }
  }

  private function saveArchive(Request $request): JsonResponse {
    $broken = $this->brokenPost($request);
    if ($broken) {
      return $broken;
    }
    $token = $this->token($request);
    if ($token === '') {
      return $this->json($this->phase(FALSE, FALSE, FALSE, '缺少上传标识。'), 400);
    }
    $existing = $this->tasks->findByUploadToken($token);
    if ($existing) {
      return $this->json($this->fromTask($existing, '这次上传已经创建过任务，没有重复创建。'));
    }
    $uploaded = $request->files->get('archive');
    if (!$uploaded instanceof UploadedFile) {
      return $this->json($this->phase(FALSE, FALSE, FALSE, '请求里没有压缩包。'), 400);
    }
    $uploadError = $this->uploadError($uploaded);
    if ($uploadError !== '') {
      return $this->json($this->phase(FALSE, FALSE, FALSE, $uploadError, $uploaded->getError()), 400);
    }
    $nid = (int) $request->request->get('nid', 0);
    if ($nid < 1 && trim((string) $request->request->get('title', '')) === '') {
      return $this->json($this->phase(TRUE, FALSE, FALSE, '请先填写标题。压缩包已在本次请求中收到，但没有保存，也没有创建任务。'), 400);
    }
    if ($nid < 1 && !$this->entityTypeManager()->getAccessControlHandler('node')->createAccess('photo')) {
      return $this->json($this->phase(TRUE, FALSE, FALSE, '没有创建图片内容的权限，压缩包没有保存。'), 403);
    }
    if (!$this->archive->privateReady()) {
      return $this->json($this->phase(TRUE, FALSE, FALSE, '私有文件目录不可用，压缩包没有保存。'), 500);
    }
    $stored = $this->storeArchive($uploaded);
    if (isset($stored['response']) && $stored['response'] instanceof JsonResponse) {
      return $stored['response'];
    }
    $duplicate = $this->tasks->findByArchiveHash((string) $stored['sha']);
    if ($duplicate) {
      $this->deleteFid((int) $stored['fid']);
      return $this->json($this->fromTask($duplicate, '相同压缩包已有任务，没有重复创建。'));
    }
    $node = $this->resolveNode($request, TRUE);
    if ($node instanceof JsonResponse) {
      return $node;
    }
    try {
      $id = $this->processor->start($node, [
        'is_new' => FALSE,
        'nid' => (int) $node->id(),
        'mode' => 'append',
        'fid' => (int) $stored['fid'],
        'server' => '',
        'archive_bytes' => (int) $stored['bytes'],
        'archive_sha256' => (string) $stored['sha'],
        'upload_token' => $token,
      ]);
    }
    catch (\Throwable $e) {
      $failed = $this->tasks->findByUploadToken($token);
      if ($failed) {
        return $this->json($this->fromTask($failed, $e->getMessage()), 422);
      }
      $this->deleteFid((int) $stored['fid']);
      return $this->json($this->phase(TRUE, FALSE, FALSE, '压缩包没有保留，任务也没有创建：' . $e->getMessage()), 500);
    }
    $this->kick->schedule($id);
    $task = $this->tasks->load($id);
    return $this->json($task ? $this->fromTask($task, '压缩包已保存，导入任务已创建。') : $this->phase(TRUE, TRUE, TRUE, '任务已创建。'));
  }

  /**
   * Saves one image onto a draft or existing photo.
   */
  public function image(Request $request): JsonResponse {
    try {
      return $this->saveImage($request);
    }
    catch (\Throwable $e) {
      $this->getLogger('xedc_core')->error('图片上传异常：@message', ['@message' => $e->getMessage()]);
      return $this->json($this->phase(FALSE, FALSE, FALSE, '图片上传失败，文件没有保存。'), 500);
    }
  }

  private function saveImage(Request $request): JsonResponse {
    $broken = $this->brokenPost($request);
    if ($broken) {
      return $broken;
    }
    $uploaded = $request->files->get('image');
    if (!$uploaded instanceof UploadedFile) {
      return $this->json($this->phase(FALSE, FALSE, FALSE, '请求里没有图片。'), 400);
    }
    $uploadError = $this->uploadError($uploaded);
    if ($uploadError !== '') {
      return $this->json($this->phase(FALSE, FALSE, FALSE, $uploadError, $uploaded->getError()), 400);
    }
    $node = $this->resolveNode($request, TRUE);
    if ($node instanceof JsonResponse) {
      return $node;
    }
    $path = $uploaded->getRealPath();
    if (!is_string($path) || $path === '') {
      return $this->json($this->phase(TRUE, FALSE, FALSE, '服务器没有读到图片临时文件。'), 400);
    }
    try {
      $result = $this->images->addFile($node, $path, $uploaded->getClientOriginalName(), $this->token($request));
    }
    catch (ImportException $e) {
      return $this->json($this->phase(TRUE, FALSE, FALSE, $e->getMessage()), 422);
    }
    $status = !empty($result['ok']) ? 200 : 422;
    return $this->json($result, $status);
  }

  /**
   * Creates an unpublished photo draft and returns its id.
   */
  public function draft(Request $request): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload)) {
      $payload = $request->request->all();
    }
    $title = trim((string) ($payload['title'] ?? ''));
    if ($title === '') {
      return $this->json(['ok' => FALSE, 'error' => '请先填写标题。'], 400);
    }
    $node = Node::create([
      'type' => 'photo',
      'title' => mb_substr($title, 0, 255),
      'uid' => (int) $this->currentUser()->id(),
      'status' => 0,
    ]);
    if (!$node->access('create')) {
      return $this->json(['ok' => FALSE, 'error' => '没有创建图片内容的权限。'], 403);
    }
    $node->xedc_keep_unpublished = TRUE;
    if ($node->hasField('moderation_state')) {
      $node->set('moderation_state', 'draft');
    }
    $summary = trim((string) ($payload['summary'] ?? ''));
    if ($summary !== '' && $node->hasField('field_summary')) {
      $node->set('field_summary', mb_substr($summary, 0, 1000));
    }
    $node->save();
    return $this->json([
      'ok' => TRUE,
      'nid' => (int) $node->id(),
      'edit' => $node->toUrl('edit-form')->toString(),
      'phase' => 'draft',
      'message' => '草稿已保存。图片上传和优化可以继续，未完成前不会公开。',
    ]);
  }

  /**
   * Finishes an image batch after the browser has sent every file it still has.
   */
  public function close(Request $request): JsonResponse {
    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload)) {
      $payload = $request->request->all();
    }
    $taskId = (int) ($payload['task_id'] ?? 0);
    $task = $this->tasks->load($taskId);
    if (!$task) {
      return $this->json(['ok' => FALSE, 'error' => '任务不存在。'], 404);
    }
    $node = $this->entityTypeManager()->getStorage('node')->load((int) $task->nid);
    if (!$node instanceof NodeInterface || !$node->access('update')) {
      return $this->json(['ok' => FALSE, 'error' => '无权结束该任务。'], 403);
    }
    try {
      $task = $this->images->close($taskId);
    }
    catch (ImportException $e) {
      return $this->json(['ok' => FALSE, 'error' => $e->getMessage()], 422);
    }
    return $this->json($this->fromTask($task, '已停止接收新图片，后台继续处理已上传的文件。'));
  }

  /**
   * @return array<string, mixed>|JsonResponse
   */
  private function storeArchive(UploadedFile $uploaded): array {
    $name = $this->safeArchiveName($uploaded->getClientOriginalName());
    if (!preg_match('/\.(zip|7z)$/i', $name)) {
      return ['response' => $this->json($this->phase(TRUE, FALSE, FALSE, '只接受 ZIP 和 7z。'), 400)];
    }
    $path = $uploaded->getRealPath();
    if (!is_string($path) || !is_file($path) || is_link($path)) {
      return ['response' => $this->json($this->phase(TRUE, FALSE, FALSE, '服务器已收到上传，但临时文件不可读。'), 400)];
    }
    $bytes = filesize($path);
    $limit = (int) $this->config('xedc_core.settings')->get('max_archive_bytes') ?: 209715200;
    if ($bytes === FALSE || $bytes < 1) {
      return ['response' => $this->json($this->phase(TRUE, FALSE, FALSE, '压缩包是空的。'), 400)];
    }
    if ($bytes > $limit) {
      return ['response' => $this->json($this->phase(TRUE, FALSE, FALSE, '压缩包超过大小限制。'), 400)];
    }
    $directory = 'private://xedc-import/inbox';
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    try {
      $uri = $this->fileSystem->copy($path, $directory . '/' . $name, FileExists::Rename);
    }
    catch (\Throwable $e) {
      return ['response' => $this->json($this->phase(TRUE, FALSE, FALSE, '压缩包无法写入私有目录。'), 500)];
    }
    $real = $this->fileSystem->realpath($uri);
    $storedBytes = is_string($real) ? filesize($real) : FALSE;
    if (!is_string($real) || !is_file($real) || is_link($real) || $storedBytes === FALSE || (int) $storedBytes !== $bytes) {
      if (is_string($uri)) {
        $this->fileSystem->delete($uri);
      }
      return ['response' => $this->json($this->phase(TRUE, FALSE, FALSE, '文件已写入，但大小校验失败，已删除这次的临时文件。'), 500)];
    }
    try {
      $format = $this->archive->detect($real);
    }
    catch (ImportException $e) {
      $this->fileSystem->delete($uri);
      return ['response' => $this->json($this->phase(TRUE, FALSE, FALSE, $e->getMessage()), 400)];
    }
    if ($format === '7z' && !$this->archive->sevenZipBinary()) {
      $this->fileSystem->delete($uri);
      return ['response' => $this->json($this->phase(TRUE, FALSE, FALSE, '服务器未安装 7z，7z 文件没有保存。'), 400)];
    }
    $sha = hash_file('sha256', $real);
    if (!is_string($sha)) {
      $this->fileSystem->delete($uri);
      return ['response' => $this->json($this->phase(TRUE, FALSE, FALSE, '无法计算压缩包校验值。'), 500)];
    }
    $file = File::create([
      'uri' => $uri,
      'uid' => (int) $this->currentUser()->id(),
      'status' => FileInterface::STATUS_PERMANENT,
      'filename' => $this->fileSystem->basename($uri),
    ]);
    $file->setMimeType($format === '7z' ? 'application/x-7z-compressed' : 'application/zip');
    $file->save();
    return [
      'fid' => (int) $file->id(),
      'uri' => $file->getFileUri(),
      'bytes' => (int) $storedBytes,
      'format' => $format,
      'sha' => $sha,
    ];
  }

  private function resolveNode(Request $request, bool $create): NodeInterface|JsonResponse {
    $nid = (int) $request->request->get('nid', 0);
    if ($nid < 1) {
      $payload = json_decode($request->getContent(), TRUE);
      if (is_array($payload)) {
        $nid = (int) ($payload['nid'] ?? 0);
      }
    }
    if ($nid > 0) {
      $node = $this->entityTypeManager()->getStorage('node')->load($nid);
      if (!$node instanceof NodeInterface || $node->bundle() !== 'photo' || !$node->access('update')) {
        return $this->json($this->phase(TRUE, FALSE, FALSE, '找不到可编辑的图片内容。'), 403);
      }
      return $node;
    }
    if (!$create) {
      return $this->json($this->phase(TRUE, FALSE, FALSE, '请先保存图片内容。'), 400);
    }
    $title = trim((string) $request->request->get('title', ''));
    if ($title === '') {
      return $this->json($this->phase(TRUE, TRUE, FALSE, '文件已保存在服务器，但没有标题，还没有创建内容。请填写标题后用同一个上传标识重试。'), 400);
    }
    $node = Node::create([
      'type' => 'photo',
      'title' => mb_substr($title, 0, 255),
      'uid' => (int) $this->currentUser()->id(),
      'status' => 0,
    ]);
    if (!$node->access('create')) {
      return $this->json($this->phase(TRUE, TRUE, FALSE, '没有创建图片内容的权限。'), 403);
    }
    $node->xedc_keep_unpublished = TRUE;
    if ($node->hasField('moderation_state')) {
      $node->set('moderation_state', 'draft');
    }
    $node->save();
    return $node;
  }

  /**
   * @return array<string, mixed>
   */
  private function fromTask(object $task, string $message): array {
    $status = (string) $task->status;
    $extracted = in_array($status, [
      ImportStatus::SCANNING,
      ImportStatus::CONVERTING,
      ImportStatus::IMPORTING,
      ImportStatus::VERIFYING,
      ImportStatus::REVIEW,
      ImportStatus::COMPLETED,
      ImportStatus::PARTIAL,
      ImportStatus::PUBLISHED,
    ], TRUE) || (int) $task->total > 0;
    $done = in_array($status, [ImportStatus::REVIEW, ImportStatus::COMPLETED, ImportStatus::PUBLISHED], TRUE);
    return [
      'ok' => !in_array($status, [ImportStatus::FAILED, ImportStatus::CANCELLED], TRUE),
      'received' => TRUE,
      'stored' => (string) $task->archive_uri !== '' || (string) $task->source === 'images',
      'task_created' => TRUE,
      'task_id' => (int) $task->id,
      'nid' => (int) $task->nid,
      'status' => $status,
      'label' => ImportStatus::label($status),
      'phase' => $done ? 'completed' : ($status === ImportStatus::FAILED ? 'failed' : 'queued'),
      'extracted' => $extracted,
      'optimized' => (int) $task->success_count > 0,
      'linked' => $done,
      'completed' => $done,
      'progress' => (int) $task->progress,
      'total' => (int) $task->total,
      'success' => (int) $task->success_count,
      'failed' => (int) $task->failed_count,
      'bytes' => (int) ($task->archive_bytes ?? 0),
      'filename' => (string) $task->archive_name,
      'format' => (string) $task->archive_format,
      'error' => (string) $task->error,
      'message' => $message,
    ];
  }

  /**
   * @return array<string, mixed>
   */
  private function phase(bool $received, bool $stored, bool $task, string $message, ?int $phpError = NULL): array {
    return [
      'ok' => FALSE,
      'received' => $received,
      'stored' => $stored,
      'task_created' => $task,
      'extracted' => FALSE,
      'optimized' => FALSE,
      'linked' => FALSE,
      'completed' => FALSE,
      'phase' => 'failed',
      'php_upload_error' => $phpError,
      'error' => $message,
      'message' => $message,
    ];
  }

  private function brokenPost(Request $request): ?JsonResponse {
    $length = (int) $request->server->get('CONTENT_LENGTH', 0);
    if ($length > 0 && $request->request->count() === 0 && $request->files->count() === 0) {
      return $this->json($this->phase(FALSE, FALSE, FALSE, '请求体超过 post_max_size（' . ini_get('post_max_size') . '），PHP 没有收到文件。'), 400);
    }
    return NULL;
  }

  private function uploadError(UploadedFile $uploaded): string {
    return match ($uploaded->getError()) {
      \UPLOAD_ERR_OK => '',
      \UPLOAD_ERR_INI_SIZE => '文件超过 PHP upload_max_filesize（' . ini_get('upload_max_filesize') . '），没有进入系统。',
      \UPLOAD_ERR_FORM_SIZE => '文件超过表单大小限制，没有进入系统。',
      \UPLOAD_ERR_PARTIAL => '上传中断，服务器只收到一部分文件。',
      \UPLOAD_ERR_NO_FILE => '没有收到文件。',
      \UPLOAD_ERR_NO_TMP_DIR => '服务器临时目录不可用。',
      \UPLOAD_ERR_CANT_WRITE => '服务器无法写入临时文件。',
      \UPLOAD_ERR_EXTENSION => 'PHP 扩展拒绝了这个文件。',
      default => 'PHP 上传失败，错误码 ' . $uploaded->getError() . '。',
    };
  }

  private function token(Request $request): string {
    $token = strtolower(trim((string) $request->request->get('upload_token', '')));
    return preg_match('/^[a-f0-9-]{16,64}$/', $token) ? $token : '';
  }

  private function safeArchiveName(string $name): string {
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', $name) ?: 'archive.zip';
    return mb_substr($name, 0, 180);
  }

  private function deleteFid(int $fid): void {
    $file = $fid > 0 ? File::load($fid) : NULL;
    if ($file instanceof FileInterface) {
      $file->delete();
    }
  }

  /**
   * @param array<string, mixed> $data
   */
  private function json(array $data, int $status = 200): JsonResponse {
    $response = new JsonResponse($data, $status);
    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }

}
