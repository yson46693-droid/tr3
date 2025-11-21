(() => {
    "use strict";

    const backdrop = document.getElementById("pwa-modal-backdrop");
    const iframe = backdrop?.querySelector("iframe");
    const closeBtn = backdrop?.querySelector("button[data-modal-close]");

    if (!backdrop || !iframe || !closeBtn) {
        console.error("Modal interception disabled: required modal elements not found.");
        return;
    }

    const originalBodyOverflow = document.body.style.overflow || "";
    let lastOpener = null;

    const clearOpener = () => {
        if (lastOpener && lastOpener instanceof Element) {
            lastOpener.removeAttribute("data-modal-opener");
        }
        lastOpener = null;
    };

    const setOpener = (opener) => {
        clearOpener();
        if (opener && opener instanceof Element) {
            lastOpener = opener;
            opener.setAttribute("data-modal-opener", "true");
        } else if (document.activeElement && document.activeElement instanceof Element) {
            lastOpener = document.activeElement;
            lastOpener.setAttribute("data-modal-opener", "true");
        }
    };

    const restoreFocus = () => {
        if (lastOpener && typeof lastOpener.focus === "function") {
            lastOpener.focus({ preventScroll: true });
        }
        clearOpener();
    };

    const activateModal = () => {
        backdrop.classList.add("is-active");
        backdrop.setAttribute("aria-hidden", "false");
        document.body.style.overflow = "hidden";
        closeBtn.focus({ preventScroll: true });
    };

    const resetIframe = () => {
        try {
            const doc = iframe.contentDocument || iframe.contentWindow?.document;
            if (doc) {
                doc.open();
                doc.write("<!DOCTYPE html><title></title>");
                doc.close();
            }
        } catch (error) {
            console.debug("Unable to reset iframe document:", error);
        }
        iframe.src = "about:blank";
    };

    const closeModal = () => {
        backdrop.classList.remove("is-active");
        backdrop.setAttribute("aria-hidden", "true");
        resetIframe();
        document.body.style.overflow = originalBodyOverflow;
        restoreFocus();
    };

    const openWithUrl = (href, options = {}) => {
        if (!href) {
            console.warn("Cannot open empty URL in modal.");
            return;
        }
        setOpener(options.opener);
        try {
            const absoluteUrl = new URL(href, window.location.href).toString();
            iframe.src = absoluteUrl;
            activateModal();
        } catch (error) {
            console.error("Failed to open URL in modal, falling back to same-window navigation.", error);
            window.location.assign(href);
        }
    };

    const openWithHtml = (html, options = {}) => {
        if (typeof html !== "string" || html.trim() === "") {
            console.warn("Cannot render empty HTML content inside the modal.");
            return;
        }
        setOpener(options.opener);
        try {
            const view = iframe.contentWindow;
            if (!view || !view.document) {
                throw new Error("Iframe content window is not accessible.");
            }
            activateModal();
            const doc = view.document;
            doc.open();
            doc.write(html);
            doc.close();
        } catch (error) {
            console.error("Failed to render HTML content in modal.", error);
            if (options.fallbackUrl) {
                window.location.assign(options.fallbackUrl);
            }
        }
    };

    const shouldIntercept = (link) => {
        if (!link || !link.href) {
            return false;
        }
        if (link.dataset.allowNewWindow === "true") {
            return false;
        }
        if (link.hasAttribute("download")) {
            return false;
        }
        const target = (link.getAttribute("target") || "").toLowerCase();
        return target === "_blank";
    };

    document.addEventListener(
        "click",
        (event) => {
            const link = event.target.closest("a");
            if (!shouldIntercept(link)) {
                return;
            }
            event.preventDefault();
            event.stopPropagation();
            window.AppModal.open(link.href, { opener: link });
        },
        true
    );

    closeBtn.addEventListener("click", closeModal);

    backdrop.addEventListener("click", (event) => {
        if (event.target === backdrop) {
            closeModal();
        }
    });

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && backdrop.classList.contains("is-active")) {
            closeModal();
        }
    });

    window.AppModal = {
        open: openWithUrl,
        openHtml: openWithHtml,
        close: closeModal,
        isOpen: () => backdrop.classList.contains("is-active"),
        getIframe: () => iframe,
        getContentWindow: () => iframe.contentWindow || null,
    };

    window.openInAppModal = (url, options) => {
        window.AppModal.open(url, options || {});
    };

    window.openHtmlInAppModal = (html, options) => {
        window.AppModal.openHtml(html, options || {});
    };
})();

