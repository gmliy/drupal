(function (Drupal, once) {
  Drupal.behaviors.xedcAiSelectAll = {
    attach(context) {
      once('xedc-ai-select-all', '.xedc-ai-select-all', context).forEach((box) => {
        box.addEventListener('change', () => {
          const form = box.closest('form');
          if (!form) {
            return;
          }
          form.querySelectorAll('input[type="checkbox"]').forEach((item) => {
            if (item !== box && item.name && (item.name.indexOf('reviews') === 0 || item.name.indexOf('nodes') === 0)) {
              item.checked = box.checked;
            }
          });
        });
      });
    },
  };
  Drupal.behaviors.xedcAiApply = {
    attach(context) {
      once('xedc-ai-apply-all', 'form.xedc-ai-seo-apply', context).forEach((form) => {
        const button = form.querySelector('button.xedc-ai-apply-all');
        const count = form.querySelector('[data-xedc-selected-count]');
        const eligible = () => form.querySelectorAll('input[type="checkbox"][data-xedc-apply-state="pending"], input[type="checkbox"][data-xedc-apply-state="failed"]');
        const refresh = () => {
          const selected = form.querySelectorAll('input[type="checkbox"][data-xedc-apply-state]:checked').length;
          if (count) {
            count.textContent = '已选择 ' + selected + ' 条建议';
          }
          if (!button) {
            return;
          }
          const boxes = Array.from(eligible());
          const allOn = boxes.length > 0 && boxes.every((item) => item.checked);
          button.textContent = allOn ? (button.getAttribute('data-xedc-clear-label') || '取消全选') : (button.getAttribute('data-xedc-select-label') || '全选待处理建议');
        };
        if (button) {
          button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            const boxes = Array.from(eligible());
            const allOn = boxes.length > 0 && boxes.every((item) => item.checked);
            boxes.forEach((item) => {
              item.checked = !allOn;
            });
            refresh();
          });
        }
        form.addEventListener('change', (event) => {
          const target = event.target;
          if (target && target.matches && target.matches('input[type="checkbox"][data-xedc-apply-state]')) {
            refresh();
          }
        });
        form.querySelectorAll('button.xedc-ai-republish-cancel').forEach((cancel) => {
          cancel.addEventListener('click', (event) => {
            event.preventDefault();
            const details = cancel.closest('details');
            if (details) {
              details.open = false;
            }
          });
        });
        refresh();
      });
      once('xedc-ai-apply-submit', 'form.xedc-ai-seo-apply', context).forEach((form) => {
        form.addEventListener('submit', (event) => {
          if (form.getAttribute('data-xedc-submitting') === '1') {
            event.preventDefault();
            return;
          }
          form.setAttribute('data-xedc-submitting', '1');
        });
      });
    },
  };
  Drupal.behaviors.xedcAiAlt = {
    attach(context) {
      once('xedc-ai-alt', '[data-xedc-alt]', context).forEach((root) => {
        const abnormal = ['placeholder', 'extension', 'numeric', 'short', 'long', 'random', 'abnormal', 'duplicate'];
        root.querySelectorAll('[data-xedc-alt-filter]').forEach((button) => {
          button.addEventListener('click', (event) => {
            event.preventDefault();
            const filter = button.getAttribute('data-xedc-alt-filter') || 'all';
            root.querySelectorAll('[data-xedc-alt-row]').forEach((row) => {
              const status = row.getAttribute('data-xedc-alt-status') || '';
              const show = filter === 'all' || status === filter || (filter === 'placeholder' && abnormal.indexOf(status) !== -1);
              row.hidden = !show;
            });
          });
        });
      });
    },
  };
  const setStartBusy = (busy) => {
    document.querySelectorAll('[data-xedc-seo-start]').forEach((button) => {
      const idle = button.getAttribute('data-xedc-seo-idle-label') || '开始 SEO 分析';
      const working = button.getAttribute('data-xedc-seo-busy-label') || '分析中';
      button.value = busy ? working : idle;
      button.disabled = !!busy;
      if (busy) {
        button.setAttribute('aria-busy', 'true');
      }
      else {
        button.removeAttribute('aria-busy');
      }
    });
  };
  const line = (className, text) => {
    const node = document.createElement('p');
    if (className) {
      node.className = className;
    }
    node.textContent = text;
    return node;
  };
  Drupal.behaviors.xedcSeoPreview = {
    attach(context) {
      once('xedc-seo-preview', '[data-xedc-seo-preview]', context).forEach((box) => {
        const form = box.closest('form');
        const input = form ? form.querySelector('input[name="nid"]') : null;
        const url = box.getAttribute('data-xedc-seo-lookup') || '';
        if (!input || !url) {
          return;
        }
        let timer = 0;
        let token = 0;
        const clear = () => {
          box.hidden = true;
          box.replaceChildren();
        };
        const showNote = (text) => {
          box.hidden = false;
          box.replaceChildren(line('xedc-ai-seo-preview-note', text));
        };
        const showContent = (content, note) => {
          box.hidden = false;
          box.replaceChildren();
          box.appendChild(line('xedc-ai-seo-preview-title', content.title || '无标题'));
          box.appendChild(line('xedc-ai-seo-preview-meta', '内容类型：' + (content.bundle_label || '未知')));
          box.appendChild(line('xedc-ai-seo-preview-meta', '内容状态：' + (content.status_label || '未能读取')));
          if (note) {
            box.appendChild(line('xedc-ai-seo-preview-note', note));
          }
        };
        const run = () => {
          const raw = String(input.value || '').trim();
          const current = ++token;
          if (raw === '') {
            clear();
            return;
          }
          if (!/^[1-9]\d*$/.test(raw)) {
            showNote('请输入有效的内容 ID。');
            return;
          }
          clear();
          const target = url + (url.indexOf('?') === -1 ? '?' : '&') + 'id=' + encodeURIComponent(raw);
          fetch(target, {headers: {Accept: 'application/json'}, credentials: 'same-origin'})
            .then((response) => {
              if (!response.ok) {
                throw new Error('lookup');
              }
              return response.json();
            })
            .then((payload) => {
              if (current !== token) {
                return;
              }
              const content = payload && payload.content;
              if (content && content.title) {
                showContent(content, payload.message || '');
                return;
              }
              showNote((payload && payload.message) ? payload.message : '未找到对应内容');
            })
            .catch(() => {
              if (current !== token) {
                return;
              }
              showNote('暂时无法读取内容信息。');
            });
        };
        input.addEventListener('input', () => {
          window.clearTimeout(timer);
          clear();
          timer = window.setTimeout(run, 280);
        });
      });
    },
  };
  Drupal.behaviors.xedcSeoJob = {
    attach(context) {
      once('xedc-seo-start-label', '[data-xedc-seo-start]', context).forEach((button) => {
        button.addEventListener('click', () => {
          button.value = button.getAttribute('data-xedc-seo-busy-label') || '分析中';
        });
      });
      once('xedc-seo-job', '[data-xedc-seo-job]', context).forEach((box) => {
        const id = box.getAttribute('data-review-id');
        if (!id) {
          setStartBusy(false);
          return;
        }
        if (box.getAttribute('data-xedc-seo-polling') === '1') {
          return;
        }
        box.setAttribute('data-xedc-seo-polling', '1');
        const base = box.getAttribute('data-xedc-seo-status-base') || '';
        const steps = [2000, 3000, 5000, 8000, 12000, 15000];
        let step = 0;
        let timer = 0;
        let stopped = false;
        setStartBusy(true);
        const resultNode = () => {
          let result = box.querySelector('[data-xedc-seo-result]');
          if (!result) {
            result = document.createElement('div');
            result.setAttribute('data-xedc-seo-result', '1');
            box.appendChild(result);
          }
          return result;
        };
        const stop = () => {
          stopped = true;
          box.removeAttribute('data-xedc-seo-polling');
          window.clearTimeout(timer);
          setStartBusy(false);
        };
        const paint = (payload) => {
          if (!payload || payload.phase === 'waiting' || payload.phase === 'analyzing' || payload.phase === 'suggesting') {
            return;
          }
          const result = resultNode();
          result.replaceChildren();
          if (payload.error) {
            result.appendChild(line('xedc-ai-error', payload.error));
          }
          if (payload.phase === 'done') {
            result.appendChild(line('', '评分 ' + (payload.score === null ? '—' : payload.score)));
            if (payload.url) {
              const link = document.createElement('a');
              link.href = payload.url;
              link.textContent = '查看分析结果';
              result.appendChild(link);
            }
          }
          else if (payload.phase === 'failed' && !payload.error) {
            result.appendChild(line('xedc-ai-error', '分析失败，可修正后重新提交。'));
          }
          else if (payload.phase === 'cancelled') {
            result.appendChild(line('', '任务已取消。'));
          }
        };
        const tick = () => {
          if (stopped || !document.body.contains(box)) {
            stop();
            return;
          }
          fetch(base + id, {headers: {Accept: 'application/json'}, credentials: 'same-origin'})
            .then((response) => {
              if (!response.ok) {
                throw new Error('status');
              }
              return response.json();
            })
            .then((payload) => {
              paint(payload);
              if (!payload || payload.phase === 'done' || payload.phase === 'failed' || payload.phase === 'cancelled') {
                stop();
                return;
              }
              const wait = steps[Math.min(step, steps.length - 1)];
              step += 1;
              timer = window.setTimeout(tick, wait);
            })
            .catch(() => {
              resultNode().replaceChildren(line('xedc-ai-error', '暂时无法读取任务状态。'));
              stop();
            });
        };
        timer = window.setTimeout(tick, 400);
        box.addEventListener('xedc-seo-stop', stop, {once: true});
      });
    },
  };
})(Drupal, once);
