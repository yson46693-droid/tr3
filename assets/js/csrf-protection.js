(() => {
    if (typeof window === 'undefined') {
        return;
    }

    const token = window.APP_CSRF_TOKEN;
    const fieldName = window.APP_CSRF_FIELD || 'csrf_token';
    const RequestCtor = typeof Request === 'function' ? Request : null;

    if (!token) {
        return;
    }

    const isSameOrigin = (input) => {
        try {
            const url = RequestCtor && input instanceof RequestCtor ? input.url : input;
            if (url instanceof URL) {
                return url.origin === window.location.origin;
            }
            const parsed = new URL(url, window.location.href);
            return parsed.origin === window.location.origin;
        } catch (error) {
            return true;
        }
    };

    const ensureFormToken = (form) => {
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        if (form.dataset && form.dataset.noCsrf === 'true') {
            return;
        }

        let input = form.querySelector(`input[name="${fieldName}"]`);
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = fieldName;
            form.appendChild(input);
        }
        input.value = token;
    };

    const observeForms = () => {
        const observer = new MutationObserver((mutations) => {
            mutations.forEach((mutation) => {
                mutation.addedNodes.forEach((node) => {
                    if (node instanceof HTMLFormElement) {
                        ensureFormToken(node);
                    } else if (node instanceof HTMLElement) {
                        node.querySelectorAll('form').forEach(ensureFormToken);
                    }
                });
            });
        });
        observer.observe(document.body, { childList: true, subtree: true });
    };

    const initializeForms = () => {
        document.querySelectorAll('form').forEach(ensureFormToken);
        document.addEventListener(
            'submit',
            (event) => {
                if (event.target instanceof HTMLFormElement) {
                    ensureFormToken(event.target);
                }
            },
            true
        );
        observeForms();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeForms, { once: true });
    } else {
        initializeForms();
    }

    const applyHeader = (headers) => {
        if (!headers) {
            return { 'X-CSRF-Token': token };
        }

        if (headers instanceof Headers) {
            headers.set('X-CSRF-Token', token);
            return headers;
        }

        if (Array.isArray(headers)) {
            const lowerKey = 'x-csrf-token';
            let updated = false;
            for (let i = 0; i < headers.length; i += 1) {
                if (
                    Array.isArray(headers[i]) &&
                    headers[i][0] &&
                    headers[i][0].toLowerCase() === lowerKey
                ) {
                    headers[i][1] = token;
                    updated = true;
                    break;
                }
            }
            if (!updated) {
                headers.push(['X-CSRF-Token', token]);
            }
            return headers;
        }

        const existingKey = Object.keys(headers).find(
            (headerKey) => headerKey.toLowerCase() === 'x-csrf-token'
        );
        if (existingKey) {
            headers[existingKey] = token;
        } else {
            headers['X-CSRF-Token'] = token;
        }
        return headers;
    };

    if (typeof window.fetch === 'function') {
        const originalFetch = window.fetch.bind(window);
        window.fetch = (resource, init = {}) => {
        if (resource && isSameOrigin(RequestCtor && resource instanceof RequestCtor ? resource : resource)) {
            if (RequestCtor && resource instanceof RequestCtor) {
                    const clonedRequest = resource.clone();
                    const mergedHeaders = new Headers(clonedRequest.headers || {});
                    if (init && init.headers) {
                        const overrideHeaders = new Headers(init.headers);
                        overrideHeaders.forEach((value, key) => mergedHeaders.set(key, value));
                    }
                    mergedHeaders.set('X-CSRF-Token', token);
                    const requestInit = Object.assign({}, init, { headers: mergedHeaders });
                return originalFetch(new RequestCtor(clonedRequest, requestInit));
                }
                init.headers = applyHeader(init.headers);
            }
            return originalFetch(resource, init);
        };
    }

    if (typeof XMLHttpRequest !== 'undefined') {
        const originalOpen = XMLHttpRequest.prototype.open;
        const originalSend = XMLHttpRequest.prototype.send;

        XMLHttpRequest.prototype.open = function open(method, url) {
            try {
                const parsed = new URL(url, window.location.href);
                this._csrfShouldAttach = parsed.origin === window.location.origin;
            } catch (error) {
                this._csrfShouldAttach = true;
            }
            return originalOpen.apply(this, arguments);
        };

        XMLHttpRequest.prototype.send = function send(body) {
            if (this._csrfShouldAttach) {
                try {
                    this.setRequestHeader('X-CSRF-Token', token);
                } catch (error) {
                    // ignore header errors
                }
            }
            return originalSend.call(this, body);
        };
    }
})();

