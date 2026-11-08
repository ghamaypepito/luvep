const stage = document.querySelector("#stage");
const spread = document.querySelector("#spread");
const toolbar = document.querySelector("#toolbar");
const previous = document.querySelector("#previous");
const next = document.querySelector("#next");
const zoom = document.querySelector("#zoom");
const pageSelect = document.querySelector("#page");
const status = document.querySelector("#page-status");
const desktop = matchMedia("(min-width: 760px)");
const total = pageSelect.options.length;
let page = 1;
let touchStart;

function visiblePages() {
    if (page === 1 || !desktop.matches || stage.classList.contains("zoomed")) {
        return [page];
    }

    const first = page - (page % 2);
    return first < total ? [first, first + 1] : [first];
}

function render(direction) {
    const pages = visiblePages();
    const currentPages = spread.dataset.pages || "1";
    if (pages.join(",") !== currentPages) {
        const images = pages.map((number) => {
            const image = new Image(1132, 1600);
            image.src = `/program-guide/pages/${String(number).padStart(2, "0")}.webp`;
            image.alt = number === 1
                ? "Cover of the International Student Program Guide"
                : `International Student Program Guide, page ${number}`;
            image.draggable = false;
            return image;
        });
        spread.dataset.direction = direction || "";
        spread.dataset.pages = pages.join(",");
        spread.classList.toggle("paired", pages.length === 2);
        spread.replaceChildren(...images);
    }

    pageSelect.value = page;
    previous.disabled = pages[0] === 1;
    next.disabled = pages.at(-1) === total;
    status.textContent = pages.length === 2
        ? `Pages ${pages[0]} and ${pages[1]} of ${total}`
        : `Page ${pages[0]} of ${total}`;
}

function turn(direction) {
    const pages = visiblePages();
    const target = direction === "next" ? pages.at(-1) + 1 : pages[0] - 1;
    if (target < 1 || target > total) return;

    page = target;
    stage.scrollTo(0, 0);
    render(direction);
}

previous.addEventListener("click", () => turn("previous"));
next.addEventListener("click", () => turn("next"));
desktop.addEventListener("change", () => render());
pageSelect.addEventListener("change", () => {
    page = Number(pageSelect.value);
    stage.scrollTo(0, 0);
    render();
});

zoom.addEventListener("click", () => {
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

render();
toolbar.hidden = false;
