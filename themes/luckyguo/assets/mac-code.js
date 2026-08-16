// luckyguo theme - macOS-style code block initializer (v3)
// Pairs with mac-code.css + Prism (core + autoloader + line-numbers)
(() => {
  if (typeof Prism === 'undefined') return;

  // Configure autoloader to fetch from our self-hosted path (privacy: no external CDN)
  if (Prism.plugins && Prism.plugins.autoloader) {
    Prism.plugins.autoloader.languages_path = '/usr/themes/luckyguo/assets/prism/';
  }

  const langs = {
    php: 'Php', bash: 'Bash', sh: 'Shell', shell: 'Shell',
    python: 'Python', py: 'Python',
    java: 'Java', js: 'JavaScript', javascript: 'JavaScript', ts: 'TypeScript',
    sql: 'SQL', nginx: 'Nginx', json: 'JSON', yaml: 'YAML', yml: 'YAML',
    xml: 'XML', html: 'HTML', css: 'CSS', scss: 'SCSS',
    markdown: 'Markdown', md: 'Markdown',
    ini: 'Ini', toml: 'TOML', dockerfile: 'Dockerfile', diff: 'Diff',
    c: 'C', cpp: 'C++', go: 'Go', rust: 'Rust', ruby: 'Ruby', php5: 'Php',
    powershell: 'PowerShell', ps1: 'PowerShell', bat: 'Bat',
    plain: 'Plain', text: 'Plain', none: 'Plain', txt: 'Plain'
  };

  // Force re-wrap by clearing data attribute first
  document.querySelectorAll('pre > code').forEach((code) => {
    const pre = code.parentElement;
    if (pre && pre.tagName === 'PRE') {
      delete pre.dataset.macReady;
      // Also remove stale controls so we re-create them with the fresh language.
      const controlRoot = pre.parentElement?.classList.contains('mac-code-shell') ? pre.parentElement : pre;
      const oldTitle = controlRoot.querySelector('.mac-code-title');
      if (oldTitle) oldTitle.remove();
      const oldCopy = controlRoot.querySelector('.mac-code-copy');
      if (oldCopy) oldCopy.remove();
    }
  });

  const wrap = (pre) => {
    if (pre.dataset.macReady === '1') return;
    const code = pre.querySelector('code');
    if (!code) return;

    let shell = pre.parentElement?.classList.contains('mac-code-shell') ? pre.parentElement : null;
    if (!shell) {
      shell = document.createElement('div');
      shell.className = 'mac-code-shell';
      pre.parentNode.insertBefore(shell, pre);
      shell.appendChild(pre);
    }

    // detect language: support Prism `language-XXX`, Typecho `lang-XXX`, and a data-attribute fallback
    let lang = '';
    const sources = [
      () => code.getAttribute('class') || '',
      () => code.getAttribute('data-language') || '',
      () => pre.getAttribute('data-language') || '',
    ];
    for (const src of sources) {
      const cls = src();
      // capture group 2 is the language name; group 1 is reserved for "text"/"none" filter
      const m = cls.match(/(?:^|\s)(?:lang|language)-([a-z0-9_+-]+)/i);
      if (m && m[1]) {
        lang = m[1].toLowerCase();
        break;
      }
    }

    const title = langs[lang] || (lang ? lang.charAt(0).toUpperCase() + lang.slice(1) : 'CODE');

    // mark and decorate
    pre.classList.add('mac-code', 'line-numbers');
    if (lang && !pre.classList.contains(`language-${lang}`)) {
      pre.classList.add(`language-${lang}`);
    }
    pre.setAttribute('data-language', lang || 'text');

    // title
    if (!shell.querySelector('.mac-code-title')) {
      const t = document.createElement('span');
      t.className = 'mac-code-title';
      t.textContent = title;
      shell.appendChild(t);
    }

    // copy button
    if (!shell.querySelector('.mac-code-copy')) {
      const btn = document.createElement('button');
      btn.className = 'mac-code-copy';
      btn.type = 'button';
      btn.textContent = 'Copy';
      btn.setAttribute('aria-label', '复制代码');
      btn.addEventListener('click', async (ev) => {
        ev.preventDefault();
        const text = code.innerText.replace(/\u00a0/g, ' ');
        try {
          await navigator.clipboard.writeText(text);
        } catch (e) {
          const range = document.createRange();
          range.selectNodeContents(code);
          const sel = window.getSelection();
          sel.removeAllRanges();
          sel.addRange(range);
        }
        btn.textContent = 'Copied';
        btn.classList.add('copied');
        setTimeout(() => {
          btn.textContent = 'Copy';
          btn.classList.remove('copied');
        }, 1500);
      });
      shell.appendChild(btn);
    }

    pre.dataset.macReady = '1';
  };

  const init = () => {
    document.querySelectorAll('pre > code').forEach((code) => {
      const pre = code.parentElement;
      if (pre && pre.tagName === 'PRE') wrap(pre);
    });
    // trigger Prism to highlight after wrap
    if (typeof Prism.highlightAllUnder === 'function') {
      Prism.highlightAllUnder(document.body);
    } else if (typeof Prism.highlightAll === 'function') {
      Prism.highlightAll();
    }
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
