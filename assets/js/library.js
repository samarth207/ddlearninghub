"use strict";

document.querySelectorAll("[data-library-card]").forEach((card) => {
    const preview = card.querySelector("[data-library-preview]");
    const button = card.querySelector("[data-library-copy]");
    const status = card.querySelector("[data-copy-status]");
    if (!preview || !button || !status) return;

    const original = preview.textContent;
    const inputs = card.querySelectorAll("[data-placeholder]");
    const update = () => {
        const values = {};
        inputs.forEach((input) => { values[input.dataset.placeholder] = input.value; });
        preview.textContent = original.replace(/\{([A-Za-z][A-Za-z0-9_]{0,49})\}/g, (match, key) => values[key] || match);
        status.textContent = "";
    };
    inputs.forEach((input) => input.addEventListener("input", update));

    const fallbackCopy = (text) => {
        const field = document.createElement("textarea");
        field.value = text;
        field.setAttribute("readonly", "");
        field.style.position = "fixed";
        field.style.left = "-9999px";
        document.body.appendChild(field);
        field.select();
        let copied = false;
        try {
            copied = document.execCommand("copy");
        } finally {
            field.remove();
            button.focus();
        }
        return copied;
    };
    button.hidden = false;
    button.addEventListener("click", async () => {
        try {
            if (navigator.clipboard && window.isSecureContext) {
                try {
                    await navigator.clipboard.writeText(preview.textContent);
                } catch (error) {
                    if (!fallbackCopy(preview.textContent)) throw error;
                }
            } else if (!fallbackCopy(preview.textContent)) {
                throw new Error("Copy unavailable");
            }
            status.textContent = "Copied!";
        } catch (error) {
            status.textContent = "Copy failed. Select and copy the text manually.";
        }
    });
});
