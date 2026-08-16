import {
  classifyServerConnection,
  MobileApiClient,
  MobileApiError,
  normalizeServerUrl,
} from './api';
import { Capacitor } from '@capacitor/core';
import { Haptics, ImpactStyle } from '@capacitor/haptics';
import {
  clearToken,
  getOrCreateDeviceUuid,
  loadCachedBootstrap,
  loadReminderHour,
  loadServerUrl,
  loadToken,
  saveServerUrl,
  saveCachedBootstrap,
  saveToken,
  scheduleDailyReminder,
} from './storage';
import type {
  ArticleSummary,
  Bootstrap,
  ChapterSummary,
  DailySummary,
  ReaderToken,
  ReviewItem,
  ReviewRating,
  OfflineSyncIssue,
  WordSenseSummary,
} from './types';
import { MediaCache } from './mediaCache';
import { OfflineRepository } from './offlineRepository';

type Screen = 'home' | 'library' | 'review' | 'vocabulary' | 'settings';

const REVIEW_RATINGS = ['again', 'hard', 'good', 'easy'] as const satisfies readonly ReviewRating[];
const LOCAL_HTTP_WARNING = '仅用于本地调试；Android/iOS 正式版可能拒绝明文连接；正式使用应配置 HTTPS。';

function isReviewRating(value: string | undefined): value is ReviewRating {
  return value !== undefined && REVIEW_RATINGS.some(rating => rating === value);
}

function apiPlatform(): 'android' | 'ios' | 'web' {
  const platform = Capacitor.getPlatform();
  return platform === 'android' || platform === 'ios' ? platform : 'web';
}

function platformLabel(): string {
  if (Capacitor.getPlatform() === 'ios') return 'IOS';
  if (Capacitor.getPlatform() === 'android') return 'ANDROID';
  return 'MOBILE';
}

function platformDeviceName(): string {
  if (Capacitor.getPlatform() === 'ios') return 'LinguaCafe iOS';
  if (Capacitor.getPlatform() === 'android') return 'LinguaCafe Android';
  return 'LinguaCafe Mobile';
}

function secureStorageLabel(): string {
  if (Capacitor.getPlatform() === 'ios') return 'Keychain';
  if (Capacitor.getPlatform() === 'android') return 'Keystore';
  return '当前会话';
}

function escapeHtml(value: unknown): string {
  return String(value ?? '')
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#039;');
}

function message(error: unknown): string {
  if (error instanceof MobileApiError || error instanceof Error) return error.message;
  return '操作失败，请重试';
}

function usesLocalDevelopmentHttp(value: string): boolean {
  try {
    return classifyServerConnection(value).kind === 'local-http-development';
  } catch {
    return false;
  }
}

export class LinguaCafeApp {
  private api: MobileApiClient | null = null;
  private bootstrap: Bootstrap | null = null;
  private screen: Screen = 'home';
  private loading = false;
  private articles: ArticleSummary[] = [];
  private chapters: ChapterSummary[] = [];
  private readerTokens: ReaderToken[] = [];
  private selectedBook: ArticleSummary | null = null;
  private selectedChapter: ChapterSummary | null = null;
  private lookupToken: ReaderToken | null = null;
  private lookupDefinitions: string[] = [];
  private reviews: ReviewItem[] = [];
  private wordSenses: WordSenseSummary[] = [];
  private reviewRevealed = false;
  private reviewStartedAt = Date.now();
  private readonly mediaCache = new MediaCache();
  private offlineRepository: OfflineRepository | null = null;
  private pendingSyncCount = 0;
  private syncIssueCount = 0;
  private syncIssues: OfflineSyncIssue[] = [];
  private syncing = false;
  private serverReachable = navigator.onLine;
  private usingOfflineSnapshot = false;
  private summary: DailySummary | null = null;
  private notice = '';
  private error = '';
  private pendingTextImport: { key: string; actionId: string } | null = null;

  constructor(private readonly root: HTMLElement) {
    window.addEventListener('online', () => void this.flushQueue(true));
    window.addEventListener('offline', () => {
      this.setServerReachable(false);
    });
  }

  async start(): Promise<void> {
    this.renderSplash();
    const serverUrl = await loadServerUrl();
    const token = await loadToken();
    if (!serverUrl || !token) {
      this.renderLogin(serverUrl);
      return;
    }
    try {
      this.api = new MobileApiClient(serverUrl);
      this.api.setToken(token);
      this.bootstrap = await this.api.bootstrap();
      await this.cacheBootstrap();
      this.setServerReachable(true);
      await this.activateOfflineRepository();
      await this.flushQueue();
      this.renderShell();
      await this.openHome();
    } catch (startError) {
      this.noteNetworkFailure(startError);
      if (
        startError instanceof MobileApiError
        && (startError.status === 401 || startError.code === 'DEVICE_REVOKED')
      ) {
        await clearToken();
        this.renderLogin(serverUrl, '登录已失效，请重新登录');
        return;
      }
      const cachedBootstrap = await loadCachedBootstrap();
      if (this.isNetworkFailure(startError) && cachedBootstrap) {
        this.bootstrap = cachedBootstrap;
        await this.activateOfflineRepository();
        this.renderShell();
        await this.openHome();
        return;
      }
      this.renderReconnect(serverUrl, message(startError));
    }
  }

  private renderSplash(): void {
    this.root.innerHTML = `
      <main class="splash" aria-busy="true">
        <div class="brand-mark">L</div>
        <h1>LinguaCafe</h1>
        <p>连接你的英语学习资料</p>
      </main>`;
  }

  private renderLogin(serverUrl = '', error = ''): void {
    const showLocalHttpWarning = usesLocalDevelopmentHttp(serverUrl);
    this.root.innerHTML = `
      <main class="login-page">
        <section class="login-card">
          <div class="brand-mark">L</div>
          <p class="eyebrow">${platformLabel()} · CONNECTED MVP</p>
          <h1>继续学习</h1>
          <p class="muted">连接你的 LinguaCafe 服务器。密码只用于换取设备令牌。</p>
          ${error ? `<div class="alert error" role="alert">${escapeHtml(error)}</div>` : ''}
          <form id="login-form">
            <label>服务器地址
              <input name="server" type="url" required placeholder="https://learn.example.com"
                value="${escapeHtml(serverUrl)}" autocomplete="url" />
            </label>
            <div class="alert warning" id="local-http-warning" role="note"
              ${showLocalHttpWarning ? '' : 'hidden'}>${LOCAL_HTTP_WARNING}</div>
            <label>邮箱
              <input name="email" type="email" required autocomplete="username" />
            </label>
            <label>密码
              <input name="password" type="password" required autocomplete="current-password" />
            </label>
            <button class="primary large" type="submit">安全登录</button>
          </form>
          <p class="privacy-note">设备令牌由系统 ${secureStorageLabel()} 保护；应用不会保存密码。</p>
        </section>
      </main>`;

    const serverInput = this.root.querySelector<HTMLInputElement>('input[name=server]');
    const localHttpWarning = this.root.querySelector<HTMLElement>('#local-http-warning');
    serverInput?.addEventListener('input', () => {
      if (localHttpWarning) localHttpWarning.hidden = !usesLocalDevelopmentHttp(serverInput.value);
    });
    this.root.querySelector<HTMLFormElement>('#login-form')?.addEventListener('submit', event => {
      event.preventDefault();
      void this.login(new FormData(event.currentTarget as HTMLFormElement));
    });
  }

  private renderReconnect(serverUrl: string, error: string): void {
    this.root.innerHTML = `
      <main class="login-page">
        <section class="login-card reconnect-card">
          <div class="brand-mark">L</div>
          <p class="eyebrow">CONNECTION</p>
          <h1>暂时无法连接</h1>
          <p class="muted">设备令牌仍安全保存在本机。网络恢复后可以直接重试。</p>
          <div class="alert error" role="alert">${escapeHtml(error)}</div>
          <button class="primary large" id="retry-connection">重新连接</button>
          <button class="text-button" id="change-account">更换服务器或账户</button>
        </section>
      </main>`;
    this.root.querySelector('#retry-connection')?.addEventListener('click', () => void this.start());
    this.root.querySelector('#change-account')?.addEventListener('click', () => {
      void clearToken().then(() => this.renderLogin(serverUrl));
    });
  }

  private async login(form: FormData): Promise<void> {
    const server = String(form.get('server') ?? '');
    const email = String(form.get('email') ?? '');
    const password = String(form.get('password') ?? '');
    this.renderLogin(server);
    const submit = this.root.querySelector<HTMLButtonElement>('button[type=submit]');
    if (submit) {
      submit.disabled = true;
      submit.textContent = '正在连接…';
    }
    try {
      const baseUrl = normalizeServerUrl(server);
      const deviceUuid = await getOrCreateDeviceUuid();
      this.api = new MobileApiClient(baseUrl);
      const result = await this.api.login({
        email,
        password,
        device_uuid: deviceUuid,
        platform: apiPlatform(),
        device_name: platformDeviceName(),
        app_version: '1.0.0',
      });
      await Promise.all([saveServerUrl(baseUrl), saveToken(result.token)]);
      this.api.setToken(result.token);
      this.bootstrap = await this.api.bootstrap();
      await this.cacheBootstrap();
      this.setServerReachable(true);
      await this.activateOfflineRepository();
      await this.flushQueue();
      this.renderShell();
      await this.openHome();
    } catch (loginError) {
      this.noteNetworkFailure(loginError);
      this.renderLogin(server, message(loginError));
    }
  }

  private renderShell(): void {
    const connected = navigator.onLine && this.serverReachable;
    this.root.innerHTML = `
      <div class="app-shell">
        <header class="topbar">
          <button class="topbar-home" id="open-home" aria-label="首页">
            <span class="mini-mark">L</span><strong>LinguaCafe</strong>
          </button>
          <div class="connection-summary">
            <span class="connection-pill ${connected ? 'online' : 'offline'}">
              ${connected ? (this.syncing ? '同步中' : '在线') : (navigator.onLine ? '服务器不可达' : '离线')}
              ${this.pendingSyncCount ? ` · ${this.pendingSyncCount} 待同步` : ''}
              ${this.syncIssueCount ? ` · ${this.syncIssueCount} 冲突` : ''}
            </span>
            <span class="language-pill">${escapeHtml(this.bootstrap?.current_language)}</span>
          </div>
        </header>
        <main id="screen" tabindex="-1"></main>
        <nav class="bottom-nav" aria-label="主要导航">
          ${this.navButton('library', '阅读', '⌁')}
          ${this.navButton('review', '复习', '◈')}
          ${this.navButton('vocabulary', '生词', 'Aa')}
          ${this.navButton('settings', '我的', '○')}
        </nav>
      </div>`;
    this.root.querySelectorAll<HTMLButtonElement>('[data-screen]').forEach(button => {
      button.addEventListener('click', () => void this.openScreen(button.dataset.screen as Screen));
    });
    this.root.querySelector('#open-home')?.addEventListener('click', () => void this.openScreen('home'));
  }

  private navButton(screen: Screen, label: string, icon: string): string {
    return `<button data-screen="${screen}" class="${this.screen === screen ? 'active' : ''}">
      <span aria-hidden="true">${icon}</span><small>${label}</small>
    </button>`;
  }

  private async openScreen(screen: Screen): Promise<void> {
    this.screen = screen;
    this.renderShell();
    if (screen === 'home') await this.openHome();
    if (screen === 'library') await this.openLibrary();
    if (screen === 'review') await this.openReview();
    if (screen === 'vocabulary') await this.openVocabulary();
    if (screen === 'settings') await this.openSettings();
  }

  private screenElement(): HTMLElement {
    const element = this.root.querySelector<HTMLElement>('#screen');
    if (!element) throw new Error('Screen root is missing');
    return element;
  }

  private setBusy(title: string): void {
    this.loading = true;
    this.screenElement().innerHTML = `<section class="screen loading" aria-busy="true">
      <div class="spinner"></div><p>${escapeHtml(title)}</p>
    </section>`;
  }

  private async openLibrary(): Promise<void> {
    if (!this.api) return;
    this.screen = 'library';
    this.setBusy('正在读取文章…');
    try {
      this.articles = await this.api.articles();
      this.setServerReachable(true);
      await this.saveOffline(() => this.offlineRepository?.saveArticles(this.articles));
      this.usingOfflineSnapshot = false;
      this.renderLibrary();
    } catch (error) {
      const cached = this.noteNetworkFailure(error) ? await this.offlineRepository?.articles() : null;
      if (cached) {
        this.articles = cached;
        this.usingOfflineSnapshot = true;
        this.renderLibrary();
      } else {
        this.renderError('无法读取文章', error, () => this.openLibrary());
      }
    }
  }

  private renderLibrary(): void {
    this.loading = false;
    const content = this.articles.length
      ? this.articles.map(article => `
          <button class="list-card article-card" data-book="${article.book_id}">
            <span class="book-icon">Aa</span>
            <span><strong>${escapeHtml(article.name)}</strong>
              <small>${article.chapter_count} 个章节</small></span>
            <span class="chevron">›</span>
          </button>`).join('')
      : '<div class="empty"><span>⌁</span><h2>暂无可读文章</h2><p>先在网页端导入并处理英文材料。</p></div>';
    this.screenElement().innerHTML = `
      <section class="screen">
        <p class="eyebrow">CONNECTED LIBRARY</p>
        <h1>选择一篇文章</h1>
        <p class="muted">${this.usingOfflineSnapshot
          ? '当前使用本机保存的短期离线文章包。恢复联网后会自动刷新。'
          : '文章目录和打开过的章节会在本机短期保存，供离线继续阅读。'}</p>
        <div class="stack">${content}</div>
      </section>`;
    this.screenElement().querySelectorAll<HTMLButtonElement>('[data-book]').forEach(button => {
      button.addEventListener('click', () => {
        const book = this.articles.find(item => item.book_id === Number(button.dataset.book));
        if (book) void this.openBook(book);
      });
    });
  }

  private async openBook(book: ArticleSummary): Promise<void> {
    if (!this.api) return;
    this.selectedBook = book;
    this.setBusy('正在读取章节…');
    try {
      this.chapters = await this.api.chapters(book.book_id);
      this.setServerReachable(true);
      await this.saveOffline(() => this.offlineRepository?.saveChapters(book.book_id, this.chapters));
      this.usingOfflineSnapshot = false;
      this.renderChapters(book);
    } catch (error) {
      const cached = this.noteNetworkFailure(error)
        ? await this.offlineRepository?.chapters(book.book_id)
        : null;
      if (cached) {
        this.chapters = cached;
        this.usingOfflineSnapshot = true;
        this.renderChapters(book);
      } else {
        this.renderError('无法读取章节', error, () => this.openBook(book));
      }
    }
  }

  private renderChapters(book: ArticleSummary): void {
      this.screenElement().innerHTML = `
        <section class="screen">
          <button class="text-button" id="back-library">← 全部文章</button>
          <p class="eyebrow">ARTICLE</p>
          <h1>${escapeHtml(book.name)}</h1>
          ${this.usingOfflineSnapshot ? '<div class="offline-banner">离线文章包</div>' : ''}
          <div class="stack">${this.chapters.map(chapter => `
            <button class="list-card" data-chapter="${chapter.chapter_id}">
              <span class="chapter-number">${chapter.chapter_id}</span>
              <span><strong>${escapeHtml(chapter.name)}</strong>
                <small>${chapter.token_count} tokens</small></span>
              <span class="chevron">›</span>
            </button>`).join('')}</div>
        </section>`;
      this.screenElement().querySelector('#back-library')?.addEventListener('click', () => this.renderLibrary());
      this.screenElement().querySelectorAll<HTMLButtonElement>('[data-chapter]').forEach(button => {
        button.addEventListener('click', () => {
          const chapter = this.chapters.find(item => item.chapter_id === Number(button.dataset.chapter));
          if (chapter) void this.openChapter(chapter);
        });
      });
  }

  private async openChapter(chapter: ChapterSummary): Promise<void> {
    if (!this.api || !this.selectedBook) return;
    const bookId = this.selectedBook.book_id;
    this.selectedChapter = chapter;
    this.setBusy('正在打开阅读器…');
    try {
      this.readerTokens = await this.api.chapterTokens(bookId, chapter.chapter_id);
      this.setServerReachable(true);
      await this.saveOffline(() => this.offlineRepository?.saveChapterTokens(
        bookId,
        chapter.chapter_id,
        this.readerTokens,
      ));
      this.usingOfflineSnapshot = false;
      this.renderReader();
    } catch (error) {
      const cached = this.noteNetworkFailure(error)
        ? await this.offlineRepository?.chapterTokens(bookId, chapter.chapter_id)
        : null;
      if (cached) {
        this.readerTokens = cached;
        this.usingOfflineSnapshot = true;
        this.renderReader();
      } else {
        this.renderError('无法打开章节', error, () => this.openChapter(chapter));
      }
    }
  }

  private renderReader(): void {
    const tokens = this.readerTokens.map((token, index) => {
      if (token.is_structure) {
        return token.word === 'PARAGRAPH_BREAK' ? '<span class="paragraph-break"></span>' : '<br />';
      }
      return `<button class="reader-token" data-token="${index}">${escapeHtml(token.word)}</button>${token.space_after ? ' ' : ''}`;
    }).join('');
    this.screenElement().innerHTML = `
      <section class="reader-screen">
        <header class="reader-header">
          <button class="text-button" id="back-chapters">← 章节</button>
          <div><small>${escapeHtml(this.selectedBook?.name)}</small>
            <strong>${escapeHtml(this.selectedChapter?.name)}</strong></div>
        </header>
        ${this.usingOfflineSnapshot ? '<div class="offline-banner reader-offline">离线文章包</div>' : ''}
        <article class="reader-copy">${tokens}</article>
        <p class="reader-hint">轻点单词查本地词典并可创建词义。</p>
      </section>`;
    this.screenElement().querySelector('#back-chapters')?.addEventListener('click', () => {
      if (this.selectedBook) void this.openBook(this.selectedBook);
    });
    this.screenElement().querySelectorAll<HTMLButtonElement>('[data-token]').forEach(button => {
      button.addEventListener('click', () => {
        const token = this.readerTokens[Number(button.dataset.token)];
        if (token) void this.openLookup(token);
      });
    });
  }

  private async openLookup(token: ReaderToken): Promise<void> {
    if (!this.api) return;
    this.lookupToken = token;
    this.lookupDefinitions = [];
    this.renderLookup(true);
    try {
      const result = await this.api.dictionary(token.lemma || token.word);
      this.setServerReachable(true);
      this.lookupDefinitions = result.definitions;
      this.renderLookup(false);
    } catch (error) {
      this.noteNetworkFailure(error);
      this.renderLookup(false, message(error));
    }
  }

  private renderLookup(busy: boolean, lookupError = ''): void {
    const token = this.lookupToken;
    if (!token) return;
    const firstDefinition = this.lookupDefinitions[0] ?? '';
    const panel = document.createElement('div');
    panel.className = 'sheet-backdrop';
    panel.innerHTML = `
      <section class="lookup-sheet" role="dialog" aria-modal="true" aria-labelledby="lookup-title">
        <div class="sheet-grip"></div>
        <header><div><p class="eyebrow">LOCAL DICTIONARY</p>
          <h2 id="lookup-title">${escapeHtml(token.word)}</h2>
          <small>${escapeHtml(token.lemma || token.word)} · ${escapeHtml(token.pos || 'other')}</small></div>
          <button class="icon-button" id="close-lookup" aria-label="关闭">×</button>
        </header>
        ${busy ? '<div class="inline-loading">正在查词…</div>' : ''}
        ${lookupError ? `<div class="alert error">${escapeHtml(lookupError)}</div>` : ''}
        ${this.lookupDefinitions.length ? `<ul class="definitions">${this.lookupDefinitions
          .map(definition => `<li>${escapeHtml(definition)}</li>`).join('')}</ul>` :
          (!busy && !lookupError ? '<p class="muted">本地词典没有找到释义。</p>' : '')}
        <form id="create-sense-form">
          <h3>创建学习词义</h3>
          <label>词性<select name="pos">${[
            'noun', 'verb', 'adjective', 'adverb', 'preposition',
            'conjunction', 'phrase', 'other',
          ].map(pos => `<option ${pos === token.pos ? 'selected' : ''}>${pos}</option>`).join('')}</select></label>
          <label>中文词义<input name="sense_zh" required maxlength="1000"
            value="${escapeHtml(firstDefinition)}" placeholder="输入你确认的中文词义" /></label>
          <button class="primary" type="submit">确认并创建</button>
        </form>
      </section>`;
    this.root.querySelector('.sheet-backdrop')?.remove();
    this.root.append(panel);
    panel.querySelector('#close-lookup')?.addEventListener('click', () => panel.remove());
    panel.addEventListener('click', event => {
      if (event.target === panel) panel.remove();
    });
    panel.querySelector<HTMLFormElement>('#create-sense-form')?.addEventListener('submit', event => {
      event.preventDefault();
      void this.createSense(new FormData(event.currentTarget as HTMLFormElement), panel);
    });
  }

  private async createSense(form: FormData, panel: HTMLElement): Promise<void> {
    if (!this.api || !this.lookupToken) return;
    const button = panel.querySelector<HTMLButtonElement>('button[type=submit]');
    if (button) {
      button.disabled = true;
      button.textContent = '正在创建…';
    }
    try {
      await this.api.createSense({
        lemma: this.lookupToken.lemma || this.lookupToken.word,
        surface_form: this.lookupToken.word,
        pos: String(form.get('pos') || 'other'),
        sense_zh: String(form.get('sense_zh') || ''),
        chapter_id: this.selectedChapter?.chapter_id,
        sentence_id: this.lookupToken.source_sentence_identity,
        sentence_en: this.sentenceForToken(this.lookupToken),
      });
      this.setServerReachable(true);
      panel.remove();
      this.showToast('词义已创建，将进入正式复习队列');
    } catch (error) {
      this.noteNetworkFailure(error);
      if (button) {
        button.disabled = false;
        button.textContent = '确认并创建';
      }
      const formElement = panel.querySelector('form');
      formElement?.insertAdjacentHTML(
        'afterbegin',
        `<div class="alert error" role="alert">${escapeHtml(message(error))}</div>`,
      );
    }
  }

  private sentenceForToken(token: ReaderToken): string {
    const identity = token.source_sentence_identity;
    if (identity === null) return '';
    return this.readerTokens
      .filter(candidate => (
        !candidate.is_structure
        && String(candidate.source_sentence_identity) === String(identity)
      ))
      .map(candidate => `${candidate.word}${candidate.space_after ? ' ' : ''}`)
      .join('')
      .trim();
  }

  private async openReview(): Promise<void> {
    if (!this.api) return;
    this.screen = 'review';
    this.setBusy('正在准备复习…');
    await this.flushQueue();
    try {
      this.reviews = await this.api.reviews();
      this.setServerReachable(true);
      await this.saveOffline(() => this.offlineRepository?.saveReviews(this.reviews));
      this.usingOfflineSnapshot = false;
      await this.removePendingReviews();
      this.reviewRevealed = false;
      this.reviewStartedAt = Date.now();
      this.renderReview();
    } catch (error) {
      const cached = this.noteNetworkFailure(error) ? await this.offlineRepository?.reviews() : null;
      if (cached) {
        this.reviews = cached;
        this.usingOfflineSnapshot = true;
        await this.removePendingReviews();
        this.reviewRevealed = false;
        this.reviewStartedAt = Date.now();
        this.renderReview();
      } else {
        this.renderError('无法读取复习队列', error, () => this.openReview());
      }
    }
  }

  private renderReview(): void {
    const item = this.reviews[0];
    if (!item) {
      this.screenElement().innerHTML = `
        <section class="screen"><div class="empty complete"><span>✓</span>
          <h1>今天到这里</h1><p>当前没有到期的词义卡。</p>
          <button class="secondary" id="review-summary">查看今日进度</button>
        </div></section>`;
      this.screenElement().querySelector('#review-summary')
        ?.addEventListener('click', () => void this.openScreen('home'));
      return;
    }
    const display = item.display;
    this.screenElement().innerHTML = `
      <section class="review-screen">
        ${this.usingOfflineSnapshot ? '<div class="offline-banner">离线复习包 · 评分会排队同步</div>' : ''}
        <div class="review-progress"><span style="width:${Math.min(100, 100 / this.reviews.length)}%"></span></div>
        <p class="eyebrow">SENSE REVIEW · ${this.reviews.length} REMAINING</p>
        <article class="study-card">
          <p class="pos">${escapeHtml(display.pos || 'sense')}</p>
          <h1>${escapeHtml(display.lemma)}</h1>
          ${display.example_sentence_en ? `<blockquote>${escapeHtml(display.example_sentence_en)}</blockquote>` : ''}
          <div class="review-audio-actions" aria-label="发音">
            <button class="text-button" data-audio-role="word_pronunciation">🔊 词发音</button>
            <button class="text-button" data-audio-role="example_audio">🔊 例句</button>
          </div>
          ${this.reviewRevealed ? `<div class="answer">
            <h2>${escapeHtml(display.sense_zh || '暂无中文词义')}</h2>
            ${display.sense_en ? `<p>${escapeHtml(display.sense_en)}</p>` : ''}
            ${display.example_sentence_zh ? `<p class="translation">${escapeHtml(display.example_sentence_zh)}</p>` : ''}
          </div>` : ''}
        </article>
        ${this.reviewRevealed ? `<div class="rating-grid" aria-label="评分">
          ${REVIEW_RATINGS.map((rating, index) =>
            `<button data-rating="${rating}" class="rating ${rating}">
              <strong>${['重来', '困难', '良好', '简单'][index]}</strong>
              <small>${['Again', 'Hard', 'Good', 'Easy'][index]}</small>
            </button>`).join('')}
        </div>` : '<button class="primary large reveal" id="reveal-answer">显示答案</button>'}
        <button class="text-button undo" id="undo-latest">↶ 撤回上一次评分</button>
      </section>`;
    this.screenElement().querySelector('#reveal-answer')?.addEventListener('click', () => {
      this.reviewRevealed = true;
      this.renderReview();
    });
    this.screenElement().querySelectorAll<HTMLButtonElement>('[data-rating]').forEach(button => {
      button.addEventListener('click', () => {
        const rating = button.dataset.rating;
        if (isReviewRating(rating)) void this.rateCurrent(rating);
      });
    });
    this.screenElement().querySelectorAll<HTMLButtonElement>('[data-audio-role]').forEach(button => {
      button.addEventListener('click', () => void this.playReviewAudio(
        button.dataset.audioRole as 'word_pronunciation' | 'example_audio',
      ));
    });
    this.screenElement().querySelector('#undo-latest')?.addEventListener('click', () => void this.undoLatest());
  }

  private async rateCurrent(rating: ReviewRating): Promise<void> {
    if (!this.api || !this.offlineRepository || !this.reviews[0]) return;
    const card = this.reviews[0];
    this.screenElement().querySelectorAll<HTMLButtonElement>('[data-rating]').forEach(button => {
      button.disabled = true;
    });
    try {
      await this.offlineRepository.enqueueRating(
        card.review_card_id,
        rating,
        Date.now() - this.reviewStartedAt,
      );
      await this.pulseRatingHaptic();
      this.reviews.shift();
      await this.offlineRepository.saveReviews(this.reviews);
      this.reviewRevealed = false;
      this.reviewStartedAt = Date.now();
      await this.refreshSyncStatus();
      this.renderReview();
      await this.flushQueue(true);
    } catch (error) {
      this.showToast(message(error), true);
      this.renderReview();
    }
  }

  private async pulseRatingHaptic(): Promise<void> {
    try {
      await Haptics.impact({ style: ImpactStyle.Medium });
    } catch {
      // Feedback is assistive; it must never block or duplicate a rating.
    }
  }

  private async playReviewAudio(role: 'word_pronunciation' | 'example_audio'): Promise<void> {
    if (!this.api || !this.reviews[0]) return;
    const display = this.reviews[0].display;
    const reference = (display.media ?? []).find(item => item.role === role);
    if (!reference) {
      const text = role === 'word_pronunciation' ? display.lemma : display.example_sentence_en;
      if (text && 'speechSynthesis' in window) {
        const utterance = new SpeechSynthesisUtterance(text);
        utterance.lang = 'en-US';
        window.speechSynthesis.speak(utterance);
        return;
      }
      this.showToast('没有可用附件；可在网页复习页上传 MP3/M4A。', true);
      return;
    }
    try {
      let blob = await this.mediaCache.get(reference);
      if (!blob) {
        blob = await this.api.downloadMedia(reference.asset_id);
        await this.mediaCache.put(reference, blob);
      }
      const url = URL.createObjectURL(blob);
      const audio = new Audio(url);
      const cleanup = () => URL.revokeObjectURL(url);
      audio.addEventListener('ended', cleanup, { once: true });
      audio.addEventListener('error', cleanup, { once: true });
      try {
        await audio.play();
      } catch (error) {
        cleanup();
        throw error;
      }
    } catch (error) {
      this.showToast(message(error), true);
    }
  }

  private async undoLatest(): Promise<void> {
    if (!this.api) return;
    try {
      const operation = await this.api.latestUndoableOperation();
      if (!operation) {
        this.showToast('没有可撤回的移动评分', true);
        return;
      }
      await this.api.undo(operation.operation_id, operation.version);
      this.showToast('已撤回上一次评分');
      await this.openReview();
    } catch (error) {
      this.showToast(message(error), true);
    }
  }

  private async openHome(): Promise<void> {
    if (!this.api) return;
    this.screen = 'home';
    this.setBusy('正在汇总今日进度…');
    try {
      this.summary = await this.api.summary();
      this.setServerReachable(true);
      const data = this.summary;
      this.screenElement().innerHTML = `
        <section class="screen">
          <p class="eyebrow">TODAY</p><h1>首页</h1>
          <div class="hero-stat"><strong>${data.today.reviewed_today_count}</strong><span>今日复习</span></div>
          <div class="stats-grid">
            <article><strong>${data.due_now_count}</strong><span>当前到期</span></article>
            <article><strong>${data.today.introduced_today_count}</strong><span>今日新学</span></article>
            <article><strong>${data.active_card_count}</strong><span>活跃词义卡</span></article>
          </div>
          <p class="muted timestamp">更新于 ${escapeHtml(new Date(data.generated_at).toLocaleTimeString())}</p>
          <button class="primary large" id="start-review">开始复习</button>
        </section>`;
      this.screenElement().querySelector('#start-review')
        ?.addEventListener('click', () => void this.openScreen('review'));
    } catch (error) {
      this.noteNetworkFailure(error);
      this.renderError('无法读取今日进度', error, () => this.openHome());
    }
  }

  private async openVocabulary(): Promise<void> {
    if (!this.api) return;
    this.screen = 'vocabulary';
    this.setBusy('正在读取生词…');
    try {
      this.wordSenses = await this.api.wordSenses();
      this.setServerReachable(true);
      const content = this.wordSenses.length
        ? this.wordSenses.map(sense => `
            <article class="list-card vocabulary-card">
              <span class="book-icon">Aa</span>
              <span><strong>${escapeHtml(sense.lemma)}</strong>
                <small>${escapeHtml([sense.pos, sense.sense_zh || sense.sense_en].filter(Boolean).join(' · '))}</small>
              </span>
            </article>`).join('')
        : '<div class="empty"><span>Aa</span><h2>还没有生词</h2><p>阅读时保存的已确认词义会出现在这里。</p></div>';
      this.screenElement().innerHTML = `
        <section class="screen">
          <p class="eyebrow">VOCABULARY</p><h1>生词</h1>
          <p class="muted">按英文词形浏览已保存的词义。</p>
          <div class="stack">${content}</div>
        </section>`;
    } catch (error) {
      this.noteNetworkFailure(error);
      this.renderError('无法读取生词', error, () => this.openVocabulary());
    }
  }

  private async openSettings(): Promise<void> {
    this.screen = 'settings';
    const [serverUrl, reminderHour] = await Promise.all([loadServerUrl(), loadReminderHour()]);
    this.screenElement().innerHTML = `
      <section class="screen">
        <p class="eyebrow">ACCOUNT</p><h1>我的</h1>
        <div class="settings-card">
          <h2>${escapeHtml(this.bootstrap?.user.name)}</h2>
          <p>${escapeHtml(this.bootstrap?.user.email)}</p>
          <p class="muted">${escapeHtml(this.bootstrap?.current_language)} · 已连接</p>
        </div>
        <form id="reminder-form" class="settings-card">
          <h2>本地复习提醒</h2>
          <label>每天提醒时间
            <select name="hour">${Array.from({ length: 24 }, (_, hour) =>
              `<option value="${hour}" ${hour === reminderHour ? 'selected' : ''}>${String(hour).padStart(2, '0')}:00</option>`,
            ).join('')}</select>
          </label>
          <button class="secondary" type="submit">保存提醒</button>
        </form>
        <div class="settings-card">
          <h2>离线同步</h2>
          <p>${this.pendingSyncCount} 个操作待同步；${this.syncIssueCount} 个操作需要查看。</p>
          <p class="muted">恢复联网后自动同步。冲突不会静默重试或改写服务器的新状态。</p>
          ${this.syncIssues.length ? `<ul class="sync-issues">${this.syncIssues.map(issue => (
            `<li><strong>${escapeHtml(issue.code)}</strong><span>${escapeHtml(issue.message)}</span></li>`
          )).join('')}</ul>` : ''}
          <button class="secondary" id="sync-now" ${this.syncing ? 'disabled' : ''}>立即同步</button>
          ${this.syncIssueCount ? '<button class="text-button" id="clear-sync-issues">已了解并清除冲突提示</button>' : ''}
        </div>
        ${Capacitor.getPlatform() === 'ios' ? `
        <form id="text-import-form" class="settings-card">
          <h2>从“文件”导入英文文本</h2>
          <p class="muted">选择一个 UTF-8 .txt 文件（不超过 200 KB）。导入内容仍由服务器处理和保存。</p>
          <label>文本文件
            <input name="document" type="file" accept=".txt,text/plain" required />
          </label>
          <label>资料名称
            <input name="book_name" type="text" maxlength="255" required />
          </label>
          <label>章节名称
            <input name="chapter_name" type="text" maxlength="255" value="导入文本" required />
          </label>
          <button class="secondary" type="submit">导入到服务器</button>
        </form>` : ''}
        <div class="settings-card"><h2>服务器</h2><p class="server-url">${escapeHtml(serverUrl)}</p></div>
        <button class="danger-outline large" id="logout">撤销此设备并退出</button>
      </section>`;
    this.screenElement().querySelector<HTMLFormElement>('#reminder-form')?.addEventListener('submit', event => {
      event.preventDefault();
      void this.saveReminder(new FormData(event.currentTarget as HTMLFormElement));
    });
    const importForm = this.screenElement().querySelector<HTMLFormElement>('#text-import-form');
    importForm?.querySelector<HTMLInputElement>('input[name=document]')?.addEventListener('change', event => {
      const file = (event.currentTarget as HTMLInputElement).files?.[0];
      const nameInput = importForm.querySelector<HTMLInputElement>('input[name=book_name]');
      if (file && nameInput && !nameInput.value) nameInput.value = file.name.replace(/\.txt$/i, '');
      this.pendingTextImport = null;
    });
    importForm?.addEventListener('input', event => {
      if ((event.target as HTMLInputElement).name !== 'document') this.pendingTextImport = null;
    });
    importForm?.addEventListener('submit', event => {
      event.preventDefault();
      void this.importTextDocument(event.currentTarget as HTMLFormElement);
    });
    this.screenElement().querySelector('#logout')?.addEventListener('click', () => void this.logout());
    this.screenElement().querySelector('#sync-now')?.addEventListener('click', () => void this.flushQueue(true));
    this.screenElement().querySelector('#clear-sync-issues')?.addEventListener('click', () => {
      void this.offlineRepository?.clearIssues().then(async () => {
        await this.refreshSyncStatus();
        await this.openSettings();
      });
    });
  }

  private async saveReminder(form: FormData): Promise<void> {
    try {
      await scheduleDailyReminder(Number(form.get('hour')));
      this.showToast('本地提醒已保存');
    } catch (error) {
      this.showToast(message(error), true);
    }
  }

  private async importTextDocument(form: HTMLFormElement): Promise<void> {
    if (!this.api) return;
    const data = new FormData(form);
    const file = data.get('document');
    const bookName = String(data.get('book_name') ?? '').trim();
    const chapterName = String(data.get('chapter_name') ?? '').trim();
    if (!(file instanceof File) || !file.name.toLowerCase().endsWith('.txt')) {
      this.showToast('请选择 .txt 文件', true);
      return;
    }
    if (file.size < 1 || file.size > 200000 || !bookName || !chapterName) {
      this.showToast('文件需为 1–200 KB，且资料和章节名称不能为空', true);
      return;
    }

    const submit = form.querySelector<HTMLButtonElement>('button[type=submit]');
    if (submit) submit.disabled = true;
    try {
      const bytes = await file.arrayBuffer();
      const content = new TextDecoder('utf-8', { fatal: true }).decode(bytes);
      if (!content.trim()) throw new Error('文本文件不能为空');
      const digest = Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256', bytes)))
        .map(value => value.toString(16).padStart(2, '0')).join('');
      const key = `${digest}|${bookName}|${chapterName}`;
      if (!this.pendingTextImport || this.pendingTextImport.key !== key) {
        this.pendingTextImport = { key, actionId: crypto.randomUUID() };
      }
      const result = await this.api.importText({
        client_action_id: this.pendingTextImport.actionId,
        file_name: file.name,
        content,
        book_name: bookName,
        chapter_name: chapterName,
      });
      this.pendingTextImport = null;
      this.setServerReachable(true);
      this.showToast(result.processing_mode === 'fallback'
        ? '已导入；服务器使用了基础英文分词'
        : '已导入，服务器正在处理章节');
      await this.openLibrary();
    } catch (error) {
      this.noteNetworkFailure(error);
      this.showToast(message(error), true);
    } finally {
      if (submit) submit.disabled = false;
    }
  }

  private async logout(): Promise<void> {
    const deviceUuid = this.bootstrap?.device.device_uuid;
    try {
      if (deviceUuid) await this.api?.revoke(deviceUuid);
    } catch {
      // Local credential deletion must still complete when the server is unavailable.
    }
    const cleanup = await Promise.allSettled([
      this.offlineRepository?.clear() ?? Promise.resolve(),
      this.mediaCache.clear(),
    ]);
    await clearToken();
    this.api = null;
    this.bootstrap = null;
    this.offlineRepository = null;
    this.renderLogin(
      await loadServerUrl(),
      cleanup.some(result => result.status === 'rejected')
        ? '已退出，但部分离线缓存未能清除；请在系统设置中清除应用数据'
        : '',
    );
  }

  private renderError(title: string, error: unknown, retry: () => void | Promise<void>): void {
    this.screenElement().innerHTML = `
      <section class="screen"><div class="empty error-state">
        <span>!</span><h1>${escapeHtml(title)}</h1><p>${escapeHtml(message(error))}</p>
        <button class="secondary" id="retry">重试</button>
      </div></section>`;
    this.screenElement().querySelector('#retry')?.addEventListener('click', () => void retry());
  }

  private showToast(text: string, isError = false): void {
    this.root.querySelector('.toast')?.remove();
    const toast = document.createElement('div');
    toast.className = `toast ${isError ? 'toast-error' : ''}`;
    toast.setAttribute('role', 'status');
    toast.textContent = text;
    this.root.append(toast);
    setTimeout(() => toast.remove(), 3200);
  }

  private async activateOfflineRepository(): Promise<void> {
    if (!this.bootstrap) return;
    try {
      this.offlineRepository = new OfflineRepository(
        this.bootstrap.user.id,
        this.bootstrap.current_language,
      );
      await this.refreshSyncStatus();
    } catch {
      this.offlineRepository = null;
      this.showToast('本机离线存储不可用；在线功能仍可继续', true);
    }
  }

  private async cacheBootstrap(): Promise<void> {
    if (!this.bootstrap) return;
    try {
      await saveCachedBootstrap(this.bootstrap);
    } catch {
      // Online use remains available when disposable offline metadata cannot persist.
    }
  }

  private async refreshSyncStatus(): Promise<void> {
    if (!this.offlineRepository) return;
    const [actions, issues] = await Promise.all([
      this.offlineRepository.queuedActions(),
      this.offlineRepository.issues(),
    ]);
    this.pendingSyncCount = actions.length;
    this.syncIssueCount = issues.length;
    this.syncIssues = issues;
  }

  private async removePendingReviews(): Promise<void> {
    const pending = await this.offlineRepository?.pendingCardIds();
    if (pending) this.reviews = this.reviews.filter(item => !pending.has(item.review_card_id));
  }

  private async flushQueue(announce = false): Promise<void> {
    if (!this.api || !this.offlineRepository || this.syncing || !navigator.onLine) return;
    const actions = (await this.offlineRepository.queuedActions()).slice(0, 100);
    if (!actions.length) {
      if (announce) this.showToast('没有待同步的离线操作');
      return;
    }
    this.syncing = true;
    try {
      const result = await this.api.syncActions(actions);
      this.setServerReachable(true);
      await this.offlineRepository.applySyncResults(result.results);
      await this.refreshSyncStatus();
      const terminal = result.results.filter(item => (
        item.outcome !== 'applied' && item.outcome !== 'replayed' && item.outcome !== 'retryable'
      )).length;
      if (terminal) this.showToast(`${terminal} 个离线操作发生冲突，请在设置中查看`, true);
      else if (announce) this.showToast(`已同步 ${result.counts.succeeded} 个操作`);
    } catch (error) {
      this.noteNetworkFailure(error);
      if (announce && !this.isNetworkFailure(error)) this.showToast(message(error), true);
    } finally {
      this.syncing = false;
      if (this.root.querySelector('.app-shell')) this.renderShellAndCurrentScreen();
    }
  }

  private renderShellAndCurrentScreen(): void {
    const screen = this.screen;
    this.renderShell();
    if (screen === 'library') this.renderLibrary();
    if (screen === 'review') this.renderReview();
    if (screen === 'home' && this.summary) void this.openHome();
    if (screen === 'vocabulary') void this.openVocabulary();
    if (screen === 'settings') void this.openSettings();
  }

  private isNetworkFailure(error: unknown): boolean {
    return error instanceof MobileApiError && error.code === 'NETWORK_ERROR';
  }

  private noteNetworkFailure(error: unknown): boolean {
    const networkFailure = this.isNetworkFailure(error);
    if (networkFailure) this.setServerReachable(false);
    return networkFailure;
  }

  private setServerReachable(reachable: boolean): void {
    this.serverReachable = reachable;
    const pill = this.root.querySelector<HTMLElement>('.connection-pill');
    if (!pill) return;
    const connected = navigator.onLine && reachable;
    pill.className = `connection-pill ${connected ? 'online' : 'offline'}`;
    pill.textContent = `${connected ? (this.syncing ? '同步中' : '在线') : (navigator.onLine ? '服务器不可达' : '离线')}`
      + `${this.pendingSyncCount ? ` · ${this.pendingSyncCount} 待同步` : ''}`
      + `${this.syncIssueCount ? ` · ${this.syncIssueCount} 冲突` : ''}`;
  }

  private async saveOffline(operation: () => Promise<void> | undefined): Promise<void> {
    try {
      await operation();
    } catch {
      this.showToast('无法更新本机离线包；在线内容仍可使用', true);
    }
  }
}
