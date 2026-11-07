/*
 * 过滤 mysqldump 中缓存类表的 INSERT/REPLACE。
 * 只匹配行首表名，不扫描整行内容；遇跨多行 INSERT 时跳过到语句结束。
 * 语义等同：
 *   LC_ALL=C grep -a -v -E
 *   '^INSERT INTO `(prefix)?(cache|cache_[^`]*|cachetags|sessions|watchdog|flood|semaphore)`'
 */
#define _GNU_SOURCE
#include <ctype.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

static int is_ephemeral_base(const char *name, size_t len)
{
  static const char *exact[] = {
    "cache", "cachetags", "sessions", "watchdog", "flood", "semaphore", NULL
  };
  size_t i;
  for (i = 0; exact[i] != NULL; i++) {
    if (strlen(exact[i]) == len && memcmp(name, exact[i], len) == 0) {
      return 1;
    }
  }
  return (len >= 6 && memcmp(name, "cache_", 6) == 0);
}

static int table_is_ephemeral(const char *table, size_t len, const char *prefix, size_t plen)
{
  /* 前缀可选：同时匹配 `cache_page` 与 `main_cache_page` */
  if (plen > 0 && len >= plen && memcmp(table, prefix, plen) == 0) {
    if (is_ephemeral_base(table + plen, len - plen)) {
      return 1;
    }
  }
  return is_ephemeral_base(table, len);
}

static const char *skip_ws(const char *p, const char *end)
{
  while (p < end && (*p == ' ' || *p == '\t')) {
    p++;
  }
  return p;
}

static int match_kw(const char *p, const char *end, const char *kw, size_t klen)
{
  if ((size_t)(end - p) < klen) {
    return 0;
  }
  if (strncasecmp(p, kw, klen) != 0) {
    return 0;
  }
  if (p + klen < end) {
    unsigned char c = (unsigned char)p[klen];
    if (isalnum(c) || c == '_') {
      return 0;
    }
  }
  return 1;
}

/* 解析行首 INSERT/REPLACE ... INTO [`db`.]`table` 或 INTO table */
static int line_is_ephemeral_data(const char *line, size_t n, const char *prefix, size_t plen)
{
  const char *p = line;
  const char *end = line + n;
  const char *table;
  size_t tlen;

  p = skip_ws(p, end);
  if (match_kw(p, end, "INSERT", 6)) {
    p += 6;
  } else if (match_kw(p, end, "REPLACE", 7)) {
    p += 7;
  } else {
    return 0;
  }
  p = skip_ws(p, end);
  while (p < end && !match_kw(p, end, "INTO", 4)) {
    /* IGNORE / LOW_PRIORITY / DELAYED */
    while (p < end && (isalnum((unsigned char)*p) || *p == '_')) {
      p++;
    }
    p = skip_ws(p, end);
    if (p >= end) {
      return 0;
    }
  }
  if (p >= end) {
    return 0;
  }
  p += 4;
  p = skip_ws(p, end);
  if (p >= end) {
    return 0;
  }
  if (*p == '`') {
    p++;
    table = p;
    while (p < end && *p != '`') {
      p++;
    }
    if (p >= end) {
      return 0;
    }
    tlen = (size_t)(p - table);
    p++;
    p = skip_ws(p, end);
    if (p < end && *p == '.') {
      p++;
      p = skip_ws(p, end);
      if (p >= end || *p != '`') {
        return 0;
      }
      p++;
      table = p;
      while (p < end && *p != '`') {
        p++;
      }
      if (p >= end) {
        return 0;
      }
      tlen = (size_t)(p - table);
    }
  } else if (isalpha((unsigned char)*p) || *p == '_') {
    table = p;
    while (p < end && (isalnum((unsigned char)*p) || *p == '_')) {
      p++;
    }
    tlen = (size_t)(p - table);
  } else {
    return 0;
  }
  return table_is_ephemeral(table, tlen, prefix, plen);
}

static int line_ends_statement(const char *line, size_t n)
{
  while (n > 0 && (line[n - 1] == '\n' || line[n - 1] == '\r' ||
                   line[n - 1] == ' ' || line[n - 1] == '\t')) {
    n--;
  }
  return n > 0 && line[n - 1] == ';';
}

static void usage(void)
{
  fprintf(stderr, "用法: sql_skip_cache [--prefix 前缀]\n");
}

int main(int argc, char **argv)
{
  const char *prefix = "";
  size_t plen;
  char *buf = NULL;
  size_t cap = 0;
  ssize_t n;
  int skip = 0;
  int i;

  for (i = 1; i < argc; i++) {
    if (strcmp(argv[i], "--prefix") == 0) {
      if (i + 1 >= argc) {
        usage();
        return 2;
      }
      prefix = argv[++i];
    } else if (strcmp(argv[i], "--help") == 0 || strcmp(argv[i], "-h") == 0) {
      usage();
      return 0;
    } else {
      usage();
      return 2;
    }
  }
  plen = strlen(prefix);
  for (i = 0; prefix[i] != '\0'; i++) {
    char c = prefix[i];
    if (!((c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') ||
          (c >= '0' && c <= '9') || c == '_')) {
      fprintf(stderr, "表前缀含有非法字符\n");
      return 1;
    }
  }

  while ((n = getline(&buf, &cap, stdin)) >= 0) {
    if (skip) {
      if (line_ends_statement(buf, (size_t)n)) {
        skip = 0;
      }
      continue;
    }
    if (line_is_ephemeral_data(buf, (size_t)n, prefix, plen)) {
      if (!line_ends_statement(buf, (size_t)n)) {
        skip = 1;
      }
      continue;
    }
    if (fwrite(buf, 1, (size_t)n, stdout) != (size_t)n) {
      free(buf);
      return 1;
    }
  }
  free(buf);
  if (ferror(stdin) || ferror(stdout)) {
    return 1;
  }
  return 0;
}
