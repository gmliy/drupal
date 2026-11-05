const loginUrl = process.argv[2];
const meta = JSON.parse((await import('node:fs')).readFileSync(process.argv[3], 'utf8'));
const shotDir = process.argv[4];
const fs = await import('node:fs');
const base = 'http://127.0.0.1:8096';
const errors = [];
const posts = [];
const navigations = [];
let ws;
let seq = 0;
const waiters = new Map();
const loadWaiters = [];

function send(method, params = {}) {
  const id = ++seq;
  ws.send(JSON.stringify({id, method, params}));
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('timeout ' + method)), 30000);
    waiters.set(id, (message) => {
      clearTimeout(timer);
      if (message.error) reject(new Error(JSON.stringify(message.error)));
      else resolve(message.result || {});
    });
  });
}
function waitLoad() {
  return new Promise((resolve) => {
    const timer = setTimeout(resolve, 20000);
    loadWaiters.push(() => { clearTimeout(timer); resolve(); });
  });
}
async function evaluate(expression) {
  const result = await send('Runtime.evaluate', {expression, returnByValue: true, awaitPromise: true});
  if (result.exceptionDetails) errors.push(result.exceptionDetails.text || 'evaluate exception');
  return result.result ? result.result.value : null;
}
async function open(url) {
  const loaded = waitLoad();
  await send('Page.navigate', {url});
  await loaded;
  await evaluate('new Promise((resolve) => setTimeout(resolve, 800))');
}
async function shot(name) {
  const image = await send('Page.captureScreenshot', {format: 'png'});
  fs.writeFileSync(shotDir + '/' + name, Buffer.from(image.data, 'base64'));
}
const version = await fetch('http://127.0.0.1:9222/json/version').then((response) => response.json());
const page = await fetch('http://127.0.0.1:9222/json/new?about:blank', {method: 'PUT'}).then((response) => response.json());
ws = new WebSocket(page.webSocketDebuggerUrl || version.webSocketDebuggerUrl);
await new Promise((resolve, reject) => {
  ws.addEventListener('open', resolve);
  ws.addEventListener('error', reject);
});
ws.addEventListener('message', (event) => {
  const message = JSON.parse(event.data);
  if (message.id && waiters.has(message.id)) {
    const done = waiters.get(message.id);
    waiters.delete(message.id);
    done(message);
  }
  if (message.method === 'Page.loadEventFired') {
    const done = loadWaiters.shift();
    if (done) done();
  }
  if (message.method === 'Page.frameNavigated' && message.params.frame && !message.params.frame.parentId) {
    navigations.push(message.params.frame.url || '');
  }
  if (message.method === 'Runtime.exceptionThrown') {
    errors.push(message.params.exceptionDetails.exception?.description || message.params.exceptionDetails.text || 'exception');
  }
  if (message.method === 'Network.requestWillBeSent' && message.params.request.method === 'POST') {
    posts.push(message.params.request.url);
  }
});
await send('Network.enable');
await send('Page.enable');
await send('Runtime.enable');
const report = {steps: []};
const step = (name, condition, detail) => {
  report.steps.push({name, pass: !!condition, detail: detail || ''});
  console.log((condition ? 'PASS ' : 'FAIL ') + name + (detail ? ' ' + detail : ''));
};
fs.mkdirSync(shotDir, {recursive: true});

await send('Emulation.setDeviceMetricsOverride', {width: 1280, height: 900, deviceScaleFactor: 1, mobile: false});
await open(loginUrl);
navigations.length = 0;
await open(base + '/admin/xedc/ai/seo');
const form = await evaluate(`(() => {
  const section = document.querySelector('.xedc-ai-seo-start');
  const panel = [...document.querySelectorAll('details')].find((item) => item.querySelector('summary') && item.querySelector('summary').textContent.includes('新建分析'));
  if (panel) panel.open = true;
  const root = section || panel || document.body;
  const text = root.innerText;
  const beforeButton = text.split('开始 SEO')[0] || text;
  return {
    revision: !!root.querySelector('input[name="revision"], [data-xedc-seo-revision]'),
    hasId: text.includes('内容 ID'),
    button: !!root.querySelector('[data-xedc-seo-start], input[value="开始 SEO 分析"]'),
    help: text.includes('输入需要进行 SEO 分析的内容 ID，系统将自动读取当前修订版本。'),
    placeholder: (root.querySelector('input[name="nid"]') || {}).placeholder || '',
    machine: text.includes('xedc_editorial'),
    workflowNoise: beforeButton.includes('工作流：') || beforeButton.includes('xedc_editorial'),
    phase: (document.querySelector('[data-xedc-seo-phase]') || {}).textContent || '',
    path: location.pathname,
  };
})()`);
step('只保留内容 ID，没有修订版本输入框', form && form.revision === false && form.hasId && form.button && form.placeholder === '请输入内容 ID' && form.help && form.machine === false, JSON.stringify(form));
step('内容 ID 区域不混入工作流信息', form && form.machine === false && form.workflowNoise === false, JSON.stringify(form));
step('打开页面能恢复等待中的任务', form && form.phase.trim() === '等待处理' && form.path === '/admin/xedc/ai/seo', form ? form.phase : '');
await shot('form-waiting-1280.png');

const list = await evaluate(`(() => {
  const table = document.querySelector('.xedc-ai-seo-list');
  const text = table ? table.innerText : '';
  const headers = [...(table ? table.querySelectorAll('thead th') : [])].map((item) => item.textContent.trim());
  return {
    hasTable: !!table,
    headers,
    scoreLabel: text.includes('SEO 评分：'),
    issueLabel: text.includes('问题数：'),
    suggestLabel: text.includes('建议数：'),
    modelLabel: text.includes('模型：'),
    tokenLabel: text.includes('Token：') || text.includes('Token '),
    costLabel: text.includes('费用：'),
    idLabel: text.includes('内容 ID：'),
    typeLabel: text.includes('内容类型：'),
    revisionCol: headers.includes('修订') || /\\n修订\\n/.test(text),
    action: text.includes('查看详情'),
  };
})()`);
step('列表字段都有明确标签', list && list.hasTable && list.scoreLabel && list.issueLabel && list.suggestLabel && list.modelLabel && list.tokenLabel && list.costLabel && list.idLabel && list.typeLabel && list.action && list.revisionCol === false, JSON.stringify(list));
await shot('list-1280.png');

await open(base + '/admin/xedc/ai/seo');
const restored = await evaluate(`(() => {
  return (document.querySelector('[data-xedc-seo-phase]') || {}).textContent || '';
})()`);
step('刷新后仍显示等待处理', (restored || '').trim() === '等待处理', restored || '');
fs.writeFileSync('/tmp/xedc-seo-seen-pending', '1');
const phases = await evaluate(`new Promise((resolve) => {
  const seen = [];
  const read = () => {
    const text = ((document.querySelector('[data-xedc-seo-phase]') || {}).textContent || '').trim();
    if (text && seen[seen.length - 1] !== text) seen.push(text);
  };
  const timer = setInterval(read, 300);
  setTimeout(() => { clearInterval(timer); read(); resolve({seen, score: document.body.innerText.includes('评分 81'), issue: document.body.innerText.includes('标题可以更具体')}); }, 14000);
})`);
step('状态自动经过分析、建议并完成', phases && phases.seen.includes('正在分析') && phases.seen.includes('正在生成建议') && phases.seen.includes('已完成') && phases.score && phases.issue, JSON.stringify(phases));
await shot('form-completed-1280.png');

const beforeNav = navigations.length;
const postsBefore = posts.length;
await evaluate(`(() => {
  document.documentElement.dataset.xedcStay = '1';
  const input = document.querySelector('input[name="nid"]');
  const button = document.querySelector('[data-xedc-seo-start]');
  const setter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value');
  setter.set.call(input, '${meta.page_nid}');
  input.dispatchEvent(new Event('input', {bubbles: true}));
  input.dispatchEvent(new Event('change', {bubbles: true}));
  button.dispatchEvent(new MouseEvent('mousedown', {bubbles: true, cancelable: true, view: window}));
})()`);
const clicked = await evaluate(`new Promise((resolve) => {
  const seen = [];
  const read = () => {
    const text = ((document.querySelector('[data-xedc-seo-phase]') || {}).textContent || '').trim();
    if (text && seen[seen.length - 1] !== text) seen.push(text);
  };
  const timer = setInterval(read, 200);
  setTimeout(() => {
    clearInterval(timer);
    resolve({
      seen,
      stay: document.documentElement.dataset.xedcStay || '',
      path: location.pathname,
      error: document.body.innerText.includes('没有可用') || document.body.innerText.includes('API'),
      body: (document.querySelector('[data-xedc-seo-job]') || {}).innerText || '',
      busy: ((document.querySelector('[data-xedc-seo-start]') || {}).value || '').includes('排队') || (document.querySelector('[data-xedc-seo-start]') || {}).disabled === true,
    });
  }, 8000);
})`);
step('点击开始分析不整页刷新', clicked && clicked.stay === '1' && clicked.path === '/admin/xedc/ai/seo' && navigations.length === beforeNav && posts.length > postsBefore, JSON.stringify({path: clicked && clicked.path, navigations: navigations.length - beforeNav, posts: posts.length - postsBefore}));
step('提交后先显示等待，再显示失败原因', clicked && clicked.seen.includes('等待处理') && clicked.seen.includes('分析失败') && clicked.error, JSON.stringify(clicked && clicked.seen) + ' ' + (clicked && clicked.body));
await shot('form-failed-1280.png');

async function checkCase(name, facts, extra) {
  if (!facts || !facts.review) {
    step(name, false, '实验室数据里没有对应内容');
    return;
  }
  await open(base + '/admin/xedc/ai/seo/' + facts.review);
  const found = await evaluate(`(() => {
    const basic = document.querySelector('.xedc-ai-basic');
    const stats = document.querySelector('[data-xedc-suggest-stats]');
    const text = basic ? basic.innerText : '';
    return {
      text,
      stats: stats ? stats.innerText : '',
      machine: text.includes('xedc_editorial'),
      partialInBasic: text.includes('部分已采纳'),
      rules: document.body.innerText.includes('评分规则') || document.body.innerText.includes('换算成 100 分'),
      overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth + 2,
      contentTypeLabel: text.includes('内容类型'),
    };
  })()`);
  const ok = found && facts.bundle && found.text.includes(facts.bundle)
    && found.text.includes(String(facts.nid))
    && found.text.includes(String(facts.analysis_vid))
    && found.text.includes(String(facts.vid))
    && found.text.includes(facts.publish)
    && found.text.includes(facts.status)
    && found.text.includes(facts.workflow)
    && found.text.includes(facts.state)
    && found.machine === false
    && found.partialInBasic === false
    && found.rules === false
    && found.contentTypeLabel
    && found.overflow === false
    && (!facts.required_state || facts.state === facts.required_state)
    && (!extra || extra(found, facts));
  step(name, ok, JSON.stringify({expect: facts, text: found && found.text, stats: found && found.stats, rules: found && found.rules}));
}

await checkCase('已发布内容', meta.cases.published, (found, facts) => facts.match ? !found.text.includes('内容已有新修订') : found.text.includes('内容已有新修订'));
await checkCase('已发布且有发布时间', meta.cases.published_time, (found, facts) => found.text.includes(facts.publish) && facts.publish !== '未发布' && facts.publish !== '暂无发布时间');
await shot('detail-published-1280.png');
await checkCase('草稿内容', meta.cases.draft);
await shot('detail-draft-1280.png');
await checkCase('待审核内容', meta.cases.needs_review);
await checkCase('退回修改内容', meta.cases.changes_requested);
await checkCase('已拒绝内容', meta.cases.rejected);
await checkCase('已撤下内容', meta.cases.archived);
await shot('detail-archived-1280.png');
await checkCase('没有工作流的页面', meta.cases.no_workflow, (found) => found.text.includes('未配置') && found.text.includes('无审核工作流'));
await shot('detail-page-1280.png');
if (meta.mismatch) {
  await checkCase('分析修订和当前修订不一致', meta.mismatch, (found, facts) => found.text.includes('内容已有新修订，本次分析基于历史版本。') && (!facts.historical_state || found.text.includes('分析修订审核状态')));
  await shot('detail-mismatch-1280.png');
}
else {
  step('分析修订和当前修订不一致', false, '没有找到修订不一致的历史分析');
}
await open(base + '/admin/xedc/ai/seo/' + meta.partial);
const partial = await evaluate(`(() => {
  const basic = document.querySelector('.xedc-ai-basic');
  const stats = document.querySelector('[data-xedc-suggest-stats]');
  return {
    basic: basic ? basic.innerText : '',
    stats: stats ? stats.innerText : '',
    rules: document.body.innerText.includes('评分规则'),
  };
})()`);
step('部分已采纳在建议统计里', partial && !partial.basic.includes('部分已采纳') && partial.stats.includes('部分已采纳') && partial.stats.includes('待确认') && partial.stats.includes('已采纳') && partial.stats.includes('已忽略') && partial.stats.includes('采纳失败') && partial.stats.includes('版本冲突') && partial.rules === false, JSON.stringify(partial));
await shot('detail-partial-1280.png');

await open(base + '/admin/xedc/ai/seo/' + meta.list_review);
const zh = await evaluate(`(() => {
  const body = document.body.innerText;
  return {
    highDesc: body.includes('高 · 页面描述'),
    rawHigh: /\\bhigh\\b/.test(body) && body.includes('high ·'),
    rawDesc: body.includes('high · description') || body.includes(' · description ·'),
    rules: body.includes('评分规则') || body.includes('换算成 100 分') || body.includes('已检查 65/95'),
    score: body.includes('SEO 参考评分') && body.includes('68'),
  };
})()`);
step('英文枚举显示为中文', zh && zh.highDesc && zh.rawHigh === false && zh.rawDesc === false, JSON.stringify(zh));
step('详情页已移除评分规则且保留总分', zh && zh.rules === false && zh.score, JSON.stringify(zh));
await shot('detail-zh-1280.png');

await send('Emulation.setDeviceMetricsOverride', {width: 390, height: 844, deviceScaleFactor: 1, mobile: true});
await open(base + '/admin/xedc/ai/seo');
const mobileList = await evaluate(`(() => {
  const table = document.querySelector('.xedc-ai-seo-list');
  const first = table && table.querySelector('tbody tr td[data-label]');
  const labels = [...(table ? table.querySelectorAll('tbody tr:first-child td[data-label]') : [])].map((td) => td.getAttribute('data-label'));
  return {
    overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth + 2,
    hasDataLabel: !!first,
    labels,
  };
})()`);
step('移动端列表有字段标签且无横向溢出', mobileList && mobileList.overflow === false && mobileList.hasDataLabel && mobileList.labels.includes('SEO 结果') && mobileList.labels.includes('AI 用量'), JSON.stringify(mobileList));
await shot('list-390.png');
await open(base + '/admin/xedc/ai/seo/' + (meta.cases.archived ? meta.cases.archived.review : meta.partial));
const mobile = await evaluate(`document.documentElement.scrollWidth > document.documentElement.clientWidth + 2`);
step('390 详情没有横向溢出', mobile === false, '');
await shot('detail-390.png');

await send('Emulation.setDeviceMetricsOverride', {width: 1280, height: 900, deviceScaleFactor: 1, mobile: false});
await evaluate(`document.documentElement.setAttribute('data-theme', 'dark'); document.documentElement.classList.add('xedc-admin-active');`);
await shot('detail-dark-1280.png');
await open(base + '/admin/xedc/ai/seo');
await evaluate(`document.documentElement.setAttribute('data-theme', 'dark');`);
await shot('list-dark-1280.png');

const failed = report.steps.filter((item) => !item.pass);
fs.writeFileSync(shotDir + '/results.json', JSON.stringify({pass: failed.length === 0, steps: report.steps, errors}, null, 2));
console.log('BROWSER ' + (failed.length === 0 ? 'PASS' : 'FAIL') + ' ' + failed.length);
if (errors.length) console.log('ERRORS ' + errors.slice(0, 5).join(' | '));
process.exit(failed.length === 0 ? 0 : 1);
