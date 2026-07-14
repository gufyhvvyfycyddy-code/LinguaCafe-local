export class CustomStudySessionCoordinator {
    constructor({ transport, storage, storageKey, onState = () => {} }) {
        this.transport = transport;
        this.storage = storage;
        this.storageKey = storageKey;
        this.onState = onState;
        this.generation = 0;
        this.disposed = false;
        this.autoResumeStarted = false;
        this.state = {
            token: '',
            currentCard: null,
            summary: {},
            waitUntil: null,
            completed: false,
            expired: false,
            loading: false,
            mutationLocked: false,
            mutationType: '',
            pendingRating: '',
            error: '',
        };
    }

    snapshot() {
        return { ...this.state };
    }

    open(token, payload) {
        this.disposed = false;
        this.generation++;
        this.state.token = token || '';
        this.applyPayload(payload || {}, { isOpen: true });
        return true;
    }

    restore() {
        if (this.disposed) return Promise.resolve(false);
        const storedToken = this.storage.getItem(this.storageKey);
        if (!storedToken) return Promise.resolve(false);
        this.state.token = storedToken;
        return this.resume({ initial: true });
    }

    answer(rating) {
        if (!this.state.currentCard || this.state.completed || this.state.expired) {
            return Promise.resolve(false);
        }
        return this.mutate('answer', rating, token => this.transport.answer(token, rating));
    }

    resume({ initial = false } = {}) {
        if (this.state.completed || this.state.expired) return Promise.resolve(false);
        return this.mutate('resume', '', token => this.transport.resume(token), initial);
    }

    autoResume() {
        if (this.autoResumeStarted) return Promise.resolve(false);
        this.autoResumeStarted = true;
        return this.resume();
    }

    async mutate(type, rating, request, initial = false) {
        if (this.disposed || this.state.mutationLocked || !this.state.token) return false;

        const requestGeneration = ++this.generation;
        const requestToken = this.state.token;
        this.state.mutationLocked = true;
        this.state.mutationType = type;
        this.state.pendingRating = rating;
        this.state.loading = initial;
        this.state.error = '';
        this.emit();

        try {
            const payload = await request(requestToken);
            if (!this.isCurrent(requestGeneration)) return false;
            this.applyPayload(payload || {});
            return true;
        } catch (error) {
            if (!this.isCurrent(requestGeneration)) return false;
            this.applyError(error);
            return false;
        } finally {
            if (this.isCurrent(requestGeneration)) {
                this.state.mutationLocked = false;
                this.state.mutationType = '';
                this.state.pendingRating = '';
                this.state.loading = false;
                this.emit();
            }
        }
    }

    applyPayload(payload, { isOpen = false } = {}) {
        if (this.disposed) return false;
        const refreshedToken = payload.refreshed_token || payload.token;
        if (refreshedToken) {
            this.state.token = refreshedToken;
            this.storage.setItem(this.storageKey, refreshedToken);
        }
        this.state.summary = payload.summary || {};
        this.state.currentCard = payload.current_card || null;
        this.state.waitUntil = payload.wait_until || null;
        this.state.completed = Boolean(payload.completed)
            || Boolean(isOpen && !this.state.currentCard && !this.state.waitUntil);
        this.state.expired = false;
        this.state.error = '';
        this.autoResumeStarted = false;

        if (this.state.completed) {
            this.state.waitUntil = null;
            this.clearToken();
        }
        this.emit();
        return true;
    }

    applyError(error) {
        const response = error && error.response;
        const payload = response && response.data ? response.data : {};
        if (response && response.status === 404) {
            this.state.expired = true;
            this.state.currentCard = null;
            this.state.waitUntil = null;
            this.state.error = payload.message || 'Preview session expired.';
            this.clearToken();
        } else {
            this.state.error = payload.message || (error && error.message) || 'Request failed.';
        }
        this.emit();
    }

    exit() {
        this.generation++;
        this.state.mutationLocked = false;
        this.state.mutationType = '';
        this.state.pendingRating = '';
        this.state.currentCard = null;
        this.state.waitUntil = null;
        this.clearToken();
        this.emit();
    }

    dispose() {
        this.disposed = true;
        this.generation++;
        this.state.mutationLocked = false;
    }

    clearToken() {
        this.state.token = '';
        this.storage.removeItem(this.storageKey);
    }

    isCurrent(requestGeneration) {
        return !this.disposed && requestGeneration === this.generation;
    }

    emit() {
        if (!this.disposed) this.onState(this.snapshot());
    }
}

export function customStudyKeyboardAction(event, state) {
    const target = event && event.target ? event.target : {};
    const tagName = String(target.tagName || '').toLowerCase();
    const isEditable = target.isContentEditable
        || ['input', 'textarea', 'select', 'button'].includes(tagName);
    if (!event || event.repeat || isEditable || state.blocked || state.sourceDialogOpen) return null;

    const key = event.code || event.key;
    if (!state.showAnswer) return key === 'Space' || key === ' ' ? 'reveal' : null;

    return {
        Digit1: 'again', Numpad1: 'again', '1': 'again',
        Digit2: 'hard', Numpad2: 'hard', '2': 'hard',
        Digit3: 'good', Numpad3: 'good', '3': 'good',
        Digit4: 'easy', Numpad4: 'easy', '4': 'easy',
    }[key] || null;
}

export function isCustomStudySessionMutationLocked(state) {
    return Boolean(
        state.mutationLocked
        || state.waitUntil
        || state.completed
        || state.expired
        || !state.currentCard,
    );
}

export function customStudySessionStorageKey() {
    return 'linguacafe.custom-study.preview-token';
}
