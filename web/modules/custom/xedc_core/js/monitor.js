/**
 * @file
 * Loads chart JSON from Drupal and draws it. Netdata is not called here.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  function text(value) {
    return value === null || value === undefined ? '暂无数据' : String(value);
  }

  function chartColor(panel, line, index) {
    const id = panel.getAttribute('data-chart') || '';
    const key = line && line.key ? line.key : '';
    if (id === 'network' || id === 'traffic') {
      if (key === 'tx' || key === 'sent') {
        return '#16a34a';
      }
      if (key === 'rx' || key === 'received') {
        return '#2563eb';
      }
    }
    const series = {
      cpu: ['#2563eb', '#60a5fa', '#94a3b8', '#d97706'],
      memory: ['#7c3aed', '#a78bfa', '#c4b5fd'],
      disk: ['#ea580c', '#fb923c'],
    };
    const palette = series[id] || ['#2563eb', '#7c3aed', '#ea580c', '#16a34a', '#64748b'];
    return palette[index % palette.length];
  }

  function axisColor(panel) {
    const value = getComputedStyle(panel).getPropertyValue('--ops-chart-axis').trim();
    return value || '#e4e4e7';
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
    axis.setAttribute('stroke', axisColor(panel));
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

    lines.forEach(function (line, lineIndex) {
      const color = chartColor(panel, line, lineIndex);
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
      path.setAttribute('stroke', color);
      path.setAttribute('stroke-width', '1.75');
      svg.appendChild(path);
    });
    plot.appendChild(svg);
    const legend = document.createElement('div');
    legend.className = 'xedc-ops-legend';
    lines.forEach(function (line, lineIndex) {
      const item = document.createElement('span');
      const swatch = document.createElement('i');
      swatch.style.background = chartColor(panel, line, lineIndex);
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

  function clear(node) {
    while (node.firstChild) {
      node.removeChild(node.firstChild);
    }
  }

  function element(tag, className, value) {
    const node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (value !== undefined) {
      node.textContent = value;
    }
    return node;
  }

  Drupal.behaviors.xedcMonitor = {
    attach(context) {
      once('xedc-monitor', '[data-xedc-monitor]', context).forEach(function (root) {
        const settings = drupalSettings.xedcMonitor || {};
        const notice = root.querySelector('[data-xedc-monitor-notice]');
        const stale = root.querySelector('[data-xedc-stale]');
        const overview = root.querySelector('[data-xedc-overview]');
        const detail = root.querySelector('[data-xedc-detail]');
        const autoBox = root.querySelector('[data-xedc-auto]');
        const intervalNode = root.querySelector('[data-xedc-interval]');
        if (!settings.dataUrl) {
          if (notice) {
            notice.textContent = '没有历史数据地址。';
          }
          return;
        }
        const state = {
          section: settings.section || 'overview',
          range: settings.range || '1h',
          refresh: settings.refresh || 3,
          auto: true,
          timer: 0,
          lastOk: '',
          trafficSpan: '7',
          trafficNic: '',
          trafficDay: '',
          historyController: null,
          liveController: null,
        };
        const trafficRoot = detail ? detail.querySelector('[data-xedc-traffic]') : null;
        if (trafficRoot) {
          state.trafficSpan = trafficRoot.getAttribute('data-traffic-current') || '7';
          state.trafficNic = trafficRoot.getAttribute('data-traffic-nic-current') || '';
        }
        try {
          state.auto = window.localStorage.getItem('xedcMonitorAuto') !== '0';
        }
        catch (error) {
          state.auto = true;
        }
        if (autoBox) {
          autoBox.checked = state.auto;
        }

        function delay() {
          const base = Math.max(3, state.refresh) * 1000;
          return document.hidden ? Math.max(base * 4, 30000) : base;
        }

        function markRanges() {
          root.querySelectorAll('[data-range]').forEach(function (button) {
            button.setAttribute('aria-pressed', button.getAttribute('data-range') === state.range ? 'true' : 'false');
          });
        }

        function showStale(message) {
          if (!stale) {
            return;
          }
          stale.hidden = !message;
          stale.textContent = message || '';
        }

        function drawMap(scope, charts) {
          if (!scope) {
            return;
          }
          scope.querySelectorAll('[data-chart]').forEach(function (panel) {
            const plot = panel.querySelector('[data-plot]');
            if (plot) {
              plot.classList.remove('is-loading');
            }
            draw(panel, charts ? charts[panel.getAttribute('data-chart')] : null);
          });
        }

        function renderTraffic(section, traffic) {
          if (!traffic) {
            return;
          }
          state.trafficSpan = String(traffic.span || state.trafficSpan || '7');
          state.trafficNic = traffic.iface || state.trafficNic || '';
          const block = element('section', 'xedc-ops-panel');
          block.setAttribute('data-xedc-traffic', '');
          block.setAttribute('data-traffic-current', state.trafficSpan);
          block.setAttribute('data-traffic-nic-current', state.trafficNic);
          block.appendChild(element('h3', '', '网络流量统计'));
          (traffic.notes || []).forEach(function (item) {
            block.appendChild(element('p', 'xedc-ops-note', item));
          });
          const cards = element('div', 'xedc-ops-cards');
          cards.setAttribute('aria-label', '网络流量总览');
          (traffic.cards || []).forEach(function (card, index) {
            const article = element('article', 'xedc-ops-card');
            article.setAttribute('data-traffic-card', String(index));
            article.appendChild(element('h3', '', card.label || ''));
            article.appendChild(element('p', 'xedc-ops-value', card.value || ''));
            article.appendChild(element('p', 'xedc-ops-detail xedc-ops-pill is-' + (card.state || 'muted'), card.detail || ''));
            cards.appendChild(article);
          });
          block.appendChild(cards);
          const spans = element('div', 'xedc-ops-ranges');
          [7, 30, 90].forEach(function (span) {
            const button = element('button', '', '最近 ' + span + ' 天');
            button.type = 'button';
            button.setAttribute('data-traffic-span', String(span));
            button.setAttribute('aria-pressed', String(span) === state.trafficSpan ? 'true' : 'false');
            spans.appendChild(button);
          });
          block.appendChild(spans);
          const chart = traffic.chart || {};
          const panel = element('article', 'xedc-ops-panel');
          panel.setAttribute('data-chart', 'traffic');
          panel.appendChild(element('h3', '', chart.title || '每日入站和出站流量'));
          const plot = element('div', 'xedc-ops-plot');
          plot.setAttribute('data-plot', '');
          panel.appendChild(plot);
          const chartNote = element('p', 'xedc-ops-detail');
          chartNote.setAttribute('data-chart-note', '');
          panel.appendChild(chartNote);
          block.appendChild(panel);
          const meta = element('div', 'xedc-ops-meta');
          [
            ['统计网卡', traffic.iface || '未采集'],
            ['类型', traffic.kind || '未采集'],
            ['网卡状态', traffic.operstate || '未知'],
            ['当前下载速度', traffic.rx_rate || '未采集'],
            ['当前上传速度', traffic.tx_rate || '未采集'],
            ['速率来源', traffic.rate_source || '未采集'],
            ['最近采集', traffic.last_sample || '未采集'],
            ['开始统计', traffic.started || '未采集'],
            ['数据完整性', traffic.integrity_label || '未采集'],
          ].forEach(function (pair) {
            const cell = element('div');
            cell.appendChild(element('span', '', pair[0]));
            cell.appendChild(element('strong', '', pair[1]));
            meta.appendChild(cell);
          });
          block.appendChild(meta);
          if (traffic.last_detail) {
            block.appendChild(element('p', 'xedc-ops-note', traffic.last_detail));
          }
          if (traffic.choices && traffic.choices.length > 1) {
            const nics = element('div', 'xedc-ops-ranges');
            traffic.choices.forEach(function (name) {
              const button = element('button', '', name);
              button.type = 'button';
              button.setAttribute('data-traffic-nic', name);
              button.setAttribute('aria-pressed', name === traffic.iface ? 'true' : 'false');
              nics.appendChild(button);
            });
            block.appendChild(nics);
          }
          const form = element('form', 'xedc-ops-filters');
          form.setAttribute('data-traffic-form', '');
          form.method = 'get';
          const day = element('input');
          day.type = 'date';
          day.name = 'day';
          day.value = traffic.day || '';
          form.appendChild(day);
          const submit = element('button', 'xedc-ops-button', '查看这一天');
          submit.type = 'submit';
          form.appendChild(submit);
          block.appendChild(form);
          const wrap = element('div', 'xedc-ops-table-wrap');
          const table = element('table', 'xedc-ops-table');
          const head = document.createElement('tr');
          ['日期', '入站流量（下载）', '出站流量（上传）', '完整性', '说明'].forEach(function (header) {
            head.appendChild(element('th', '', header));
          });
          const thead = document.createElement('thead');
          thead.appendChild(head);
          table.appendChild(thead);
          const body = document.createElement('tbody');
          (traffic.days || []).forEach(function (row) {
            const tr = document.createElement('tr');
            [row.day, row.rx, row.tx, row.integrity, row.note].forEach(function (cell) {
              tr.appendChild(element('td', '', cell || ''));
            });
            body.appendChild(tr);
          });
          table.appendChild(body);
          wrap.appendChild(table);
          block.appendChild(wrap);
          section.appendChild(block);
          draw(panel, chart.points ? chart : null);
        }

        function renderCatalog(section, browser) {
          if (!browser || !browser.rows) {
            return;
          }
          const form = element('form', 'xedc-ops-filters');
          form.method = 'get';
          const sectionInput = element('input');
          sectionInput.type = 'hidden';
          sectionInput.name = 'section';
          sectionInput.value = 'browser';
          form.appendChild(sectionInput);
          const query = element('input');
          query.type = 'search';
          query.name = 'q';
          query.value = browser.query || '';
          query.placeholder = '图表、维度或采集器';
          form.appendChild(query);
          const category = element('select');
          category.name = 'category';
          const all = element('option', '', '全部分类');
          all.value = '';
          category.appendChild(all);
          Object.keys(browser.categories || {}).forEach(function (name) {
            const option = element('option', '', name + '（' + browser.categories[name] + '）');
            option.value = name;
            if (browser.category === name) {
              option.selected = true;
            }
            category.appendChild(option);
          });
          form.appendChild(category);
          const submit = element('button', 'xedc-ops-button', '筛选');
          submit.type = 'submit';
          form.appendChild(submit);
          section.appendChild(form);
          section.appendChild(element('p', 'xedc-ops-note', browser.total + ' 个图表，第 ' + (Number(browser.page) + 1) + ' / ' + browser.pages + ' 页。当前页数值来自最近一次采样。'));
          const wrap = element('div', 'xedc-ops-table-wrap');
          const table = element('table', 'xedc-ops-table');
          const head = document.createElement('tr');
          ['图表', '分类', '单位', '维度', '当前值', '状态', ''].forEach(function (header) {
            head.appendChild(element('th', '', header));
          });
          const thead = document.createElement('thead');
          thead.appendChild(head);
          table.appendChild(thead);
          const body = document.createElement('tbody');
          browser.rows.forEach(function (row) {
            const tr = document.createElement('tr');
            const name = document.createElement('td');
            name.appendChild(document.createTextNode(row.title || ''));
            name.appendChild(document.createElement('br'));
            const code = element('code', '', row.id || '');
            name.appendChild(code);
            tr.appendChild(name);
            tr.appendChild(element('td', '', row.category || ''));
            tr.appendChild(element('td', '', row.units || ''));
            tr.appendChild(element('td', '', row.dimensions || ''));
            tr.appendChild(element('td', '', row.value || ''));
            tr.appendChild(element('td', '', [row.status, row.updated].filter(Boolean).join(' · ')));
            const action = document.createElement('td');
            const button = element('button', '', '趋势');
            button.type = 'button';
            button.setAttribute('data-chart-id', row.id || '');
            action.appendChild(button);
            tr.appendChild(action);
            body.appendChild(tr);
          });
          table.appendChild(body);
          wrap.appendChild(table);
          section.appendChild(wrap);
          const pager = element('p', 'xedc-ops-pager');
          const page = Number(browser.page) || 0;
          const queryText = encodeURIComponent(browser.query || '');
          const categoryText = encodeURIComponent(browser.category || '');
          if (page > 0) {
            const previous = element('a', '', '上一页');
            previous.href = '?section=browser&page=' + (page - 1) + '&q=' + queryText + '&category=' + categoryText;
            pager.appendChild(previous);
          }
          if (page + 1 < Number(browser.pages)) {
            const next = element('a', '', '下一页');
            next.href = '?section=browser&page=' + (page + 1) + '&q=' + queryText + '&category=' + categoryText;
            pager.appendChild(next);
          }
          section.appendChild(pager);
        }

        function renderCollectors(section, collectors) {
          if (!collectors || !collectors.length) {
            return;
          }
          const panel = element('article', 'xedc-ops-panel');
          panel.appendChild(element('h3', '', '已发现采集器'));
          const wrap = element('div', 'xedc-ops-table-wrap');
          const table = element('table', 'xedc-ops-table');
          const head = document.createElement('tr');
          ['采集器', '图表数', '状态'].forEach(function (header) {
            head.appendChild(element('th', '', header));
          });
          const thead = document.createElement('thead');
          thead.appendChild(head);
          table.appendChild(thead);
          const body = document.createElement('tbody');
          collectors.forEach(function (row) {
            const tr = document.createElement('tr');
            tr.appendChild(element('td', '', row.name || ''));
            tr.appendChild(element('td', '', row.charts || ''));
            tr.appendChild(element('td', '', row.state || ''));
            body.appendChild(tr);
          });
          table.appendChild(body);
          wrap.appendChild(table);
          panel.appendChild(wrap);
          section.appendChild(panel);
        }

        function renderDetail(payload) {
          if (!detail) {
            return;
          }
          clear(detail);
          const section = element('section');
          section.appendChild(element('h3', 'xedc-ops-section', payload.title || '监控明细'));
          const note = element('p', 'xedc-ops-note', payload.error || payload.updated || '');
          note.setAttribute('data-xedc-detail-notice', '');
          section.appendChild(note);
          (payload.notes || []).forEach(function (item) {
            section.appendChild(element('p', 'xedc-ops-note', item));
          });
          renderTraffic(section, payload.traffic);
          (payload.groups || []).forEach(function (group) {
            const panel = element('article', 'xedc-ops-panel');
            panel.appendChild(element('h3', '', group.title || ''));
            if (group.metrics && group.metrics.length) {
              const list = element('ul', 'xedc-ops-metrics');
              group.metrics.forEach(function (metric) {
                const row = element('li');
                row.appendChild(element('span', '', metric.label || ''));
                const value = element('strong', '', (metric.value || '') + (metric.unit ? ' ' + metric.unit : ''));
                row.appendChild(value);
                const status = [metric.status_label, metric.trend_label, metric.sampled].filter(Boolean).join(' · ');
                const em = element('em', 'is-' + (metric.status || ''), status);
                row.appendChild(em);
                if (metric.note) {
                  row.appendChild(element('em', '', metric.note));
                }
                list.appendChild(row);
              });
              panel.appendChild(list);
            }
            (group.tables || []).forEach(function (table) {
              panel.appendChild(element('h3', '', table.title || ''));
              const wrap = element('div', 'xedc-ops-table-wrap');
              const htmlTable = element('table', 'xedc-ops-table');
              const head = document.createElement('tr');
              (table.headers || []).forEach(function (header) {
                head.appendChild(element('th', '', header));
              });
              const thead = document.createElement('thead');
              thead.appendChild(head);
              htmlTable.appendChild(thead);
              const body = document.createElement('tbody');
              (table.rows || []).forEach(function (row) {
                const tr = document.createElement('tr');
                row.forEach(function (cell) {
                  tr.appendChild(element('td', '', cell));
                });
                body.appendChild(tr);
              });
              htmlTable.appendChild(body);
              wrap.appendChild(htmlTable);
              panel.appendChild(wrap);
            });
            section.appendChild(panel);
          });
          renderCatalog(section, payload.browser);
          renderCollectors(section, payload.collectors);
          if (payload.section === 'alarms' || (payload.alarms && payload.alarms.length)) {
            const panel = element('article', 'xedc-ops-panel');
            panel.appendChild(element('h3', '', 'Netdata 告警'));
            if (!payload.alarms || !payload.alarms.length) {
              panel.appendChild(element('p', 'xedc-ops-note', '当前没有 Netdata 原生告警。没有活动告警时不会显示为正常或警告。'));
            }
            else {
              payload.alarms.forEach(function (alarm) {
                panel.appendChild(element('p', '', [alarm.level, alarm.name, alarm.state, alarm.when, alarm.chart, alarm.info].filter(Boolean).join(' · ')));
              });
            }
            section.appendChild(panel);
          }
          const plots = element('div', 'xedc-ops-chart-grid');
          plots.setAttribute('data-xedc-detail-charts', '');
          Object.keys(payload.charts || {}).forEach(function (key) {
            const panel = element('article', 'xedc-ops-panel');
            panel.setAttribute('data-chart', key);
            panel.appendChild(element('h3', '', payload.charts[key].title || '趋势'));
            const plot = element('div', 'xedc-ops-plot');
            plot.setAttribute('data-plot', '');
            panel.appendChild(plot);
            panel.appendChild(element('p', 'xedc-ops-detail'));
            panel.lastChild.setAttribute('data-chart-note', '');
            plots.appendChild(panel);
          });
          section.appendChild(plots);
          detail.appendChild(section);
          drawMap(detail, payload.charts || {});
        }

        function patchDetail(payload) {
          if (!detail) {
            return;
          }
          const metrics = [];
          (payload.groups || []).forEach(function (group) {
            (group.metrics || []).forEach(function (metric) {
              metrics.push(metric);
            });
          });
          const nodes = detail.querySelectorAll('.xedc-ops-metrics strong');
          if (!nodes.length || nodes.length !== metrics.length) {
            return;
          }
          nodes.forEach(function (node, index) {
            const metric = metrics[index];
            node.textContent = (metric.value || '') + (metric.unit ? ' ' + metric.unit : '');
            const status = node.parentNode ? node.parentNode.querySelector('em') : null;
            if (status) {
              status.textContent = [metric.status_label, metric.trend_label, metric.sampled].filter(Boolean).join(' · ');
            }
          });
          const note = detail.querySelector('[data-xedc-detail-notice]');
          if (note) {
            note.textContent = payload.error || payload.updated || '';
          }
          if (payload.traffic && payload.traffic.cards) {
            detail.querySelectorAll('[data-traffic-card]').forEach(function (card, index) {
              const item = payload.traffic.cards[index];
              if (!item) {
                return;
              }
              const value = card.querySelector('.xedc-ops-value');
              const line = card.querySelector('.xedc-ops-detail');
              if (value) {
                value.textContent = item.value || '';
              }
              if (line) {
                line.textContent = item.detail || '';
                line.className = 'xedc-ops-detail xedc-ops-pill is-' + (item.state || 'muted');
              }
            });
          }
        }

        function applyOverview(payload) {
          root.querySelectorAll('[data-k="updated"]').forEach(function (node) {
            node.textContent = payload.updated || '';
          });
          root.querySelectorAll('[data-k="connection"]').forEach(function (node) {
            node.textContent = payload.connection || '';
            const live = node.closest('.xedc-ops-live');
            if (live) {
              live.classList.toggle('is-ok', payload.ok !== false);
              live.classList.toggle('is-bad', payload.ok === false);
            }
          });
          root.querySelectorAll('[data-k="uptime"]').forEach(function (node) {
            node.textContent = payload.uptime || '';
          });
          if (payload.refresh_seconds) {
            root.querySelectorAll('[data-k="refresh"]').forEach(function (node) {
              node.textContent = payload.refresh_seconds + ' 秒';
            });
          }
          root.querySelectorAll('[data-card]').forEach(function (card) {
            const item = (payload.cards || [])[Number(card.getAttribute('data-card'))];
            if (!item) {
              return;
            }
            const value = card.querySelector('[data-card-value]');
            const line = card.querySelector('[data-card-detail]');
            if (value) {
              value.textContent = item.value || '';
            }
            if (line) {
              line.textContent = item.detail || '';
            }
          });
        }

        function endpoint(params) {
          const url = new URL(settings.dataUrl, window.location.origin);
          Object.keys(params).forEach(function (key) {
            if (params[key] !== '' && params[key] !== null && params[key] !== undefined) {
              url.searchParams.set(key, params[key]);
            }
          });
          return url.toString();
        }

        function loadHistory(range) {
          state.range = range || state.range;
          markRanges();
          if (state.historyController) {
            state.historyController.abort();
          }
          state.historyController = new AbortController();
          const params = { range: state.range };
          if (state.section === 'network') {
            params.span = state.trafficSpan || '7';
            params.nic = state.trafficNic || '';
            params.day = state.trafficDay || '';
          }
          const scope = state.section === 'overview' ? overview : detail;
          if (state.section === 'overview') {
            if (notice) {
              notice.textContent = '正在读取历史采样。';
            }
          }
          else {
            params.section = state.section;
            params.series = '1';
          }
          if (scope) {
            scope.querySelectorAll('[data-plot]').forEach(function (plot) {
              plot.classList.add('is-loading');
            });
          }
          fetch(endpoint(params), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: state.historyController.signal,
          })
            .then(function (response) {
              if (response.status === 403) {
                throw new Error('没有监控权限');
              }
              if (!response.ok) {
                throw new Error('HTTP ' + response.status);
              }
              return response.json();
            })
            .then(function (payload) {
              showStale('');
              state.lastOk = payload.updated || state.lastOk;
              if (state.section === 'overview') {
                if (notice) {
                  notice.textContent = payload.notice || (payload.ok ? '' : text(payload.error));
                }
                drawMap(overview, payload.charts || {});
              }
              else {
                renderDetail(payload);
              }
            })
            .catch(function (error) {
              if (error.name === 'AbortError') {
                return;
              }
              showStale('历史数据读取失败，仍显示上一次成功的图。' + (state.lastOk ? ' 上次成功：' + state.lastOk : ''));
              if (scope) {
                scope.querySelectorAll('[data-plot]').forEach(function (plot) {
                  plot.classList.remove('is-loading');
                });
              }
            });
        }

        function loadLive() {
          if (state.liveController) {
            state.liveController.abort();
          }
          state.liveController = new AbortController();
          const liveParams = { section: state.section, range: state.range };
          if (state.section === 'network') {
            liveParams.span = state.trafficSpan || '7';
            liveParams.nic = state.trafficNic || '';
            liveParams.day = state.trafficDay || '';
          }
          fetch(endpoint(liveParams), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: state.liveController.signal,
          })
            .then(function (response) {
              if (response.status === 403) {
                throw new Error('没有监控权限');
              }
              if (!response.ok) {
                throw new Error('HTTP ' + response.status);
              }
              return response.json();
            })
            .then(function (payload) {
              if (payload.ok === false && !payload.cards && !payload.groups) {
                throw new Error(payload.error || '监控数据不可用');
              }
              showStale('');
              state.lastOk = payload.updated || state.lastOk;
              if (payload.refresh_seconds) {
                state.refresh = payload.refresh_seconds;
                if (intervalNode) {
                  intervalNode.textContent = (document.hidden ? '后台 ' : '') + state.refresh + ' 秒';
                }
              }
              if (state.section === 'overview') {
                applyOverview(payload);
              }
              else if (payload.groups) {
                patchDetail(payload);
              }
            })
            .catch(function (error) {
              if (error.name === 'AbortError') {
                return;
              }
              showStale('数据已过期。' + (state.lastOk ? '上次成功：' + state.lastOk + '。' : '') + error.message);
            });
        }

        function schedule() {
          window.clearTimeout(state.timer);
          if (!state.auto) {
            if (intervalNode) {
              intervalNode.textContent = '已暂停';
            }
            return;
          }
          if (intervalNode) {
            intervalNode.textContent = (document.hidden ? '后台 ' : '') + state.refresh + ' 秒';
          }
          state.timer = window.setTimeout(function () {
            loadLive();
            schedule();
          }, delay());
        }

        function showSection(section) {
          state.section = section;
          root.querySelectorAll('[data-section]').forEach(function (link) {
            if (link.getAttribute('data-section') === section) {
              link.setAttribute('aria-current', 'page');
            }
            else {
              link.removeAttribute('aria-current');
            }
          });
          if (overview) {
            overview.classList.toggle('is-hidden', section !== 'overview');
          }
          if (detail) {
            detail.classList.toggle('is-hidden', section === 'overview');
          }
          const selected = (settings.refresh && section === settings.section) ? settings.refresh : state.refresh;
          state.refresh = selected;
          loadHistory(state.range);
          schedule();
        }

        root.querySelectorAll('[data-range]').forEach(function (button) {
          button.addEventListener('click', function () {
            loadHistory(button.getAttribute('data-range'));
          });
        });
        root.querySelectorAll('[data-section]').forEach(function (link) {
          link.addEventListener('click', function (event) {
            event.preventDefault();
            showSection(link.getAttribute('data-section'));
          });
        });
        root.addEventListener('click', function (event) {
          const spanButton = event.target.closest('[data-traffic-span]');
          if (spanButton) {
            state.trafficSpan = spanButton.getAttribute('data-traffic-span') || '7';
            state.trafficDay = '';
            loadHistory(state.range);
            return;
          }
          const nicButton = event.target.closest('[data-traffic-nic]');
          if (nicButton) {
            state.trafficNic = nicButton.getAttribute('data-traffic-nic') || '';
            loadHistory(state.range);
            return;
          }
          const button = event.target.closest('[data-chart-id]');
          if (!button) {
            return;
          }
          const chart = button.getAttribute('data-chart-id');
          fetch(endpoint({ chart: chart, range: state.range }), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
          })
            .then(function (response) {
              if (!response.ok) {
                throw new Error('HTTP ' + response.status);
              }
              return response.json();
            })
            .then(function (payload) {
              if (!payload.charts) {
                showStale(payload.error || '这个图表没有历史。');
                return;
              }
              const host = detail.querySelector('[data-xedc-detail-charts]') || detail;
              clear(host);
              Object.keys(payload.charts).forEach(function (key) {
                const panel = element('article', 'xedc-ops-panel');
                panel.setAttribute('data-chart', key);
                panel.appendChild(element('h3', '', payload.charts[key].title || chart));
                const plot = element('div', 'xedc-ops-plot');
                plot.setAttribute('data-plot', '');
                panel.appendChild(plot);
                panel.appendChild(element('p', 'xedc-ops-detail'));
                panel.lastChild.setAttribute('data-chart-note', '');
                host.appendChild(panel);
              });
              drawMap(host, payload.charts);
            })
            .catch(function (error) {
              showStale('图表读取失败：' + error.message);
            });
        });
        if (autoBox) {
          autoBox.addEventListener('change', function () {
            state.auto = autoBox.checked;
            try {
              window.localStorage.setItem('xedcMonitorAuto', state.auto ? '1' : '0');
            }
            catch (error) {
              return;
            }
            schedule();
          });
        }
        document.addEventListener('visibilitychange', function () {
          schedule();
        });
        if (settings.detailCharts && state.section !== 'overview') {
          drawMap(detail, settings.detailCharts);
        }
        if (settings.trafficChart && detail) {
          const trafficChart = detail.querySelector('[data-xedc-traffic] [data-chart="traffic"]');
          if (trafficChart) {
            draw(trafficChart, settings.trafficChart);
          }
        }
        root.addEventListener('submit', function (event) {
          const form = event.target && event.target.closest ? event.target.closest('[data-traffic-form]') : null;
          if (!form) {
            return;
          }
          event.preventDefault();
          const input = form.querySelector('input[name="day"]');
          state.trafficDay = input ? input.value : '';
          loadHistory(state.range);
        });
        if (state.section === 'overview') {
          loadHistory(state.range);
        }
        schedule();
      });
    },
  };
})(Drupal, drupalSettings, once);
