(() => {
  const root = document.documentElement;
  const motionQuery = matchMedia('(prefers-reduced-motion: reduce)');
  const updateColor = () => document.querySelector('meta[name="theme-color"]')?.setAttribute('content', root.dataset.theme === 'dark' ? '#1c191d' : '#fcfafb');
  const themeToggle = document.querySelector('.theme-toggle');
  let themeStyleTimer = 0;
  let themeAnimationTimer = 0;
  const themeConfig = window.SuiteThemeConfig || { name: 'suite-theme', domain: '' };
  const cookieName = /^[A-Za-z][A-Za-z0-9_-]{0,63}$/.test(themeConfig.name) ? themeConfig.name : 'suite-theme';
  const cookieDomain = typeof themeConfig.domain === 'string' && /^[.]?[A-Za-z0-9.-]+$/.test(themeConfig.domain) ? themeConfig.domain : '';
  const saveTheme = (theme) => {
    localStorage.setItem(cookieName, theme);
    document.cookie = `${cookieName}=${theme}; Max-Age=31536000; Path=/${cookieDomain ? `; Domain=${cookieDomain}` : ''}; SameSite=Lax; Secure`;
  };
  themeToggle?.addEventListener('animationend', (event) => {
    if (event.animationName !== 'theme-turn') return;
    clearTimeout(themeAnimationTimer);
    themeAnimationTimer = 0;
    themeToggle.classList.remove('is-rotating');
  });
  const updateThemeLabel = () => themeToggle?.setAttribute('aria-label', root.dataset.theme === 'dark' ? '切换到浅色主题' : '切换到深色主题');
  updateColor();
  updateThemeLabel();
  themeToggle?.addEventListener('click', () => {
    const nextTheme = root.dataset.theme === 'dark' ? 'light' : 'dark';
    root.classList.add('theme-switching');
    void root.offsetWidth;
    themeToggle.classList.remove('is-rotating');
    void themeToggle.offsetWidth;
    themeToggle.classList.add('is-rotating');
    root.dataset.theme = nextTheme;
    saveTheme(root.dataset.theme);
    updateColor();
    updateThemeLabel();
    clearTimeout(themeStyleTimer);
    clearTimeout(themeAnimationTimer);
    themeStyleTimer = setTimeout(() => {
      root.classList.remove('theme-switching');
      themeStyleTimer = 0;
    }, 280);
    themeAnimationTimer = setTimeout(() => {
      themeToggle.classList.remove('is-rotating');
      themeAnimationTimer = 0;
    }, 700);
  });

  const searchToggle = document.querySelector('.search-toggle');
  const searchBar = document.querySelector('.search-bar');
  const setSearchOpen = (open) => {
    searchBar?.classList.toggle('open', open);
    searchToggle?.setAttribute('aria-expanded', String(open));
    searchToggle?.setAttribute('aria-label', open ? '关闭搜索' : '打开搜索');
    if (open) searchBar?.querySelector('input')?.focus();
  };
  searchToggle?.addEventListener('click', () => {
    setSearchOpen(!(searchBar?.classList.contains('open') ?? false));
  });
  document.addEventListener('keydown', (event) => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
      event.preventDefault();
      setSearchOpen(true);
      return;
    }
    if (event.key === 'Escape' && searchBar?.classList.contains('open')) {
      setSearchOpen(false);
      searchToggle?.focus();
    }
  });

  const beginNavigation = (target) => {
    root.classList.add('page-leaving');
    setTimeout(() => location.assign(target), 230);
  };
  addEventListener('pageshow', () => root.classList.remove('page-leaving'));
  document.addEventListener('click', (event) => {
    if (motionQuery.matches || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const link = event.target.closest('a[href]');
    if (!link || link.target || link.hasAttribute('download')) return;
    const target = new URL(link.href, location.href);
    if (!['http:', 'https:'].includes(target.protocol)) return;
    if (target.origin === location.origin && target.pathname === location.pathname && target.search === location.search && target.hash) return;
    event.preventDefault();
    beginNavigation(target.href);
  });
  searchBar?.addEventListener('submit', (event) => {
    if (motionQuery.matches || event.defaultPrevented) return;
    event.preventDefault();
    root.classList.add('page-leaving');
    setTimeout(() => HTMLFormElement.prototype.submit.call(searchBar), 230);
  });

  const header = document.querySelector('.site-header');
  const article = document.querySelector('.article:not([data-reading-progress="off"])');
  let readingProgress;
  if (header && article) {
    readingProgress = document.createElement('span');
    readingProgress.className = 'reading-progress';
    readingProgress.setAttribute('aria-hidden', 'true');
    header.appendChild(readingProgress);
  }

  const articleContent = document.querySelector('.article-content');
  const articleToc = document.querySelector('.article-toc');
  const articleLayout = document.querySelector('.article-layout');
  const articleAside = document.querySelector('.article-aside');
  if (articleContent && articleToc && articleLayout && articleAside) {
    const headings = [...articleContent.querySelectorAll('h2, h3')];
    if (!headings.length) {
      articleToc.hidden = true;
    } else {
      const tocList = articleToc.querySelector('ol');
      const tocLinks = new Map();
      let majorNumber = 0;
      let minorNumber = 0;
      headings.forEach((heading, index) => {
        const id = heading.id || `section-${index + 1}`;
        heading.id = id;
        const item = document.createElement('li');
        const level = heading.tagName === 'H3' ? 3 : 2;
        item.dataset.level = String(level);
        if (level === 2) {
          majorNumber += 1;
          minorNumber = 0;
        } else {
          minorNumber += 1;
        }
        const link = document.createElement('a');
        link.href = `#${id}`;
        const number = document.createElement('span');
        number.className = 'toc-index';
        number.textContent = level === 2
          ? String(majorNumber)
          : `${majorNumber}.${minorNumber}`;
        const title = document.createElement('span');
        title.textContent = heading.textContent.trim();
        link.append(number, title);
        item.append(link);
        tocList.append(item);
        tocLinks.set(id, link);
      });

      const placeToc = () => {
        const isMobile = innerWidth <= 820;
        if (isMobile && !articleToc.classList.contains('is-mobile')) {
          articleLayout.parentNode.insertBefore(articleToc, articleLayout);
          articleToc.classList.add('is-mobile');
        } else if (!isMobile && articleToc.classList.contains('is-mobile')) {
          articleAside.appendChild(articleToc);
          articleToc.classList.remove('is-mobile');
        }
      };
      placeToc();
      addEventListener('resize', placeToc, { passive: true });

      let activeTocLink = null;
      const setActiveToc = (heading) => {
        const link = tocLinks.get(heading?.id);
        if (!link || link === activeTocLink) return;
        activeTocLink?.classList.remove('is-active');
        link.classList.add('is-active');
        activeTocLink = link;
        if (!articleToc.classList.contains('is-mobile')) {
          const tocRect = articleToc.getBoundingClientRect();
          const linkRect = link.getBoundingClientRect();
          if (linkRect.top < tocRect.top || linkRect.bottom > tocRect.bottom) {
            link.scrollIntoView({ block: 'nearest' });
          }
        }
      };
      setActiveToc(headings[0]);
      if (!motionQuery.matches && 'IntersectionObserver' in window) {
        const tocObserver = new IntersectionObserver((entries) => {
          const visible = entries.filter((entry) => entry.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);
          if (visible[0]) setActiveToc(visible[0].target);
        }, { rootMargin: '-120px 0px -62% 0px', threshold: 0 });
        headings.forEach((heading) => tocObserver.observe(heading));
      }
    }
  }

  let scrollFrame = 0;
  let articleStart = 0;
  let articleHeight = 0;
  let headerHeight = 0;
  let headerScrolled = false;
  const refreshScrollMetrics = () => {
    if (!article) return;
    headerHeight = header?.offsetHeight ?? 0;
    articleStart = article.getBoundingClientRect().top + scrollY - headerHeight;
    articleHeight = article.offsetHeight;
  };
  const updateScrollState = () => {
    scrollFrame = 0;
    const nextHeaderScrolled = scrollY > 12;
    if (nextHeaderScrolled !== headerScrolled) {
      header?.classList.toggle('is-scrolled', nextHeaderScrolled);
      headerScrolled = nextHeaderScrolled;
    }
    if (!readingProgress || !article) return;
    const distance = Math.max(articleHeight - innerHeight * .55, 1);
    const progress = Math.min(1, Math.max(0, (scrollY - articleStart) / distance));
    readingProgress.style.setProperty('--reading-progress', progress.toFixed(4));
  };
  const requestScrollUpdate = () => {
    if (scrollFrame) return;
    scrollFrame = requestAnimationFrame(updateScrollState);
  };
  addEventListener('scroll', requestScrollUpdate, { passive: true });
  addEventListener('resize', () => {
    refreshScrollMetrics();
    requestScrollUpdate();
  }, { passive: true });
  addEventListener('load', () => {
    refreshScrollMetrics();
    requestScrollUpdate();
  }, { once: true, passive: true });
  refreshScrollMetrics();
  updateScrollState();

  if (!motionQuery.matches && 'IntersectionObserver' in window) {
    const revealElement = (element) => {
      element.classList.add('is-visible');
      const delay = Number.parseInt(element.style.getPropertyValue('--reveal-delay'), 10) || 0;
      setTimeout(() => element.classList.remove('reveal-item'), 760 + delay);
    };
    const revealObserver = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        revealElement(entry.target);
        revealObserver.unobserve(entry.target);
      });
    }, { rootMargin: '0px 0px -8% 0px', threshold: .08 });

    const revealTargets = document.querySelectorAll([
      '.section-heading', '.post-row', '.native-widget',
      '.article-content > h2, .article-content > h3', '.article-aside', '.about-masthead > *',
      '.about-story-heading', '.about-copy > *', '.comments',
      '.post-near', '.error-page > *', '.archive-year-heading',
      '.archive-month-heading'
    ].join(','));
    revealTargets.forEach((element, index) => {
      element.classList.add('reveal-item');
      element.style.setProperty('--reveal-delay', `${(index % 4) * 55}ms`);
      if (element.getBoundingClientRect().top < innerHeight * .92) {
        revealElement(element);
      } else {
        revealObserver.observe(element);
      }
    });

    if (innerWidth > 820 && matchMedia('(hover: hover) and (pointer: fine)').matches) {
      document.querySelectorAll('.journal-banner, .article-cover').forEach((visual) => {
        const image = visual.querySelector('img');
        if (!image) return;
        visual.classList.add('parallax-visual');
        let parallaxFrame = 0;
        let currentX = 0;
        let currentY = 0;
        let targetX = 0;
        let targetY = 0;
        const paintParallax = () => {
          currentX += (targetX - currentX) * .14;
          currentY += (targetY - currentY) * .14;
          image.style.setProperty('--parallax-x', `${currentX.toFixed(2)}px`);
          image.style.setProperty('--parallax-y', `${currentY.toFixed(2)}px`);
          if (Math.abs(targetX - currentX) > .01 || Math.abs(targetY - currentY) > .01) {
            parallaxFrame = requestAnimationFrame(paintParallax);
          } else {
            currentX = targetX;
            currentY = targetY;
            parallaxFrame = 0;
          }
        };
        const requestParallax = () => {
          if (!parallaxFrame) parallaxFrame = requestAnimationFrame(paintParallax);
        };
        visual.addEventListener('pointermove', (event) => {
          const rect = visual.getBoundingClientRect();
          targetX = ((event.clientX - rect.left) / rect.width - .5) * 10;
          targetY = ((event.clientY - rect.top) / rect.height - .5) * 7;
          requestParallax();
        });
        visual.addEventListener('pointerleave', () => {
          targetX = 0;
          targetY = 0;
          requestParallax();
        });
      });
    }
  }
})();
