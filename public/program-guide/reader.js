const stage = document.querySelector("#stage");
const spread = document.querySelector("#spread");
const toolbar = document.querySelector("#toolbar");
const previous = document.querySelector("#previous");
const next = document.querySelector("#next");
const zoom = document.querySelector("#zoom");
const pageSelect = document.querySelector("#page");
const status = document.querySelector("#page-status");
const pageError = document.querySelector("#page-error");
const desktop = matchMedia("(min-width: 760px)");
const reducedMotion = matchMedia("(prefers-reduced-motion: reduce)");
const total = pageSelect.options.length;
const cover = spread.firstElementChild;
const pageImages = new Map();
let page = 1;
let shownPage = 1;
let revision = 0;
let outgoing;
let touchStart;

function visiblePages(number) {
    if (number === 1 || !desktop.matches || stage.classList.contains("zoomed")) {
        return [number];
    }

    const first = number - (number % 2);
    return first < total ? [first, first + 1] : [first];
}

function preparePage(number) {
    if (!pageImages.has(number)) {
        const image = number === 1 && cover.naturalWidth ? cover : new Image(1132, 1600);
        if (image !== cover) {
            image.alt = number === 1 ? cover.alt : `International Student Program Guide, page ${number}`;
            image.draggable = false;
            image.src = `/program-guide/pages/${String(number).padStart(2, "0")}.webp`;
        }
        const ready = image.decode().then(() => image, (error) => {
            if (pageImages.get(number) === ready) pageImages.delete(number);
            throw error;
        });
        pageImages.set(number, ready);
    }
    return pageImages.get(number);
}

function prepareNeighbours(pages) {
    const neighbours = [pages[0] - 1, pages.at(-1) + 1]
        .filter((number) => number >= 1 && number <= total)
        .flatMap(visiblePages);
    for (const number of pageImages.keys()) {
        if (!pages.includes(number) && !neighbours.includes(number)) pageImages.delete(number);
    }
    for (const number of neighbours) preparePage(number).catch(() => {});
}

function clearTurn() {
    if (!outgoing) return;
    outgoing.getAnimations({ subtree: true }).forEach((animation) => animation.cancel());
    outgoing.remove();
    outgoing = undefined;
}

function animateTurn(direction) {
    const layer = document.createElement("div");
    layer.className = `${spread.className} outgoing-spread`;
    layer.setAttribute("aria-hidden", "true");
    layer.append(...spread.children);
    spread.after(layer);
    outgoing = layer;

    const forward = direction === "next";
    const leaf = forward ? layer.lastElementChild : layer.firstElementChild;
    const other = forward ? leaf.previousElementSibling : leaf.nextElementSibling;
    leaf.style.transformOrigin = forward ? "left center" : "right center";
    const timing = { duration: 260, easing: "cubic-bezier(0.23, 1, 0.32, 1)", fill: "forwards" };
    const angle = forward ? -89.9 : 89.9;
    const turn = leaf.animate([
        { transform: "perspective(2200px) rotateY(0deg)" },
        { transform: `perspective(2200px) rotateY(${angle}deg)` },
    ], timing);
    other?.animate([{ opacity: 1 }, { opacity: 0 }], timing);
    turn.finished.then(() => {
        if (outgoing === layer) clearTurn();
    }, () => {});
}

function updateControls(pages) {
    pageSelect.value = shownPage;
    previous.disabled = pages[0] === 1;
    next.disabled = pages.at(-1) === total;
    status.textContent = pages.length === 2
        ? `Pages ${pages[0]} and ${pages[1]} of ${total}`
        : `Page ${pages[0]} of ${total}`;
}

async function render(direction) {
    const request = ++revision;
    const target = page;
    const pages = visiblePages(target);
    pageError.hidden = true;
    if (pages.join(",") !== spread.dataset.pages) {
        stage.setAttribute("aria-busy", "true");
        let images;
        try {
            images = await Promise.all(pages.map(preparePage));
        } catch {
            if (request !== revision) return;
            page = shownPage;
            pageSelect.value = shownPage;
            stage.removeAttribute("aria-busy");
            pageError.hidden = false;
            return;
        }
        if (request !== revision) return;

        clearTurn();
        const currentReady = Array.from(spread.children).every((image) => image.complete && image.naturalWidth);
        if (direction && currentReady && spread.animate && !reducedMotion.matches && !stage.classList.contains("zoomed")) {
            animateTurn(direction);
        }
        spread.replaceChildren(...images);
        spread.dataset.pages = pages.join(",");
        spread.classList.toggle("paired", pages.length === 2);
    }
    stage.removeAttribute("aria-busy");
    shownPage = target;
    updateControls(pages);
    prepareNeighbours(pages);
}

function turn(direction) {
    const pages = visiblePages(page);
    const target = direction === "next" ? pages.at(-1) + 1 : pages[0] - 1;
    if (target < 1 || target > total) return;

    page = target;
    stage.scrollTo(0, 0);
    render(direction);
}

previous.addEventListener("click", () => turn("previous"));
next.addEventListener("click", () => turn("next"));
desktop.addEventListener("change", () => {
    clearTurn();
    render();
});
reducedMotion.addEventListener("change", clearTurn);
pageSelect.addEventListener("change", () => {
    page = Number(pageSelect.value);
    stage.scrollTo(0, 0);
    render();
});

zoom.addEventListener("click", () => {
    clearTurn();
    const zoomed = stage.classList.toggle("zoomed");
    zoom.setAttribute("aria-pressed", zoomed);
    stage.tabIndex = zoomed ? 0 : -1;
    stage.scrollTo(0, 0);
    render();
});

document.addEventListener("keydown", (event) => {
    if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey ||
        event.target === pageSelect || event.target === stage) return;

    if (event.key === "ArrowLeft" || event.key === "ArrowRight") {
        event.preventDefault();
        turn(event.key === "ArrowLeft" ? "previous" : "next");
    }
});

stage.addEventListener("touchstart", (event) => {
    touchStart = event.touches.length === 1 ? event.touches[0] : null;
}, { passive: true });
stage.addEventListener("touchcancel", () => { touchStart = null; });
stage.addEventListener("touchend", (event) => {
    const start = touchStart;
    touchStart = null;
    if (!start || event.touches.length || stage.classList.contains("zoomed")) return;

    const dx = event.changedTouches[0].clientX - start.clientX;
    const dy = event.changedTouches[0].clientY - start.clientY;
    if (Math.abs(dx) > 48 && Math.abs(dx) > 1.5 * Math.abs(dy)) {
        turn(dx < 0 ? "next" : "previous");
    }
}, { passive: true });

updateControls([1]);
toolbar.hidden = false;
window.addEventListener("load", () => {
    if (revision === 0) prepareNeighbours([1]);
}, { once: true });
