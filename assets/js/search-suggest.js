/**
 * Search box suggestions: fills a native <datalist> under every site search
 * box with matching post and page titles as the visitor types. A datalist
 * keeps keyboard and screen-reader behavior the browser's own. Picking a
 * suggestion searches for it straight away. Settings arrive as data-*
 * attributes on this script's own tag, since the CSP forbids inline script.
 */
(() => {
    const script = document.currentScript;
    const endpoint = script?.dataset.endpoint;
    const minLength = Number(script?.dataset.minLength) || 3;

    if (!endpoint) {
        return;
    }

    const inputs = document.querySelectorAll('form[role="search"] input[name="q"]');

    inputs.forEach((input, index) => {
        const list = document.createElement('datalist');
        list.id = 'lp-search-suggestions-' + index;
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
                input.form?.requestSubmit();
                return;
            }

            window.clearTimeout(timer);
            const text = input.value.trim();

            if (text.length < minLength) {
                show([]);
                return;
            }

            timer = window.setTimeout(async () => {
                controller?.abort();
                controller = new AbortController();

                try {
                    const response = await fetch(endpoint + '?q=' + encodeURIComponent(text), {
                        signal: controller.signal,
                        headers: { Accept: 'application/json' },
                    });

                    if (!response.ok) {
                        return;
                    }

                    const data = await response.json();
                    show(Array.isArray(data.suggestions) ? data.suggestions.filter((title) => typeof title === 'string') : []);
                } catch (error) {
                    if (error.name !== 'AbortError') {
                        show([]);
                    }
                }
            }, 250);
        });
    });
})();
