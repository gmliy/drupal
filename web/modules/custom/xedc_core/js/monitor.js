/**
 * @file
 * Loads chart JSON from Drupal and draws it. Netdata is not called here.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  function text(value) {
    return value === null || value === undefined ? '暂无数据' : String(value);
  }

  function draw(panel, chart) {
    const plot = panel.querySelector('[data-plot]');
    const note = panel.querySelector('[data-chart-note]');
    plot.textContent = '';
    if (!chart || !chart.points || !chart.points.length) {
      note.textContent = '暂无数据';
      return;
    }
    const lines = chart.lines || [];
    let min = Infinity;
    let max = -Infinity;
    chart.points.forEach(function (point) {
      lines.forEach(function (line) {
        const value = point[line.key];
        if (typeof value === 'number' && isFinite(value)) {
          min = Math.min(min, value);
          max = Math.max(max, value);
        }
      });
    });
    if (!isFinite(min) || !isFinite(max)) {
      note.textContent = '暂无数据';
      return;
    }
    if (min === max) {
      min -= 1;
      max += 1;
    }
    const width = 640;
    const height = 180;
    const pad = 16;
    const ns = 'http://www.w3.org/2000/svg';
    const svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', chart.title || '趋势');
    const axis = document.createElementNS(ns, 'line');
    axis.setAttribute('x1', pad);
    axis.setAttribute('x2', width - pad);
    axis.setAttribute('y1', height - pad);
    axis.setAttribute('y2', height - pad);
    axis.setAttribute('stroke', '#e4e4e7');
    svg.appendChild(axis);

    function y(value) {
      return pad + ((max - value) / (max - min)) * (height - pad * 2);
    }
    function x(index) {
      if (chart.points.length === 1) {
        return width / 2;
      }
      return pad + (index / (chart.points.length - 1)) * (width - pad * 2);
    }

    lines.forEach(function (line) {
      let current = '';
      chart.points.forEach(function (point, index) {
        const value = point[line.key];
        if (typeof value !== 'number' || !isFinite(value)) {
          current = '';
          return;
        }
        current += (current ? ' L ' : 'M ') + x(index).toFixed(1) + ' ' + y(value).toFixed(1);
      });
      if (!current) {
        return;
      }
      const path = document.createElementNS(ns, 'path');
      path.setAttribute('d', current);
      path.setAttribute('fill', 'none');
      path.setAttribute('stroke', line.color || '#171717');
      path.setAttribute('stroke-width', '1.75');
      svg.appendChild(path);
    });
    plot.appendChild(svg);
    const legend = document.createElement('div');
    legend.className = 'xedc-ops-legend';
    lines.forEach(function (line) {
      const item = document.createElement('span');
      const swatch = document.createElement('i');
      swatch.style.background = line.color || '#171717';
      item.appendChild(swatch);
      item.appendChild(document.createTextNode(line.label));
      legend.appendChild(item);
    });
    plot.appendChild(legend);
    const last = chart.points[chart.points.length - 1];
    const bits = lines.map(function (line) {
      const value = last[line.key];
      return line.label + ' ' + (typeof value === 'number' ? value : '暂无') + (chart.unit ? ' ' + chart.unit : '');
    });
    note.textContent = '最新一点：' + bits.join(' · ') + '。共 ' + chart.points.length + ' 个采样。';
  }

  Drupal.behaviors.xedcMonitor = {
    attach(context) {
      once('xedc-monitor', '[data-xedc-monitor]', context).forEach(function (root) {
        const settings = drupalSettings.xedcMonitor || {};
        const notice = root.querySelector('[data-xedc-monitor-notice]');
        if (!settings.dataUrl) {
          notice.textContent = '没有历史数据地址。';
          return;
        }
        let active = '1h';
        let controller = null;

        function load(range) {
          active = range;
          root.querySelectorAll('[data-range]').forEach(function (button) {
            button.setAttribute('aria-pressed', button.getAttribute('data-range') === range ? 'true' : 'false');
          });
          if (controller) {
            controller.abort();
          }
          controller = new AbortController();
          notice.textContent = '正在读取历史采样。';
          root.querySelectorAll('[data-plot]').forEach(function (plot) {
            plot.classList.add('is-loading');
          });
          const url = new URL(settings.dataUrl, window.location.origin);
          url.searchParams.set('range', range);
          fetch(url.toString(), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: controller.signal,
          })
            .then(function (response) {
              if (!response.ok) {
                throw new Error('HTTP ' + response.status);
              }
              return response.json();
            })
            .then(function (payload) {
              if (range !== active) {
                return;
              }
              notice.textContent = payload.notice || (payload.ok ? '' : text(payload.error));
              if (!payload.ok && payload.error && !payload.notice) {
                notice.textContent = payload.error;
              }
              root.querySelectorAll('[data-chart]').forEach(function (panel) {
                panel.querySelector('[data-plot]').classList.remove('is-loading');
                draw(panel, payload.charts ? payload.charts[panel.getAttribute('data-chart')] : null);
              });
            })
            .catch(function (error) {
              if (error.name === 'AbortError' || range !== active) {
                return;
              }
              notice.textContent = '历史数据读取失败：' + error.message;
              root.querySelectorAll('[data-plot]').forEach(function (plot) {
                plot.classList.remove('is-loading');
              });
            });
        }

        root.querySelectorAll('[data-range]').forEach(function (button) {
          button.addEventListener('click', function () {
            load(button.getAttribute('data-range'));
          });
        });
        load('1h');
      });
    },
  };
})(Drupal, drupalSettings, once);
