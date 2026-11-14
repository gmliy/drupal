<?php

declare(strict_types=1);

namespace Drupal\xedc_comic\Service;

use Drupal\Core\File\FileSystemInterface;
use Symfony\Component\Process\Process;

/**
 * PDF validation and page rendering via Poppler (pdftoppm / pdfinfo).
 */
final class ComicPdfConverter {

  public const ALLOWED_DPI = [96, 150, 200];

  public const ALLOWED_FORMAT = ['jpg', 'webp'];

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * @return array<string, string|null>
   */
  public function environmentReport(): array {
    return [
      'pdftoppm' => $this->binaryVersion('pdftoppm', ['-v']),
      'pdftocairo' => $this->binaryVersion('pdftocairo', ['-v']),
      'pdfinfo' => $this->binaryVersion('pdfinfo', ['-v']),
      'gs' => $this->binaryVersion('gs', ['--version']),
      'php_imagick' => extension_loaded('imagick') ? 'loaded' : NULL,
    ];
  }

  public function isReady(): bool {
    return $this->binaryPath('pdftoppm') !== NULL && $this->binaryPath('pdfinfo') !== NULL;
  }

  /**
   * @throws \InvalidArgumentException
   */
  public function assertReady(): void {
    if ($this->isReady()) {
      return;
    }
    throw new \InvalidArgumentException('服务器未安装 PDF 转换依赖（需要 poppler-utils：pdftoppm 与 pdfinfo）。请先安装后再使用 PDF 导入。');
  }

  /**
   * MIME + header + size limits.
   *
   * @throws \InvalidArgumentException
   */
  public function validatePdfFile(string $realPath, int $maxBytes): void {
    if (!is_file($realPath)) {
      throw new \InvalidArgumentException('PDF 文件无法读取。');
    }
    $size = (int) filesize($realPath);
    if ($size < 64) {
      throw new \InvalidArgumentException('PDF 文件过小或损坏。');
    }
    if ($size > $maxBytes) {
      throw new \InvalidArgumentException('PDF 超过大小限制。');
    }
    $head = file_get_contents($realPath, FALSE, NULL, 0, 8);
    if ($head === FALSE || !str_starts_with($head, '%PDF-')) {
      throw new \InvalidArgumentException('文件内容不是有效的 PDF。');
    }
    $finfo = new \finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($realPath);
    if (!is_string($mime) || !in_array($mime, ['application/pdf', 'application/x-pdf'], TRUE)) {
      throw new \InvalidArgumentException('PDF MIME 类型校验失败。');
    }
  }

  /**
   * @throws \RuntimeException
   */
  public function assertNotEncrypted(string $realPath): void {
    $pdfinfo = $this->binaryPath('pdfinfo');
    if ($pdfinfo === NULL) {
      throw new \RuntimeException('pdfinfo 不可用。');
    }
    $process = new Process([$pdfinfo, $realPath]);
    $process->setTimeout(30);
    $process->run();
    if (!$process->isSuccessful()) {
      throw new \RuntimeException('无法解析 PDF：' . trim($process->getErrorOutput() ?: $process->getOutput()));
    }
    $out = $process->getOutput();
    if (preg_match('/Encrypted:\s*yes/i', $out)) {
      throw new \RuntimeException('不支持加密或需要密码的 PDF。');
    }
  }

  /**
   * Estimate rendered pixel count for one page at given DPI (pre-render guard).
   *
   * @throws \RuntimeException
   */
  public function estimatePagePixels(string $realPath, int $pageNumber, int $dpi): int {
    $pdfinfo = $this->binaryPath('pdfinfo');
    if ($pdfinfo === NULL) {
      throw new \RuntimeException('pdfinfo 不可用。');
    }
    if (!in_array($dpi, self::ALLOWED_DPI, TRUE)) {
      throw new \InvalidArgumentException('DPI 不在允许范围。');
    }
    $process = new Process([
      $pdfinfo,
      '-f', (string) $pageNumber,
      '-l', (string) $pageNumber,
      $realPath,
    ]);
    $process->setTimeout(30);
    $process->run();
    if (!$process->isSuccessful()) {
      throw new \RuntimeException('无法读取 PDF 页面尺寸。');
    }
    $wPt = 612.0;
    $hPt = 792.0;
    if (preg_match('/Page size:\s+([\d.]+)\s+x\s+([\d.]+)/', $process->getOutput(), $m)) {
      $wPt = (float) $m[1];
      $hPt = (float) $m[2];
    }
    $wPx = (int) ceil($wPt / 72.0 * $dpi);
    $hPx = (int) ceil($hPt / 72.0 * $dpi);
    return $wPx * $hPx;
  }

  public function countPages(string $realPath): int {
    $pdfinfo = $this->binaryPath('pdfinfo');
    if ($pdfinfo === NULL) {
      throw new \RuntimeException('pdfinfo 不可用。');
    }
    $process = new Process([$pdfinfo, $realPath]);
    $process->setTimeout(60);
    $process->run();
    if (!$process->isSuccessful()) {
      throw new \RuntimeException('无法识别 PDF 页数。');
    }
    if (preg_match('/^Pages:\s+(\d+)/m', $process->getOutput(), $m)) {
      return max(1, (int) $m[1]);
    }
    throw new \RuntimeException('无法识别 PDF 页数。');
  }

  /**
   * Render one page to a JPEG file (WebP handled later in ImageOptimizer).
   *
   * @return string Absolute path to rendered image
   */
  public function renderPage(string $pdfRealPath, int $pageNumber, string $outputDir, int $dpi, int $maxPixels): string {
    if (!in_array($dpi, self::ALLOWED_DPI, TRUE)) {
      throw new \InvalidArgumentException('DPI 不在允许范围。');
    }
    $pdftoppm = $this->binaryPath('pdftoppm');
    if ($pdftoppm === NULL) {
      throw new \RuntimeException('pdftoppm 不可用。');
    }
    if ($maxPixels > 0) {
      $estimate = $this->estimatePagePixels($pdfRealPath, $pageNumber, $dpi);
      if ($estimate > $maxPixels) {
        throw new \RuntimeException('单页像素超过限制（预估 ' . $estimate . 'px）。');
      }
    }
    if (!is_dir($outputDir) && !mkdir($outputDir, 0775, TRUE) && !is_dir($outputDir)) {
      throw new \RuntimeException('无法创建临时目录。');
    }
    $free = @disk_free_space($outputDir);
    if (is_int($free) && $free < 32 * 1024 * 1024) {
      throw new \RuntimeException('临时磁盘空间不足。');
    }
    $prefix = $outputDir . '/page-' . sprintf('%05d', $pageNumber);
    $process = new Process([
      $pdftoppm,
      '-r',
      (string) $dpi,
      '-f',
      (string) $pageNumber,
      '-l',
      (string) $pageNumber,
      '-jpeg',
      '-singlefile',
      $pdfRealPath,
      $prefix,
    ]);
    $process->setTimeout((int) \Drupal::config('xedc_comic.settings')->get('pdf.page_timeout') ?: 120);
    $process->run();
    if (!$process->isSuccessful()) {
      throw new \RuntimeException(trim($process->getErrorOutput() ?: $process->getOutput()) ?: '页面转换失败。');
    }
    $jpg = $prefix . '.jpg';
    if (!is_file($jpg)) {
      throw new \RuntimeException('转换输出不存在。');
    }
    $info = @getimagesize($jpg);
    if (!is_array($info)) {
      @unlink($jpg);
      throw new \RuntimeException('转换结果不是有效图片。');
    }
    $pixels = (int) $info[0] * (int) $info[1];
    if ($pixels > $maxPixels) {
      @unlink($jpg);
      throw new \RuntimeException('单页像素超过限制。');
    }
    return $jpg;
  }

  public function sanitizeDpi(int $dpi): int {
    return in_array($dpi, self::ALLOWED_DPI, TRUE) ? $dpi : 150;
  }

  public function sanitizeFormat(string $format): string {
    $format = strtolower(trim($format));
    return in_array($format, self::ALLOWED_FORMAT, TRUE) ? $format : 'jpg';
  }

  public function sanitizeQuality(int $quality): int {
    return max(50, min(95, $quality));
  }

  private function binaryPath(string $name): ?string {
    $paths = [
      '/usr/bin/' . $name,
      '/usr/local/bin/' . $name,
    ];
    foreach ($paths as $path) {
      if (is_executable($path)) {
        return $path;
      }
    }
    $which = new Process(['which', $name]);
    $which->run();
    if ($which->isSuccessful()) {
      $found = trim($which->getOutput());
      if ($found !== '' && is_executable($found)) {
        return $found;
      }
    }
    return NULL;
  }

  private function binaryVersion(string $name, array $versionArgs): ?string {
    $path = $this->binaryPath($name);
    if ($path === NULL) {
      return NULL;
    }
    $process = new Process(array_merge([$path], $versionArgs));
    $process->run();
    $line = trim($process->getErrorOutput() . "\n" . $process->getOutput());
    return $line !== '' ? strtok($line, "\n") : $path;
  }

}