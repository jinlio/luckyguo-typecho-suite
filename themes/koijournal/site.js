(() => {
  const root = document.documentElement;
  const motionQuery = matchMedia('(prefers-reduced-motion: reduce)');
  const updateColor = () => document.querySelector('meta[name="theme-color"]')?.setAttribute('content', root.dataset.theme === 'dark' ? '#1c191d' : '#fcfafb');
  const themeToggle = document.querySelector('.theme-toggle');
  let themeStyleTimer = 0;
  let themeAnimationTimer = 0;
  const themeConfig = window.SuiteThemeConfig || { name: 'suite-theme', domain: '' };
  const motionEnabled = themeConfig.motion !== 'off';
  const reducedMotion = () => !motionEnabled || motionQuery.matches;
  const cookieName = /^[A-Za-z][A-Za-z0-9_-]{0,63}$/.test(themeConfig.name) ? themeConfig.name : 'suite-theme';
  const cookieDomain = typeof themeConfig.domain === 'string' && /^[.]?[A-Za-z0-9.-]+$/.test(themeConfig.domain) ? themeConfig.domain : '';
  const saveTheme = (theme) => {
    try { localStorage.setItem(cookieName, theme); } catch (e) {}
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

  const armCommentAvatarFallback = (image) => {
    if (image.dataset.avatarFallbackArmed === 'true') return;
    const fallback = image.dataset.avatarFallback;
    if (!fallback) return;
    image.dataset.avatarFallbackArmed = 'true';
    const useFallback = () => {
      if (image.dataset.avatarFallbackApplied === 'true') return;
      image.dataset.avatarFallbackApplied = 'true';
      image.removeAttribute('onerror');
      image.src = fallback;
    };
    if (image.complete && image.naturalWidth > 0) return;
    const timer = setTimeout(() => {
      if (image.naturalWidth === 0) useFallback();
    }, 2800);
    image.addEventListener('load', () => clearTimeout(timer), { once: true });
    image.addEventListener('error', () => {
      clearTimeout(timer);
      useFallback();
    }, { once: true });
  };
  const commentAvatars = document.querySelectorAll('.comment-author img.avatar[data-avatar-fallback]');
  if ('IntersectionObserver' in window) {
    const avatarObserver = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        armCommentAvatarFallback(entry.target);
        avatarObserver.unobserve(entry.target);
      });
    }, { rootMargin: '200px' });
    commentAvatars.forEach((image) => avatarObserver.observe(image));
  } else {
    commentAvatars.forEach(armCommentAvatarFallback);
  }
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
  const navToggle = document.querySelector('.nav-toggle');
  const siteNav = document.querySelector('.site-nav');
  let headerElement;
  const syncHeaderOffsets = () => {
    headerElement = headerElement || document.querySelector('.site-header');
    if (!headerElement) return;
    const height = headerElement.offsetHeight || 70;
    root.style.setProperty('--header-height', `${height}px`);
    root.style.setProperty('--toc-top-offset', `${height + 8}px`);
  };
  syncHeaderOffsets();
  if ('ResizeObserver' in window) {
    const headerResizeObserver = new ResizeObserver(syncHeaderOffsets);
    if (headerElement) headerResizeObserver.observe(headerElement);
  }
  const updateNavState = (open) => {
    siteNav?.classList.toggle('is-open', open);
    navToggle?.setAttribute('aria-expanded', String(open));
    navToggle?.setAttribute('aria-label', open ? '关闭导航菜单' : '打开导航菜单');
    syncHeaderOffsets();
  };
  const updateSearchState = (open) => {
    searchBar?.classList.toggle('open', open);
    searchToggle?.setAttribute('aria-expanded', String(open));
    searchToggle?.setAttribute('aria-label', open ? '关闭搜索' : '打开搜索');
    syncHeaderOffsets();
    if (open) searchBar?.querySelector('input')?.focus();
  };
  const setNavOpen = (open) => {
    if (open) updateSearchState(false);
    updateNavState(open);
  };
  navToggle?.addEventListener('click', () => {
    setNavOpen(!(siteNav?.classList.contains('is-open') ?? false));
  });
  siteNav?.addEventListener('click', (event) => {
    if (event.target.closest('a')) setNavOpen(false);
  });
  addEventListener('resize', () => {
    if (innerWidth > 820) setNavOpen(false);
  }, { passive: true });
  const shortcut = document.querySelector('[data-search-shortcut]');
  if (shortcut && !/Mac|iPhone|iPad|iPod/i.test(navigator.platform || navigator.userAgent)) shortcut.textContent = 'Ctrl K';
  const setSearchOpen = (open) => {
    if (open) updateNavState(false);
    updateSearchState(open);
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
    } else if (event.key === 'Escape' && siteNav?.classList.contains('is-open')) {
      setNavOpen(false);
      navToggle?.focus();
    }
  });

  addEventListener('pageshow', () => root.classList.remove('page-leaving'));
  const searchInput = searchBar?.querySelector('input[name="s"]');
  searchInput?.addEventListener('input', () => searchInput.setCustomValidity(''));
  searchBar?.addEventListener('submit', (event) => {
    const input = searchInput;
    if (input && !input.value.trim()) {
      event.preventDefault();
      input.setCustomValidity('请输入搜索关键词');
      input.reportValidity();
      input.focus();
    } else if (input) {
      input.setCustomValidity('');
    }
  });

  const contextBack = document.querySelector('[data-context-back]');
  if (contextBack && document.referrer) {
    try {
      const referrer = new URL(document.referrer);
      if (referrer.origin === location.origin && referrer.pathname !== location.pathname) {
        contextBack.href = referrer.href;
      }
    } catch (e) {}
  }

  const header = headerElement || document.querySelector('.site-header');
  const article = document.querySelector('.article[data-reading-progress="on"]');
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
    const headings = [...articleContent.querySelectorAll(articleToc.dataset.tocDepth === 'h2' ? 'h2' : 'h2, h3')];
    if (!headings.length) {
      articleToc.hidden = true;
    } else {
      const tocList = articleToc.querySelector('ol');
      const tocLinks = new Map();
      const usedIds = new Set();
      let majorNumber = 0;
      let minorNumber = 0;
      headings.forEach((heading, index) => {
        const baseId = heading.id || `section-${index + 1}`;
        let id = baseId;
        let suffix = 2;
        while (usedIds.has(id) || (document.getElementById(id) && document.getElementById(id) !== heading)) {
          id = `${baseId}-${suffix++}`;
        }
        usedIds.add(id);
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

      const tocToggle = articleToc.querySelector('.article-toc-toggle');
      const setTocExpanded = (expanded) => {
        articleToc.classList.toggle('is-collapsed', !expanded);
        tocToggle?.setAttribute('aria-expanded', String(expanded));
        tocList?.setAttribute('aria-hidden', String(!expanded));
        if (tocList && 'inert' in tocList) tocList.inert = !expanded;
      };
      tocToggle?.addEventListener('click', () => {
        setTocExpanded(articleToc.classList.contains('is-collapsed'));
      });
      tocList?.addEventListener('click', (event) => {
        if (event.target.closest('a') && articleToc.classList.contains('is-mobile')) {
          setTocExpanded(false);
        }
      });
      setTocExpanded(true);

      const placeToc = () => {
        const isMobile = innerWidth <= 820;
        if (isMobile && !articleToc.classList.contains('is-mobile')) {
          articleLayout.parentNode.insertBefore(articleToc, articleLayout);
          articleToc.classList.add('is-mobile');
          setTocExpanded(false);
        } else if (!isMobile && articleToc.classList.contains('is-mobile')) {
          articleAside.appendChild(articleToc);
          articleToc.classList.remove('is-mobile');
          setTocExpanded(true);
        }
        syncHeaderOffsets();
      };
      placeToc();
      addEventListener('resize', placeToc, { passive: true });

      let activeTocLink = null;
      const setActiveToc = (heading) => {
        const link = tocLinks.get(heading?.id);
        if (!link || link === activeTocLink) return;
        activeTocLink?.classList.remove('is-active');
        link.classList.add('is-active');
        link.setAttribute('aria-current', 'location');
        activeTocLink?.removeAttribute('aria-current');
        activeTocLink = link;
        if (!articleToc.classList.contains('is-mobile')) {
          const tocRect = articleToc.getBoundingClientRect();
          const linkRect = link.getBoundingClientRect();
          if (linkRect.top < tocRect.top || linkRect.bottom > tocRect.bottom) {
            const offset = linkRect.top < tocRect.top
              ? linkRect.top - tocRect.top
              : linkRect.bottom - tocRect.bottom;
            articleToc.scrollTop += offset;
          }
        }
      };
      setActiveToc(headings[0]);
      if (!reducedMotion() && 'IntersectionObserver' in window) {
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
    root.style.setProperty('--header-height', `${headerHeight}px`);
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

  if (!reducedMotion() && 'IntersectionObserver' in window) {
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

  document.querySelectorAll('.article-share').forEach((share) => {
    const copy = share.querySelector('[data-share-copy]');
    const toast = share.querySelector('[data-share-toast]');
    copy?.addEventListener('click', async () => {
      const url = share.dataset.shareUrl || location.href;
      try {
        await navigator.clipboard.writeText(url);
        if (toast) toast.textContent = '链接已复制';
      } catch (error) {
        if (toast) toast.textContent = '请手动复制地址';
      }
      setTimeout(() => { if (toast) toast.textContent = ''; }, 2200);
    });
  });
})();
