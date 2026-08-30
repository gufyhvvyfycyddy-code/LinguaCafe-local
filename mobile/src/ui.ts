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
  ChapterPackage,
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
import { mobilePrivacyPolicyHtml } from './privacy';
import {
  movedBeyondReaderTap,
  readerPhrase,
  READER_LONG_PRESS_MS,
} from './readerTouchSelection';

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

function materialLabel(article: ArticleSummary): string {
  const type = {
    cet4: '四级真题',
    cet6: '六级真题',
    postgraduate_exam: '考研真题',
    personal: '我的材料',
  }[article.material_type ?? 'personal'];
  return [type, article.exam_year, article.exam_set ? `第 ${article.exam_set} 套` : null]
    .filter(value => value !== null && value !== undefined)
    .join(' · ');
}

function isScreen(value: unknown): value is Screen {
  return value === 'home' || value === 'library' || value === 'review'
    || value === 'vocabulary' || value === 'settings';
}

function syncIssueCopy(issue: OfflineSyncIssue): { title: string; message: string } {
  switch (issue.code) {
    case 'OUT_OF_ORDER_ACTION':
      return { title: '服务器已有更新', message: '这次离线操作早于服务器上的最新记录，因此没有覆盖新结果。' };
    case 'STALE_WORD_SENSE':
      return { title: '词义已经更新', message: '本机保存的是较早版本，因此没有覆盖服务器上的新内容。' };
    case 'WORD_SENSE_DELETED':
    case 'WORD_SENSE_NOT_FOUND':
    case 'REVIEW_CARD_NOT_FOUND':
      return { title: '内容已不可用', message: '相关词义或复习卡已发生变化，这次离线操作没有应用。' };
    case 'READING_SESSION_NOT_FOUND':
    case 'READING_SESSION_NOT_ACTIVE':
    case 'READING_SESSION_STALE_SOURCE':
    case 'READING_OCCURRENCE_STALE':
    case 'READING_CONTINUITY_CHAPTER_NOT_FOUND':
    case 'READING_CONTINUITY_STALE_SOURCE':
    case 'READING_CONTINUITY_INVALID_TOKEN':
      return { title: '阅读内容已经变化', message: '这次离线阅读操作不再适用于当前文章，服务器现有记录保持不变。' };
    case 'IDEMPOTENCY_KEY_REUSED':
      return { title: '操作内容不一致', message: '同一次重试包含了不同内容，因此没有重复提交。' };
    default:
      return { title: '操作未能应用', message: '服务器没有接受这次离线操作，现有数据保持不变。' };
  }
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
  private readerPackage: ChapterPackage | null = null;
  private selectedBook: ArticleSummary | null = null;
  private selectedChapter: ChapterSummary | null = null;
  private lookupToken: ReaderToken | null = null;
  private lookupDefinitions: string[] = [];
  private lookupReadingRating: 'again' | 'good' | null = null;
  private lookupHelpRevealed = false;
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
  private lookupHistoryOpen = false;
  private currentReaderCanonicalIndex: number | null = null;
  private lastQueuedReaderCanonicalIndex: number | null = null;
  private readerPositionTimer: number | null = null;
  private readerPositionCleanup: (() => void) | null = null;

  constructor(private readonly root: HTMLElement) {
    window.addEventListener('online', () => void this.flushQueue(true));
    window.addEventListener('offline', () => {
      this.setServerReachable(false);
    });
    window.addEventListener('popstate', event => {
      if (this.lookupHistoryOpen) {
        this.lookupHistoryOpen = false;
        this.root.querySelector('.sheet-backdrop')?.remove();
        return;
      }
      if (event.state?.linguacafeLookup) {
        history.back();
        return;
      }
      if (isScreen(event.state?.linguacafeScreen) && this.root.querySelector('.app-shell')) {
        void this.openScreen(event.state.linguacafeScreen, false);
      }
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
          ${mobilePrivacyPolicyHtml()}
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
    if (history.state?.linguacafeScreen !== this.screen || history.state?.linguacafeLookup) {
      history.replaceState({ linguacafeScreen: this.screen }, '');
    }
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
              ${this.syncIssueCount ? ` · ${this.syncIssueCount} 需处理` : ''}
            </span>
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

  private async openScreen(screen: Screen, recordHistory = true): Promise<void> {
    await this.queueCurrentReaderPosition();
    this.stopReaderPositionTracking();
    if (recordHistory && history.state?.linguacafeScreen !== screen) {
      history.pushState({ linguacafeScreen: screen }, '');
    }
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
              <small>${escapeHtml(materialLabel(article))} · ${article.chapter_count} 个章节</small>
              <small data-book-offline="${article.book_id}">正在检查离线状态…</small></span>
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
    void this.refreshLibraryOfflineStatus();
  }

  private async refreshLibraryOfflineStatus(): Promise<void> {
    if (!this.offlineRepository) return;
    await Promise.all(this.articles.map(async article => {
      const state = await this.offlineRepository!.bookDownloadState(article.book_id);
      const downloaded = Math.min(article.chapter_count, state.chapterIds.length);
      let copy = downloaded ? `已下载 ${downloaded}/${article.chapter_count} 章` : '未下载';
      if (state.contentVersion && article.content_version && state.contentVersion !== article.content_version) {
        copy = `有更新 · ${copy}`;
      } else if (state.contentVersion) {
        copy = '已下载整套 · 可离线打开';
      }
      const element = this.screenElement().querySelector<HTMLElement>(`[data-book-offline="${article.book_id}"]`);
      if (element) element.textContent = copy;
    }));
  }

  private async openBook(book: ArticleSummary): Promise<void> {
    if (!this.api) return;
    await this.queueCurrentReaderPosition();
    this.stopReaderPositionTracking();
    this.selectedChapter = null;
    this.readerPackage = null;
    this.readerTokens = [];
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
          <p class="muted">${escapeHtml(materialLabel(book))}</p>
          ${this.usingOfflineSnapshot ? '<div class="offline-banner">离线文章包</div>' : ''}
          <p class="muted" id="book-download-status">正在检查离线状态…</p>
          <button class="secondary large" id="download-book">下载整套</button>
          <div class="stack">${this.chapters.map(chapter => `
            <button class="list-card" data-chapter="${chapter.chapter_id}">
              <span class="chapter-number">${chapter.chapter_id}</span>
              <span><strong>${escapeHtml(chapter.name)}</strong>
                <small>${chapter.token_count} tokens</small>
                <small data-chapter-offline="${chapter.chapter_id}">正在检查离线状态…</small></span>
              <span class="chevron">›</span>
            </button>`).join('')}</div>
        </section>`;
      this.screenElement().querySelector('#back-library')?.addEventListener('click', () => this.renderLibrary());
      this.screenElement().querySelector('#download-book')?.addEventListener('click', () => void this.downloadBook(book));
      this.screenElement().querySelectorAll<HTMLButtonElement>('[data-chapter]').forEach(button => {
        button.addEventListener('click', () => {
          const chapter = this.chapters.find(item => item.chapter_id === Number(button.dataset.chapter));
          if (chapter) void this.openChapter(chapter);
        });
      });
      void this.refreshChapterOfflineStatus(book);
  }

  private async refreshChapterOfflineStatus(book: ArticleSummary): Promise<void> {
    if (!this.offlineRepository) return;
    const state = await this.offlineRepository.bookDownloadState(book.book_id);
    const downloaded = new Set(state.chapterIds);
    this.chapters.forEach(chapter => {
      const element = this.screenElement().querySelector<HTMLElement>(`[data-chapter-offline="${chapter.chapter_id}"]`);
      if (element) element.textContent = downloaded.has(chapter.chapter_id) ? '已下载 · 可离线' : '未下载';
    });
    const status = this.screenElement().querySelector<HTMLElement>('#book-download-status');
    if (!status) return;
    if (state.contentVersion && book.content_version && state.contentVersion !== book.content_version) {
      status.textContent = '本机整套已有新版本可更新';
    } else if (state.contentVersion) {
      status.textContent = '整套已下载，可离线打开';
    } else if (downloaded.size) {
      status.textContent = `已下载 ${Math.min(downloaded.size, this.chapters.length)}/${this.chapters.length} 章`;
    } else {
      status.textContent = '整套尚未下载';
    }
  }

  private async downloadBook(book: ArticleSummary): Promise<void> {
    if (!this.api || !this.offlineRepository) return;
    if (!book.content_version) {
      this.showToast('服务器未提供可下载的材料版本', true);
      return;
    }
    if (!this.chapters.length) {
      this.showToast('这套材料还没有可下载的章节', true);
      return;
    }
    if (!navigator.onLine || this.usingOfflineSnapshot) {
      this.showToast('下载整套需要联网', true);
      return;
    }
    const button = this.screenElement().querySelector<HTMLButtonElement>('#download-book');
    if (button) button.disabled = true;
    try {
      for (let index = 0; index < this.chapters.length; index++) {
        if (button) button.textContent = `正在下载 ${index + 1}/${this.chapters.length}`;
        const chapter = this.chapters[index];
        const articlePackage = await this.api.chapterPackage(book.book_id, chapter.chapter_id);
        await this.offlineRepository.saveChapterPackage(book.book_id, chapter.chapter_id, articlePackage);
      }
      await this.offlineRepository.markBookDownloaded(book.book_id, book.content_version);
      this.setServerReachable(true);
      await this.refreshChapterOfflineStatus(book);
      this.showToast('整套已下载，可离线打开');
    } catch (error) {
      this.noteNetworkFailure(error);
      this.showToast(message(error), true);
      await this.refreshChapterOfflineStatus(book);
    } finally {
      if (button) {
        button.disabled = false;
        button.textContent = '下载整套';
      }
    }
  }

  private async openChapter(chapter: ChapterSummary): Promise<void> {
    if (!this.api || !this.selectedBook) return;
    const bookId = this.selectedBook.book_id;
    await this.flushQueue();
    this.selectedChapter = chapter;
    this.setBusy('正在打开阅读器…');
    try {
      const cached = await this.offlineRepository?.chapterPackage(bookId, chapter.chapter_id);
      this.readerPackage = await this.api.chapterPackage(bookId, chapter.chapter_id);
      this.readerPackage.reading_session = await this.api.startReadingSession(
        chapter.chapter_id,
        cached?.reading_session?.status === 'active'
          ? cached.reading_session.reading_session_id
          : undefined,
      );
      if (!this.packageHasCurrentContinuity(this.readerPackage)) {
        throw new Error('文章包与阅读进度版本不一致，请重新打开章节。');
      }
      this.readerTokens = this.readerPackage.tokens;
      const pending = await this.offlineRepository?.pendingReadingPosition(
        chapter.chapter_id,
        this.readerPackage.source_revision,
      );
      this.currentReaderCanonicalIndex = pending?.payload.canonical_token_index
        ?? this.readerPackage.reading_session.continuity.resume?.canonical_token_index
        ?? null;
      this.lastQueuedReaderCanonicalIndex = this.currentReaderCanonicalIndex;
      this.setServerReachable(true);
      await this.saveOffline(() => this.offlineRepository?.saveChapterPackage(
        bookId,
        chapter.chapter_id,
        this.readerPackage!,
      ));
      this.usingOfflineSnapshot = false;
      this.renderReader();
    } catch (error) {
      const cached = this.noteNetworkFailure(error)
        ? await this.offlineRepository?.chapterPackage(bookId, chapter.chapter_id)
        : null;
      if (cached) {
        if (!this.packageHasCurrentContinuity(cached)) {
          this.renderError('无法打开离线章节', new Error('离线文章包与阅读进度版本不一致，请联网更新。'), () => this.openChapter(chapter));
          return;
        }
        this.readerPackage = cached;
        this.readerTokens = cached.tokens;
        const pending = await this.offlineRepository?.pendingReadingPosition(
          chapter.chapter_id,
          cached.source_revision,
        );
        this.currentReaderCanonicalIndex = pending?.payload.canonical_token_index
          ?? cached.reading_session.continuity.resume?.canonical_token_index
          ?? null;
        this.lastQueuedReaderCanonicalIndex = this.currentReaderCanonicalIndex;
        this.usingOfflineSnapshot = true;
        this.renderReader();
      } else {
        this.renderError('无法打开章节', error, () => this.openChapter(chapter));
      }
    }
  }

  private renderReader(): void {
    this.stopReaderPositionTracking();
    const tokens = this.readerTokens.map((token, index) => {
      if (token.is_structure) {
        return token.word === 'PARAGRAPH_BREAK' ? '<span class="paragraph-break"></span>' : '<br />';
      }
      const canonical = token.canonical_token_index === null
        ? ''
        : ` data-canonical-token="${token.canonical_token_index}"`;
      return `<button class="reader-token" data-token="${index}"${canonical}>${escapeHtml(token.word)}</button>${token.space_after ? ' ' : ''}`;
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
        <p class="reader-hint">轻点单词查本地词典；离线时使用文章包摘要。</p>
        <button class="primary large" id="finish-reading">完成阅读</button>
      </section>`;
    this.screenElement().querySelector('#back-chapters')?.addEventListener('click', () => {
      if (this.selectedBook) void (async () => {
        await this.queueCurrentReaderPosition();
        await this.openBook(this.selectedBook!);
      })();
    });
    const readerCopy = this.screenElement().querySelector<HTMLElement>('.reader-copy');
    if (readerCopy) readerCopy.style.touchAction = 'pan-y';
    const tokenButtons = [...(readerCopy?.querySelectorAll<HTMLButtonElement>('[data-token]') ?? [])];
    let suppressNextClick = false;
    let gesture: {
      pointerId: number;
      startIndex: number;
      endIndex: number;
      originX: number;
      originY: number;
      active: boolean;
      timer: number;
    } | null = null;
    const paintSelection = (startIndex?: number, endIndex?: number) => {
      tokenButtons.forEach(button => {
        const index = Number(button.dataset.token);
        const selected = startIndex !== undefined && endIndex !== undefined
          && index >= Math.min(startIndex, endIndex)
          && index <= Math.max(startIndex, endIndex);
        button.toggleAttribute('aria-pressed', selected);
        button.style.background = selected ? 'var(--green-soft)' : '';
        button.style.color = selected ? 'var(--green-deep)' : '';
      });
    };
    const cancelGesture = () => {
      if (gesture) window.clearTimeout(gesture.timer);
      gesture = null;
      paintSelection();
    };
    readerCopy?.addEventListener('pointerdown', event => {
      const button = (event.target as Element).closest<HTMLButtonElement>('[data-token]');
      if (!button || event.pointerType === 'mouse') return;
      readerCopy?.setPointerCapture(event.pointerId);
      const index = Number(button.dataset.token);
      gesture = {
        pointerId: event.pointerId,
        startIndex: index,
        endIndex: index,
        originX: event.clientX,
        originY: event.clientY,
        active: false,
        timer: window.setTimeout(() => {
          if (!gesture) return;
          gesture.active = true;
          paintSelection(gesture.startIndex, gesture.endIndex);
        }, READER_LONG_PRESS_MS),
      };
    });
    readerCopy?.addEventListener('pointermove', event => {
      if (!gesture || gesture.pointerId !== event.pointerId) return;
      if (!gesture.active) {
        if (movedBeyondReaderTap(gesture.originX, gesture.originY, event.clientX, event.clientY)) {
          suppressNextClick = true;
          window.setTimeout(() => { suppressNextClick = false; }, 500);
          cancelGesture();
        }
        return;
      }
      event.preventDefault();
      const button = document.elementFromPoint(event.clientX, event.clientY)
        ?.closest<HTMLButtonElement>('[data-token]');
      const index = Number(button?.dataset.token);
      if (button && readerPhrase(this.readerTokens, gesture.startIndex, index)) {
        gesture.endIndex = index;
        paintSelection(gesture.startIndex, gesture.endIndex);
      }
    });
    readerCopy?.addEventListener('pointerup', event => {
      if (!gesture || gesture.pointerId !== event.pointerId) return;
      const selected = gesture.active
        ? readerPhrase(this.readerTokens, gesture.startIndex, gesture.endIndex)
        : null;
      if (gesture.active) {
        event.preventDefault();
        suppressNextClick = true;
        window.setTimeout(() => { suppressNextClick = false; }, 0);
      }
      cancelGesture();
      if (selected) void this.openLookup(selected);
    });
    readerCopy?.addEventListener('pointercancel', cancelGesture);
    tokenButtons.forEach(button => {
      button.addEventListener('click', () => {
        if (suppressNextClick) return;
        const token = this.readerTokens[Number(button.dataset.token)];
        if (token) void this.openLookup(token);
      });
    });
    this.screenElement().querySelector('#finish-reading')
      ?.addEventListener('click', () => void this.finishReading());
    this.startReaderPositionTracking();
  }

  private async openLookup(token: ReaderToken): Promise<void> {
    if (!this.api) return;
    this.lookupToken = token;
    const term = (token.lemma || token.word).trim().toLocaleLowerCase('en-US');
    this.lookupDefinitions = [];
    this.lookupReadingRating = null;
    this.lookupHelpRevealed = false;
    await this.recordLookupOpened(token);
    if (!this.lookupHistoryOpen) {
      history.pushState({ linguacafeScreen: this.screen, linguacafeLookup: true }, '');
      this.lookupHistoryOpen = true;
    }

    const target = this.readingTargetForToken(token);
    const hasReviewCandidates = Boolean(target?.candidate_word_senses.some(candidate => candidate.review_card_id));
    if (hasReviewCandidates) {
      this.renderLookup(false);
      return;
    }

    await this.loadLookupAnswer(term);
  }

  private async loadLookupAnswer(term: string): Promise<void> {
    if (!this.api) return;
    if (this.usingOfflineSnapshot) {
      this.lookupDefinitions = this.readerPackage?.dictionary_summaries[term] ?? [];
      this.renderLookup(false, '', true);
      return;
    }

    this.renderLookup(true);
    try {
      const result = await this.api.dictionary(term);
      this.setServerReachable(true);
      this.lookupDefinitions = result.definitions;
      this.renderLookup(false);
    } catch (error) {
      if (this.noteNetworkFailure(error)) {
        this.lookupDefinitions = this.readerPackage?.dictionary_summaries[term] ?? [];
        this.renderLookup(false, '', true);
        return;
      }
      this.renderLookup(false, message(error));
    }
  }

  private async revealReadingLookup(rating: 'again' | 'good' | null): Promise<void> {
    const token = this.lookupToken;
    const session = this.readerPackage?.reading_session;
    const target = token ? this.readingTargetForToken(token) : null;
    if (!token || !target || !session || !this.offlineRepository) return;

    if (rating) {
      this.lookupReadingRating = rating;
      if (rating === 'again') {
        await this.offlineRepository.enqueueReadingInteraction(
          session.reading_session_id,
          target.occurrence_id,
          'marked_unknown',
        );
        if (!this.usingOfflineSnapshot) await this.flushQueue();
      }
    } else {
      this.lookupHelpRevealed = true;
      await this.offlineRepository.enqueueReadingInteraction(
        session.reading_session_id,
        target.occurrence_id,
        'helped',
      );
      if (!this.usingOfflineSnapshot) await this.flushQueue();
    }

    const term = (token.lemma || token.word).trim().toLocaleLowerCase('en-US');
    await this.loadLookupAnswer(term);
  }

  private renderLookup(busy: boolean, lookupError = '', usingPackageSummary = false): void {
    const token = this.lookupToken;
    if (!token) return;
    const firstDefinition = this.lookupDefinitions[0] ?? '';
    const target = this.readingTargetForToken(token);
    const reviewCandidates = target?.candidate_word_senses.filter(candidate => candidate.review_card_id) ?? [];
    const awaitingRecognition = reviewCandidates.length > 0
      && this.lookupReadingRating === null
      && !this.lookupHelpRevealed;
    const activeReadingRating: 'again' | 'good' | null = this.lookupReadingRating
      ?? (this.lookupHelpRevealed ? 'again' : null);
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
        ${awaitingRecognition ? `
          <div class="card">
            <p>先回想这个词在这里的意思，再选择你的真实情况。</p>
            <div class="rating-grid">
              <button type="button" data-reading-intent="good">认识 / 记得</button>
              <button type="button" data-reading-intent="again">不认识</button>
            </div>
            <button type="button" class="text-button" data-reading-help="true">查看答案</button>
          </div>` : `
          ${busy ? '<div class="inline-loading">正在查词…</div>' : ''}
          ${lookupError ? `<div class="alert error">${escapeHtml(lookupError)}</div>` : ''}
          ${usingPackageSummary ? '<div class="offline-banner">离线词典摘要</div>' : ''}
          ${this.lookupDefinitions.length ? `<ul class="definitions">${this.lookupDefinitions
            .map(definition => `<li>${escapeHtml(definition)}</li>`).join('')}</ul>` :
            (!busy && !lookupError ? `<p class="muted">${usingPackageSummary
              ? '文章包内没有找到词典摘要。'
              : '本地词典没有找到释义。'}</p>` : '')}
          ${reviewCandidates.map(candidate => `
            <section class="card">
              <strong>${escapeHtml(candidate.sense_zh || candidate.sense_en || token.word)}</strong>
              ${activeReadingRating ? `<button type="button" data-reading-card="${candidate.review_card_id}">
                ${activeReadingRating === 'good' ? '认识 / 记得' : '不认识'} · 确认这个词义
              </button>` : ''}
            </section>`).join('')}
          <form id="create-sense-form">
            <h3>创建学习词义</h3>
            <label>词性<select name="pos">${[
              'noun', 'verb', 'adjective', 'adverb', 'preposition',
              'conjunction', 'phrase', 'other',
            ].map(pos => `<option ${pos === token.pos ? 'selected' : ''}>${pos}</option>`).join('')}</select></label>
            <label>中文词义<input name="sense_zh" required maxlength="1000"
              value="${escapeHtml(firstDefinition)}" placeholder="输入你确认的中文词义" /></label>
            <button class="primary" type="submit">确认并创建</button>
          </form>`}
      </section>`;
    this.root.querySelector('.sheet-backdrop')?.remove();
    this.root.append(panel);
    panel.querySelector('#close-lookup')?.addEventListener('click', () => this.closeLookupSheet(panel));
    panel.addEventListener('click', event => {
      if (event.target === panel) this.closeLookupSheet(panel);
    });
    panel.querySelector<HTMLFormElement>('#create-sense-form')?.addEventListener('submit', event => {
      event.preventDefault();
      void this.createSense(new FormData(event.currentTarget as HTMLFormElement), panel);
    });
    panel.querySelectorAll<HTMLButtonElement>('[data-reading-intent]').forEach(button => {
      button.addEventListener('click', () => {
        const rating = button.dataset.readingIntent;
        if (rating === 'again' || rating === 'good') void this.revealReadingLookup(rating);
      });
    });
    panel.querySelector<HTMLButtonElement>('[data-reading-help]')?.addEventListener('click', () => {
      void this.revealReadingLookup(null);
    });
    panel.querySelectorAll<HTMLButtonElement>('[data-reading-card]').forEach(button => {
      button.addEventListener('click', () => {
        const reviewCardId = Number(button.dataset.readingCard);
        if (target && activeReadingRating && reviewCardId > 0) {
          void this.rateReadingTarget(target.occurrence_id, reviewCardId, activeReadingRating, panel);
        }
      });
    });
  }

  private closeLookupSheet(panel: HTMLElement): void {
    if (this.lookupHistoryOpen) history.back();
    else panel.remove();
  }

  private readingTargetForToken(token: ReaderToken) {
    if (token.canonical_token_index === null || token.selection_kind === 'phrase') return undefined;
    return this.readerPackage?.reading_session?.reading_targets.find(target => (
      token.canonical_token_index! >= target.start_word_index
      && token.canonical_token_index! <= target.end_word_index
    ));
  }

  private async recordLookupOpened(token: ReaderToken): Promise<void> {
    const session = this.readerPackage?.reading_session;
    const target = this.readingTargetForToken(token);
    if (!session || !target || !this.offlineRepository) return;
    await this.offlineRepository.enqueueReadingInteraction(
      session.reading_session_id,
      target.occurrence_id,
    );
    if (!this.usingOfflineSnapshot) await this.flushQueue();
  }

  private async rateReadingTarget(
    occurrenceId: string,
    reviewCardId: number,
    rating: 'again' | 'good',
    panel: HTMLElement,
  ): Promise<void> {
    const session = this.readerPackage?.reading_session;
    if (!session || !this.offlineRepository) return;
    await this.offlineRepository.enqueueRating(
      reviewCardId,
      rating,
      0,
      new Date(),
      { readingSessionId: session.reading_session_id, occurrenceId },
    );
    this.closeLookupSheet(panel);
    await this.flushQueue();
  }

  private async finishReading(): Promise<void> {
    const session = this.readerPackage?.reading_session;
    const chapterId = this.selectedChapter?.chapter_id;
    if (!this.api || !this.offlineRepository || !session || !chapterId) return;
    if (this.usingOfflineSnapshot || !navigator.onLine) {
      this.showToast('完成阅读需要联网，以便服务器确认最终结算。', true);
      return;
    }

    await this.queueCurrentReaderPosition();
    await this.flushQueue();
    if ((await this.offlineRepository.queuedActions()).length > 0) {
      this.showToast('仍有离线操作尚未同步，暂不能完成阅读。', true);
      return;
    }

    try {
      const preflight = await this.api.finishReadingSession(chapterId, session.reading_session_id, 'preflight');
      this.renderFinishConfirmation(preflight);
    } catch (error) {
      this.noteNetworkFailure(error);
      this.showToast(message(error), true);
    }
  }

  private renderFinishConfirmation(preflight: import('./types').ReadingFinishProjection): void {
    const panel = document.createElement('div');
    panel.className = 'sheet-backdrop';
    panel.innerHTML = `
      <section class="lookup-sheet" role="dialog" aria-modal="true" aria-labelledby="finish-title">
        <header><h2 id="finish-title">确认完成阅读</h2>
          <button class="icon-button" id="close-finish" aria-label="关闭">×</button></header>
        <p>服务器计划记录 ${preflight.passive_good_count} 张被动 Good 卡片。</p>
        ${preflight.unresolved_count > 0
          ? `<div class="alert error">仍有 ${preflight.unresolved_count} 个词义未解决，暂不能完成。</div>`
          : ''}
        <button class="primary large" id="commit-finish" ${preflight.can_commit ? '' : 'disabled'}>确认完成</button>
      </section>`;
    this.root.append(panel);
    panel.querySelector('#close-finish')?.addEventListener('click', () => panel.remove());
    panel.querySelector('#commit-finish')?.addEventListener('click', () => void this.commitReadingFinish(panel));
  }

  private async commitReadingFinish(panel: HTMLElement): Promise<void> {
    const session = this.readerPackage?.reading_session;
    const chapterId = this.selectedChapter?.chapter_id;
    if (!this.api || !session || !chapterId) return;
    try {
      const result = await this.api.finishReadingSession(chapterId, session.reading_session_id, 'commit');
      if (!result.completed) {
        this.showToast('服务器未完成阅读结算。', true);
        return;
      }
      session.status = 'completed';
      session.completed = true;
      panel.remove();
      this.showToast(`阅读已完成；服务器记录 ${result.passive_good_count} 张被动 Good 卡片。`);
    } catch (error) {
      this.noteNetworkFailure(error);
      this.showToast(message(error), true);
    }
  }

  private async ensureReadingSenseContext(token: ReaderToken): Promise<{
    reading_session_id: string;
    source_revision: string;
    occurrence_id: string;
  } | null> {
    const chapterId = this.selectedChapter?.chapter_id;
    const session = this.readerPackage?.reading_session;
    if (
      !this.api
      || !this.readerPackage
      || !chapterId
      || !session
      || token.canonical_token_index === null
      || token.selection_kind === 'phrase'
    ) return null;

    let target = this.readingTargetForToken(token);
    if (!target) {
      await this.api.markReadingUnfamiliarTarget(chapterId, {
        kind: 'word',
        start_word_index: token.canonical_token_index,
        end_word_index: token.canonical_token_index,
        source_revision: this.readerPackage.source_revision,
      });
      this.readerPackage.reading_session = await this.api.startReadingSession(
        chapterId,
        session.reading_session_id,
      );
      target = this.readingTargetForToken(token);
    }
    if (!target || target.kind !== 'word') {
      throw new Error('服务器没有返回当前单词的阅读标记，请刷新文章后重试。');
    }

    const refreshedSession = this.readerPackage.reading_session;
    if (!refreshedSession) {
      throw new Error('阅读会话已经失效，请重新打开文章。');
    }

    return {
      reading_session_id: refreshedSession.reading_session_id,
      source_revision: refreshedSession.source_revision,
      occurrence_id: target.occurrence_id,
    };
  }

  private async createSense(form: FormData, panel: HTMLElement): Promise<void> {
    if (!this.api || !this.lookupToken) return;
    const button = panel.querySelector<HTMLButtonElement>('button[type=submit]');
    if (button) {
      button.disabled = true;
      button.textContent = '正在创建…';
    }
    try {
      const readingContext = await this.ensureReadingSenseContext(this.lookupToken);
      const result = await this.api.createSense({
        lemma: this.lookupToken.lemma || this.lookupToken.word,
        surface_form: this.lookupToken.word,
        pos: String(form.get('pos') || 'other'),
        sense_zh: String(form.get('sense_zh') || ''),
        chapter_id: this.selectedChapter?.chapter_id,
        sentence_id: this.lookupToken.source_sentence_identity,
        sentence_en: this.sentenceForToken(this.lookupToken),
        ...(readingContext ?? {}),
      });
      if (readingContext) {
        const target = this.readerPackage?.reading_session?.reading_targets.find(
          item => item.occurrence_id === readingContext.occurrence_id,
        );
        if (target && !target.candidate_word_senses.some(
          candidate => candidate.word_sense_id === result.word_sense.sense_id,
        )) {
          target.candidate_word_senses.push({
            word_sense_id: result.word_sense.sense_id,
            review_card_id: result.word_sense.review_card_id,
            sense_zh: result.word_sense.sense_zh,
            sense_en: result.word_sense.sense_en,
            pos: result.word_sense.pos,
          });
        }
      }
      this.setServerReachable(true);
      this.closeLookupSheet(panel);
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
        new Date(),
        undefined,
        card.display.question_example_key ?? null,
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
          <p class="muted">已连接</p>
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
          <p>${this.pendingSyncCount} 个操作待同步；${this.syncIssueCount} 个操作需要处理。</p>
          <p class="muted">恢复联网后自动同步。暂时失败的操作会保留在本机；无法应用的操作不会覆盖服务器的新状态。</p>
          ${this.syncIssues.length ? `<ul class="sync-issues">${this.syncIssues.map(issue => {
            const copy = syncIssueCopy(issue);
            return `<li><strong>${escapeHtml(copy.title)}</strong><span>${escapeHtml(copy.message)}</span></li>`;
          }).join('')}</ul>` : ''}
          <button class="secondary" id="sync-now" ${this.syncing ? 'disabled' : ''}>立即同步</button>
          ${this.syncIssueCount ? '<button class="text-button" id="clear-sync-issues">已了解并清除提示</button>' : ''}
        </div>
        ${Capacitor.getPlatform() === 'ios' ? `
        <form id="text-import-form" class="settings-card">
          <h2>从“文件”导入英文文本</h2>
          <p class="muted">选择一个 UTF-8 .txt 文件（不超过 200 KB）。导入内容仍由服务器处理和保存。</p>
          <label>文本文件
            <input name="document" type="file" accept=".txt,text/plain" aria-label="选择文本文件" required />
          </label>
          <label>资料名称
            <input name="book_name" type="text" maxlength="255" aria-label="导入资料名称" required />
          </label>
          <label>章节名称
            <input name="chapter_name" type="text" maxlength="255" value="导入文本" aria-label="导入章节名称" required />
          </label>
          <button class="secondary" type="submit">导入到服务器</button>
        </form>` : ''}
        <div class="settings-card"><h2>服务器</h2><p class="server-url">${escapeHtml(serverUrl)}</p></div>
        ${mobilePrivacyPolicyHtml()}
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
      let content: string;
      try {
        content = new TextDecoder('utf-8', { fatal: true }).decode(bytes);
      } catch {
        throw new Error('文本文件必须使用 UTF-8 编码');
      }
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
      const retryable = result.results.filter(item => item.outcome === 'retryable').length;
      if (terminal) this.showToast(`${terminal} 个离线操作未能应用，请在“我的”中查看处理建议`, true);
      else if (announce && retryable) this.showToast('暂时无法同步；待同步操作仍保留在本机，请稍后重试', true);
      else if (announce) this.showToast(`已同步 ${result.counts.succeeded} 个操作`);
    } catch (error) {
      this.noteNetworkFailure(error);
      if (announce) this.showToast('暂时无法同步；待同步操作仍保留在本机，请稍后重试', true);
    } finally {
      this.syncing = false;
      if (this.root.querySelector('.app-shell')) this.renderShellAndCurrentScreen();
    }
  }

  private renderShellAndCurrentScreen(): void {
    const screen = this.screen;
    this.renderShell();
    if (screen === 'library' && this.selectedChapter && this.readerPackage) this.renderReader();
    else if (screen === 'library') this.renderLibrary();
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
      + `${this.syncIssueCount ? ` · ${this.syncIssueCount} 需处理` : ''}`;
  }

  private async saveOffline(operation: () => Promise<void> | undefined): Promise<void> {
    try {
      await operation();
    } catch {
      this.showToast('无法更新本机离线包；在线内容仍可使用', true);
    }
  }

  private packageHasCurrentContinuity(value: ChapterPackage): value is ChapterPackage & {
    reading_session: NonNullable<ChapterPackage['reading_session']>;
  } {
    const continuity = value.reading_session?.continuity;
    return Boolean(
      value.source_revision
      && value.reading_session
      && value.reading_session.source_revision === value.source_revision
      && continuity?.source_revision === value.source_revision
      && (!continuity.resume || continuity.resume.source_revision === value.source_revision)
      && (!continuity.furthest || continuity.furthest.source_revision === value.source_revision),
    );
  }

  private startReaderPositionTracking(): void {
    const buttons = [...this.screenElement().querySelectorAll<HTMLButtonElement>('[data-canonical-token]')];
    if (!buttons.length) return;

    const updateVisiblePosition = () => {
      const threshold = window.innerHeight * 0.35;
      const visible = buttons.filter(button => {
        const rect = button.getBoundingClientRect();
        return rect.bottom > 0 && rect.top <= threshold;
      });
      const button = visible.at(-1) ?? buttons.find(candidate => candidate.getBoundingClientRect().bottom > 0);
      const canonical = Number(button?.dataset.canonicalToken);
      if (button && Number.isInteger(canonical)) this.currentReaderCanonicalIndex = canonical;
    };
    const schedule = () => {
      updateVisiblePosition();
      if (this.readerPositionTimer !== null) window.clearTimeout(this.readerPositionTimer);
      this.readerPositionTimer = window.setTimeout(() => {
        this.readerPositionTimer = null;
        void this.queueCurrentReaderPosition();
      }, 500);
    };
    const persistOnLifecycle = () => {
      updateVisiblePosition();
      void this.queueCurrentReaderPosition();
    };
    const onVisibilityChange = () => {
      if (document.visibilityState === 'hidden') persistOnLifecycle();
    };

    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('pagehide', persistOnLifecycle);
    document.addEventListener('visibilitychange', onVisibilityChange);
    let restoreFrame: number | null = null;
    this.readerPositionCleanup = () => {
      window.removeEventListener('scroll', schedule);
      window.removeEventListener('pagehide', persistOnLifecycle);
      document.removeEventListener('visibilitychange', onVisibilityChange);
      if (this.readerPositionTimer !== null) window.clearTimeout(this.readerPositionTimer);
      if (restoreFrame !== null) cancelAnimationFrame(restoreFrame);
      this.readerPositionTimer = null;
    };

    const resume = this.currentReaderCanonicalIndex;
    if (resume !== null) {
      restoreFrame = requestAnimationFrame(() => {
        restoreFrame = null;
        this.screenElement().querySelector<HTMLElement>(`[data-canonical-token="${resume}"]`)
          ?.scrollIntoView({ block: 'center' });
        updateVisiblePosition();
      });
    } else {
      updateVisiblePosition();
    }
  }

  private stopReaderPositionTracking(): void {
    this.readerPositionCleanup?.();
    this.readerPositionCleanup = null;
  }

  private async queueCurrentReaderPosition(): Promise<void> {
    const chapterId = this.selectedChapter?.chapter_id;
    const sourceRevision = this.readerPackage?.source_revision;
    const canonicalTokenIndex = this.currentReaderCanonicalIndex;
    if (
      !this.offlineRepository
      || !chapterId
      || !sourceRevision
      || canonicalTokenIndex === null
      || canonicalTokenIndex === this.lastQueuedReaderCanonicalIndex
    ) return;

    this.lastQueuedReaderCanonicalIndex = canonicalTokenIndex;
    try {
      await this.offlineRepository.enqueueReadingPosition(
        chapterId,
        sourceRevision,
        canonicalTokenIndex,
      );
      await this.refreshSyncStatus();
    } catch {
      if (this.lastQueuedReaderCanonicalIndex === canonicalTokenIndex) {
        this.lastQueuedReaderCanonicalIndex = null;
      }
      this.showToast('无法保存本机阅读位置；请保持页面打开后重试', true);
    }
  }
}
