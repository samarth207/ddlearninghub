"use strict";

const menuToggle = document.querySelector(".menu-toggle");
const siteNav = document.querySelector("#site-nav");

if (menuToggle && siteNav) {
    menuToggle.addEventListener("click", () => {
        const isOpen = menuToggle.getAttribute("aria-expanded") === "true";
        menuToggle.setAttribute("aria-expanded", String(!isOpen));
        siteNav.classList.toggle("is-open", !isOpen);
    });
}

document.querySelectorAll("form[data-confirm]").forEach((form) => {
    form.addEventListener("submit", (event) => {
        if (!window.confirm(form.dataset.confirm || "Are you sure you want to continue?")) {
            event.preventDefault();
        }
    });
});

const videoUrlInput = document.querySelector("#lesson-video-url");
const videoLinkFeedback = document.querySelector("#video-link-feedback");

if (videoUrlInput && videoLinkFeedback) {
    const updateVideoLinkFeedback = () => {
        const value = videoUrlInput.value.trim();
        if (!value) {
            videoLinkFeedback.textContent = "Paste a YouTube or Google Drive link. It will be validated and converted when saved.";
            videoLinkFeedback.classList.remove("form-help-error");
            return;
        }

        try {
            const url = new URL(value);
            const youtubeHosts = ["youtube.com", "www.youtube.com", "m.youtube.com", "youtu.be", "www.youtu.be"];
            const driveHosts = ["drive.google.com", "docs.google.com"];
            if (url.protocol !== "https:") {
                throw new Error("Use a secure HTTPS link.");
            }
            if (youtubeHosts.includes(url.hostname.toLowerCase())) {
                videoLinkFeedback.textContent = "YouTube link detected. The video ID will be checked when saved.";
            } else if (driveHosts.includes(url.hostname.toLowerCase())) {
                videoLinkFeedback.textContent = "Google Drive link detected. The file ID will be checked when saved.";
            } else {
                throw new Error("Use a YouTube or Google Drive link.");
            }
            videoLinkFeedback.classList.remove("form-help-error");
        } catch (error) {
            videoLinkFeedback.textContent = error instanceof Error ? error.message : "Enter a valid YouTube or Google Drive link.";
            videoLinkFeedback.classList.add("form-help-error");
        }
    };

    videoUrlInput.addEventListener("input", updateVideoLinkFeedback);
    updateVideoLinkFeedback();
}
