/**
 * Site search enhancements, all progressive (every search still works as
 * a plain form without this file):
 *
 * - Search boxes get either a results panel (live results as you type,
 *   plus recent and popular searches before you type) or, when only title
 *   suggestions are on, the browser's own <datalist>.
 * - The panel follows the ARIA combobox pattern: arrow keys move through
 *   it, Enter opens the highlighted entry, Escape closes it.
 * - On the search page, filters, sorting, pagination, and "Did you mean?"
 *   update the results in place instead of reloading the page.
 *
 * Settings arrive as data-* attributes on this script's own tag, since
 * the CSP forbids inline script. Recent searches of visitors who aren't
 * signed in are kept only in their own browser (localStorage); a
 * signed-in user's are kept on their account instead, so nothing is left
 * behind in a shared browser after they sign out.
 */
(() => {
    const script = document.currentScript;

    if (!script || !('lpSearch' in script.dataset)) {
        return;
    }

    const config = {
        minLength: Number(script.dataset.minLength) || 3,
        searchUrl: script.dataset.searchUrl || '',
        suggestionsEndpoint: script.dataset.suggestionsEndpoint || '',
        liveEndpoint: script.dataset.liveEndpoint || '',
        panelEndpoint: script.dataset.panelEndpoint || '',
        clearEndpoint: script.dataset.clearEndpoint || '',
        history: script.dataset.history === '1',
        signedIn: script.dataset.signedIn === '1',
    };

    const HISTORY_KEY = 'lp-search-history';
    const HISTORY_LIMIT = 8;
    const TYPING_DELAY = 200;
    let uniqueId = 0;

    // ------------------------------------------------------------------
    // Recent searches kept in this browser (signed-out visitors only)
    // ------------------------------------------------------------------

    const readLocalHistory = () => {
        try {
            const stored = JSON.parse(window.localStorage.getItem(HISTORY_KEY) || '[]');

            return Array.isArray(stored) ? stored.filter((entry) => typeof entry === 'string') : [];
        } catch (error) {
            return [];
        }
    };

    const writeLocalHistory = (entries) => {
        try {
            if (entries.length) {
                window.localStorage.setItem(HISTORY_KEY, JSON.stringify(entries));
            } else {
                window.localStorage.removeItem(HISTORY_KEY);
            }
        } catch (error) {
            // Storage blocked (private browsing, site data cleared): history just isn't kept.
        }
    };

    const rememberSearch = (text) => {
        const query = text.trim();

        if (!config.history || config.signedIn || query === '') {
            return;
        }

        const others = readLocalHistory().filter((entry) => entry.toLowerCase() !== query.toLowerCase());
        writeLocalHistory([query, ...others].slice(0, HISTORY_LIMIT));
    };

    // The "before you type" data (server-side recent searches for signed-in
    // users, popular searches), fetched once per page and shared by every box.
    let panelDataPromise = null;

    const loadPanelData = () => {
        if (!config.panelEndpoint) {
            return Promise.resolve({ recent: [], popular: [], clearToken: null });
        }

        panelDataPromise ??= fetch(config.panelEndpoint, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => (response.ok ? response.json() : {}))
            .catch(() => ({}))
            .then((data) => ({
                recent: Array.isArray(data.recent) ? data.recent : [],
                popular: Array.isArray(data.popular) ? data.popular : [],
                clearToken: typeof data.clearToken === 'string' ? data.clearToken : null,
            }));

        return panelDataPromise;
    };

    const fetchJson = (endpoint, text, signal) => fetch(endpoint + '?q=' + encodeURIComponent(text), {
        signal,
        headers: { Accept: 'application/json' },
    }).then((response) => (response.ok ? response.json() : {}));

    // ------------------------------------------------------------------
    // Plain title suggestions (<datalist>)
    // ------------------------------------------------------------------

    const attachDatalist = (form, input) => {
        const list = document.createElement('datalist');
        list.id = 'lp-search-suggestions-' + (++uniqueId);
        input.insertAdjacentElement('afterend', list);
        input.setAttribute('list', list.id);
        input.setAttribute('autocomplete', 'off');

        let timer = null;
        let controller = null;
        let shown = [];

        const show = (titles) => {
            shown = titles;
            list.replaceChildren(...titles.map((title) => {
                const option = document.createElement('option');
                option.value = title;

                return option;
            }));
        };

        input.addEventListener('input', (event) => {
            if (event.inputType === 'insertReplacementText' || (event.inputType === undefined && shown.includes(input.value))) {
                form.requestSubmit();

                return;
            }

            window.clearTimeout(timer);
            const text = input.value.trim();

            if (text.length < config.minLength) {
                show([]);

                return;
            }

            timer = window.setTimeout(() => {
                controller?.abort();
                controller = new AbortController();

                fetchJson(config.suggestionsEndpoint, text, controller.signal)
                    .then((data) => show(Array.isArray(data.suggestions) ? data.suggestions.filter((title) => typeof title === 'string') : []))
                    .catch((error) => {
                        if (error.name !== 'AbortError') {
                            show([]);
                        }
                    });
            }, TYPING_DELAY);
        });
    };

    // ------------------------------------------------------------------
    // Results panel (ARIA combobox + listbox)
    // ------------------------------------------------------------------

    const attachPanel = (form, input) => {
        const id = 'lp-search-live-' + (++uniqueId);
        const panel = document.createElement('div');
        panel.className = 'lp-search-live';
        panel.hidden = true;

        const list = document.createElement('ul');
        list.className = 'lp-search-live__list';
        list.id = id + '-list';
        list.setAttribute('role', 'listbox');
        list.setAttribute('aria-label', 'Search suggestions');

        const status = document.createElement('div');
        status.className = 'lp-visually-hidden';
        status.setAttribute('role', 'status');

        // The panel lives on <body>, positioned from the search box, because
        // theme headers often clip their contents (overflow: hidden).
        panel.append(list);
        document.body.append(panel);
        form.append(status);

        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-controls', list.id);
        input.setAttribute('autocomplete', 'off');

        let options = [];
        let activeIndex = -1;
        let timer = null;
        let controller = null;
        let clearToken = null;

        // Positions come from CSSOM properties, which the CSP allows (it
        // only forbids style attributes and inline <style>).
        const position = () => {
            if (panel.hidden) {
                return;
            }

            const box = input.getBoundingClientRect();
            const width = panel.offsetWidth;
            const left = Math.min(Math.max(8, box.right - width), window.innerWidth - width - 8);

            panel.style.top = Math.round(box.bottom + 6) + 'px';
            panel.style.left = Math.round(Math.max(8, left)) + 'px';
        };

        window.addEventListener('resize', position, { passive: true });
        window.addEventListener('scroll', position, { passive: true, capture: true });

        const close = () => {
            panel.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
            activeIndex = -1;
        };

        const setActive = (index) => {
            options.forEach((option) => option.classList.remove('is-active'));
            activeIndex = index;

            if (index < 0 || !options[index]) {
                input.removeAttribute('aria-activedescendant');

                return;
            }

            options[index].classList.add('is-active');
            options[index].setAttribute('aria-selected', 'true');
            options.forEach((option, other) => {
                if (other !== index) {
                    option.setAttribute('aria-selected', 'false');
                }
            });
            input.setAttribute('aria-activedescendant', options[index].id);
            options[index].scrollIntoView({ block: 'nearest' });
        };

        const activate = (option) => {
            if (option.dataset.href) {
                window.location.assign(option.dataset.href);

                return;
            }

            input.value = option.dataset.query || '';
            close();
            form.requestSubmit();
        };

        const heading = (text, onClear) => {
            const item = document.createElement('li');
            item.className = 'lp-search-live__heading';
            item.setAttribute('role', 'presentation');

            const label = document.createElement('span');
            label.textContent = text;
            item.append(label);

            if (onClear) {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'lp-search-live__clear';
                button.textContent = 'Clear';
                button.setAttribute('aria-label', 'Clear recent searches');
                button.addEventListener('mousedown', (event) => event.preventDefault());
                button.addEventListener('click', onClear);
                item.append(button);
            }

            return item;
        };

        const option = (className) => {
            const item = document.createElement('li');
            item.className = 'lp-search-live__option ' + className;
            item.id = id + '-option-' + options.length;
            item.setAttribute('role', 'option');
            item.setAttribute('aria-selected', 'false');
            item.addEventListener('mousedown', (event) => event.preventDefault());
            item.addEventListener('click', () => activate(item));
            options.push(item);

            return item;
        };

        const queryOption = (query) => {
            const item = option('lp-search-live__option--query');
            item.dataset.query = query;
            item.textContent = query;

            return item;
        };

        const open = (items, announcement) => {
            options = options.filter((item) => items.includes(item));
            list.replaceChildren(...items);
            activeIndex = -1;
            input.removeAttribute('aria-activedescendant');

            if (!items.length) {
                close();

                return;
            }

            panel.hidden = false;
            input.setAttribute('aria-expanded', 'true');
            status.textContent = announcement || '';
            position();
        };

        const showStart = () => {
            loadPanelData().then((data) => {
                if (document.activeElement !== input || input.value.trim() !== '') {
                    return;
                }

                clearToken = data.clearToken;
                options = [];
                const items = [];
                const recent = config.signedIn ? data.recent : (config.history ? readLocalHistory() : []);

                if (recent.length) {
                    items.push(heading('Recent searches', () => clearRecent()));
                    recent.forEach((query) => items.push(queryOption(query)));
                }

                if (data.popular.length) {
                    items.push(heading('Popular searches'));
                    data.popular.forEach((query) => items.push(queryOption(query)));
                }

                open(items, '');
            });
        };

        const clearRecent = () => {
            if (!config.signedIn) {
                writeLocalHistory([]);
                showStart();
                input.focus();

                return;
            }

            if (!clearToken) {
                return;
            }

            const body = new FormData();
            body.append('csrf_token', clearToken);

            fetch(config.clearEndpoint, { method: 'POST', body, credentials: 'same-origin' })
                .then((response) => (response.ok ? response.json() : null))
                .then((data) => {
                    if (data) {
                        panelDataPromise = Promise.resolve(loadPanelData().then((previous) => ({
                            ...previous,
                            recent: [],
                            clearToken: typeof data.clearToken === 'string' ? data.clearToken : null,
                        })));
                        showStart();
                        input.focus();
                    }
                })
                .catch(() => {});
        };

        const showTyped = (text) => {
            controller?.abort();
            controller = new AbortController();

            if (config.liveEndpoint) {
                fetchJson(config.liveEndpoint, text, controller.signal)
                    .then((data) => {
                        options = [];
                        const results = Array.isArray(data.results) ? data.results : [];
                        const items = results.map((result) => {
                            const item = option('lp-search-live__option--result');
                            item.dataset.href = String(result.url || '');

                            if (result.thumbnail) {
                                const image = document.createElement('img');
                                image.className = 'lp-search-live__thumb';
                                image.src = String(result.thumbnail);
                                image.alt = '';
                                image.loading = 'lazy';
                                item.append(image);
                            }

                            const text = document.createElement('span');
                            text.className = 'lp-search-live__text';

                            const title = document.createElement('span');
                            title.className = 'lp-search-live__title';
                            title.textContent = String(result.title || '');

                            const meta = document.createElement('span');
                            meta.className = 'lp-search-live__meta';
                            meta.textContent = [result.type, result.date].filter(Boolean).join(' · ');

                            text.append(title, meta);
                            item.append(text);

                            return item;
                        });

                        const total = Number(data.total) || 0;

                        if (total > 0) {
                            const all = option('lp-search-live__option--all');
                            all.dataset.query = text;
                            all.textContent = total === 1 ? 'See the result on the search page' : 'See all ' + total + ' results';
                            items.push(all);
                        } else {
                            const empty = document.createElement('li');
                            empty.className = 'lp-search-live__empty';
                            empty.setAttribute('role', 'presentation');
                            empty.textContent = 'No matches yet. Press Enter to search.';
                            items.push(empty);
                        }

                        open(items, total === 0 ? 'No matches' : total + (total === 1 ? ' result' : ' results'));
                    })
                    .catch((error) => {
                        if (error.name !== 'AbortError') {
                            close();
                        }
                    });

                return;
            }

            if (config.suggestionsEndpoint) {
                fetchJson(config.suggestionsEndpoint, text, controller.signal)
                    .then((data) => {
                        options = [];
                        const titles = Array.isArray(data.suggestions) ? data.suggestions.filter((title) => typeof title === 'string') : [];
                        open(titles.map(queryOption), titles.length ? titles.length + ' suggestions' : '');
                    })
                    .catch((error) => {
                        if (error.name !== 'AbortError') {
                            close();
                        }
                    });

                return;
            }

            close();
        };

        input.addEventListener('focus', () => {
            if (input.value.trim() === '') {
                showStart();
            }
        });

        input.addEventListener('input', () => {
            window.clearTimeout(timer);
            const text = input.value.trim();

            if (text === '') {
                controller?.abort();
                showStart();

                return;
            }

            if (text.length < config.minLength) {
                controller?.abort();
                close();

                return;
            }

            timer = window.setTimeout(() => showTyped(text), TYPING_DELAY);
        });

        input.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                if (panel.hidden) {
                    if (input.value.trim() === '') {
                        showStart();
                    }

                    return;
                }

                event.preventDefault();

                if (!options.length) {
                    return;
                }

                const step = event.key === 'ArrowDown' ? 1 : -1;
                setActive((activeIndex + step + options.length) % options.length);
            } else if (event.key === 'Enter' && !panel.hidden && activeIndex >= 0) {
                event.preventDefault();
                activate(options[activeIndex]);
            } else if (event.key === 'Escape' && !panel.hidden) {
                event.preventDefault();
                close();
            } else if (event.key === 'Tab') {
                close();
            }
        });

        form.addEventListener('focusout', () => {
            window.setTimeout(() => {
                if (!form.contains(document.activeElement) && !panel.contains(document.activeElement)) {
                    close();
                }
            }, 0);
        });

        document.addEventListener('click', (event) => {
            if (!form.contains(event.target) && !panel.contains(event.target)) {
                close();
            }
        });
    };

    // ------------------------------------------------------------------
    // Wiring every search box
    // ------------------------------------------------------------------

    const usePanel = Boolean(config.liveEndpoint || config.panelEndpoint || (config.history && !config.signedIn));

    const wireSearchBoxes = (root) => {
        root.querySelectorAll('form[role="search"]').forEach((form) => {
            const input = form.querySelector('input[name="q"]');

            if (!input || input.dataset.lpSearchBound === '1') {
                return;
            }

            input.dataset.lpSearchBound = '1';
            form.addEventListener('submit', () => rememberSearch(input.value));

            // The search page's own "Refine search" box sits above the
            // results it would repeat, so it only ever gets title suggestions.
            const isRefineForm = form.classList.contains('lp-search-filters__form');

            if (usePanel && !isRefineForm) {
                attachPanel(form, input);
            } else if (config.suggestionsEndpoint) {
                attachDatalist(form, input);
            }
        });
    };

    wireSearchBoxes(document);

    // ------------------------------------------------------------------
    // Search page: update results in place
    // ------------------------------------------------------------------

    const main = document.querySelector('main.lp-main');

    if (!main || !main.querySelector('form.lp-search-filters__form') || !window.DOMParser || !window.history.pushState) {
        return;
    }

    const searchPath = new URL(config.searchUrl || main.querySelector('form.lp-search-filters__form').action, window.location.href).pathname;
    const announcer = document.createElement('div');
    announcer.className = 'lp-visually-hidden';
    announcer.setAttribute('role', 'status');
    document.body.append(announcer);
    let pageController = null;

    const currentQuery = () => new URL(window.location.href).searchParams.get('q') || '';

    const load = (url, push) => {
        pageController?.abort();
        pageController = new AbortController();
        const target = new URL(url, window.location.href);
        const refine = (target.searchParams.get('q') || '') === currentQuery();
        const focusedId = main.contains(document.activeElement) ? document.activeElement.id : '';

        main.setAttribute('aria-busy', 'true');

        const headers = { 'X-Requested-With': 'fetch' };

        if (refine) {
            headers['X-Lp-Search-Refine'] = '1';
        }

        fetch(target.href, { signal: pageController.signal, headers, credentials: 'same-origin' })
            .then((response) => {
                if (!response.ok) {
                    throw new Error('Search request failed');
                }

                return response.text();
            })
            .then((html) => {
                const next = new DOMParser().parseFromString(html, 'text/html');
                const nextMain = next.querySelector('main.lp-main');

                // New results needing the lightbox script this page never
                // loaded (it only loads where images appear) need a real load.
                const needsViewer = next.querySelector('script[data-lp-media-viewer]') && !document.querySelector('script[data-lp-media-viewer]');

                if (!nextMain || needsViewer) {
                    window.location.assign(target.href);

                    return;
                }

                main.replaceChildren(...Array.from(nextMain.childNodes, (node) => document.importNode(node, true)));
                document.title = next.title;

                if (push) {
                    window.history.pushState({ lpSearch: true }, '', target.href);
                }

                const query = target.searchParams.get('q') || '';
                document.querySelectorAll('form[role="search"] input[name="q"]').forEach((input) => {
                    if (!main.contains(input)) {
                        input.value = query;
                    }
                });

                wireSearchBoxes(main);
                main.dispatchEvent(new CustomEvent('lp:content-updated', { bubbles: true }));

                const title = main.querySelector('h1');
                const refocus = focusedId ? document.getElementById(focusedId) : null;

                if (refocus && main.contains(refocus)) {
                    refocus.focus();
                } else if (title) {
                    title.setAttribute('tabindex', '-1');
                    title.focus();
                }

                const count = main.querySelectorAll('.lp-search-results__item').length;
                announcer.textContent = (title ? title.textContent.trim() + '. ' : '') + (count ? count + (count === 1 ? ' result shown.' : ' results shown.') : 'No results.');
            })
            .catch((error) => {
                if (error.name !== 'AbortError') {
                    window.location.assign(target.href);
                }
            })
            .finally(() => main.removeAttribute('aria-busy'));
    };

    const formUrl = (form) => {
        const params = new URLSearchParams();

        new FormData(form).forEach((value, name) => {
            if (typeof value === 'string' && value.trim() !== '') {
                params.append(name, value);
            }
        });

        const action = new URL(form.action, window.location.href);

        return action.origin + action.pathname + (params.toString() ? '?' + params.toString() : '');
    };

    main.addEventListener('submit', (event) => {
        const form = event.target;

        if (form instanceof HTMLFormElement && form.classList.contains('lp-search-filters__form')) {
            event.preventDefault();
            load(formUrl(form), true);
        }
    });

    // Choosing a filter or sort order applies it straight away.
    main.addEventListener('change', (event) => {
        const field = event.target;
        const form = field instanceof Element ? field.closest('form.lp-search-filters__form') : null;

        if (form && field.name !== 'q' && (field.tagName === 'SELECT' || field.type === 'date')) {
            load(formUrl(form), true);
        }
    });

    main.addEventListener('click', (event) => {
        const link = event.target instanceof Element ? event.target.closest('a[href]') : null;

        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || link.target) {
            return;
        }

        const url = new URL(link.href, window.location.href);

        if (url.origin === window.location.origin && url.pathname === searchPath) {
            event.preventDefault();
            load(url.href, true);
        }
    });

    window.history.replaceState({ lpSearch: true }, '', window.location.href);

    window.addEventListener('popstate', (event) => {
        if (event.state && event.state.lpSearch) {
            load(window.location.href, false);
        }
    });
})();
