const stage = document.querySelector("#stage");
const spread = document.querySelector("#spread");
const frame = spread.parentElement;
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
let activeTurn;
let gesture;

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
    if (!activeTurn) return;
    activeTurn.preview.remove();
    frame.classList.remove("is-turning");
    activeTurn = undefined;
}

function previewSpread(images, className) {
    const layer = document.createElement("div");
    layer.className = `spread ${className}`;
    layer.classList.toggle("paired", images.length === 2);
    layer.append(...images.map((image) => image.cloneNode()));
    return layer;
}

function createTurn(direction, images) {
    clearTurn();
    const forward = direction === "next";
    const current = [...spread.children];
    const turningIndex = forward ? current.length - 1 : 0;
    const doubleSided = current.length === 2 && images.length === 2;
    const preview = document.createElement("div");
    preview.className = "turn-preview";
    preview.setAttribute("aria-hidden", "true");
    const outgoing = previewSpread(current, "outgoing-spread");
    preview.append(previewSpread(images, "incoming-spread"), outgoing);

    let leaf = outgoing.children[turningIndex];
    const other = forward ? leaf.previousElementSibling : leaf.nextElementSibling;
    if (doubleSided) {
        const sheet = document.createElement("div");
        sheet.className = "turning-sheet";
        const bounds = current[turningIndex].getBoundingClientRect();
        const book = frame.getBoundingClientRect();
        sheet.style.left = `${bounds.left - book.left}px`;
        sheet.style.top = `${bounds.top - book.top}px`;
        sheet.style.width = `${bounds.width}px`;
        sheet.style.height = `${bounds.height}px`;
        sheet.append(leaf.cloneNode(), images[forward ? 0 : 1].cloneNode());
        leaf.style.visibility = "hidden";
        leaf = sheet;
        preview.append(sheet);
    }

    leaf.style.transformOrigin = forward ? "left center" : "right center";
    activeTurn = {
        preview, leaf, other: doubleSided ? null : other,
        angle: (forward ? -1 : 1) * (doubleSided ? 180 : 89.9),
        progress: 0,
    };
    frame.append(preview);
    frame.classList.add("is-turning");
    return activeTurn;
}

function moveTurn(flip, progress) {
    flip.progress = progress;
    flip.leaf.style.transform = `perspective(2200px) rotateY(${flip.angle * progress}deg)`;
    if (flip.other) flip.other.style.opacity = 1 - progress;
}

function settleTurn(flip, end) {
    const start = flip.progress;
    const duration = 125 + 155 * Math.abs(end - start);
    const started = performance.now();
    function advance(now) {
        if (activeTurn !== flip) return;
        const time = Math.min(1, (now - started) / duration);
        const eased = 1 - (1 - time) ** 3;
        moveTurn(flip, start + (end - start) * eased);
        if (time < 1) requestAnimationFrame(advance);
        else clearTurn();
    }
    requestAnimationFrame(advance);
}

function canAnimate() {
    return !reducedMotion.matches && !stage.classList.contains("zoomed") &&
        [...spread.children].every((image) => image.complete && image.naturalWidth);
}

function updateControls(pages) {
    pageSelect.value = shownPage;
    previous.disabled = pages[0] === 1;
    next.disabled = pages.at(-1) === total;
    status.textContent = pages.length === 2
        ? `Pages ${pages[0]} and ${pages[1]} of ${total}`
        : `Page ${pages[0]} of ${total}`;
}

async function render(direction, dragTurn) {
    resetGesture();
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

        if (!dragTurn || dragTurn !== activeTurn) {
            clearTurn();
            if (direction && canAnimate()) settleTurn(createTurn(direction, images), 1);
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

function turnTarget(direction, number) {
    const pages = visiblePages(number);
    return direction === "next" ? pages.at(-1) + 1 : pages[0] - 1;
}

function turn(direction, dragTurn) {
    const target = turnTarget(direction, page);
    if (target < 1 || target > total) return;

    page = target;
    stage.scrollTo(0, 0);
    render(direction, dragTurn);
}

previous.addEventListener("click", () => turn("previous"));
next.addEventListener("click", () => turn("next"));
desktop.addEventListener("change", () => {
    clearTurn();
    render();
});
reducedMotion.addEventListener("change", () => {
    resetGesture();
    clearTurn();
});
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

function resetGesture() {
    if (!gesture) return;
    const current = gesture;
    gesture = undefined;
    stage.classList.remove("is-grabbing");
    if (stage.hasPointerCapture(current.id)) stage.releasePointerCapture(current.id);
    if (current.flip === activeTurn) clearTurn();
}

function dragDistance(current) {
    return current.direction === "next" ? current.startX - current.x : current.x - current.startX;
}

function scrubGesture(current) {
    const distance = Math.max(0, dragDistance(current));
    const grip = current.direction === "next"
        ? current.startX - current.left
        : current.left + current.width - current.startX;
    const travel = Math.max(current.width * 0.35, grip) * Math.abs(current.flip.angle) / 90;
    const fraction = Math.min(1, distance / travel);
    // The sheet's projected width follows cosine as it rotates around the spine.
    const cosine = Math.cos(Math.abs(current.flip.angle) * Math.PI / 180);
    const progress = Math.acos(1 - (1 - cosine) * fraction) / (Math.abs(current.flip.angle) * Math.PI / 180);
    moveTurn(current.flip, progress);
}

stage.addEventListener("pointerdown", (event) => {
    if (!event.isPrimary || event.button !== 0 || page !== shownPage ||
        stage.classList.contains("zoomed") || !event.target.matches("#spread img")) return;

    const paired = spread.classList.contains("paired");
    const bounds = event.target.getBoundingClientRect();
    const previousSide = paired
        ? event.target === spread.firstElementChild
        : shownPage > 1 && event.clientX < bounds.left + bounds.width / 2;
    gesture = {
        id: event.pointerId, startX: event.clientX, startY: event.clientY,
        x: event.clientX, left: bounds.left, width: bounds.width, paired,
        clickDirection: previousSide ? "previous" : "next",
    };
    stage.setPointerCapture(event.pointerId);
    stage.classList.add("is-grabbing");
});

stage.addEventListener("pointermove", async (event) => {
    const current = gesture;
    if (!current || current.id !== event.pointerId) return;

    current.x = event.clientX;
    if (current.flip) {
        scrubGesture(current);
        return;
    }
    if (current.direction || Math.abs(current.x - current.startX) < 8 ||
        Math.abs(current.x - current.startX) <= Math.abs(event.clientY - current.startY)) return;

    current.direction = current.paired
        ? current.clickDirection
        : current.x < current.startX ? "next" : "previous";
    const target = turnTarget(current.direction, shownPage);
    if (target < 1 || target > total || !canAnimate()) return;

    let images;
    try {
        images = await Promise.all(visiblePages(target).map(preparePage));
    } catch {
        return;
    }
    if (gesture !== current) return;
    current.flip = createTurn(current.direction, images);
    scrubGesture(current);
});

function endGesture(event, cancelled) {
    const current = gesture;
    if (!current || current.id !== event.pointerId) return;

    gesture = undefined;
    stage.classList.remove("is-grabbing");
    current.x = event.clientX;
    const click = !current.direction && Math.hypot(
        current.x - current.startX, event.clientY - current.startY,
    ) < 8;
    if (!cancelled && click) {
        turn(current.clickDirection);
        return;
    }

    const threshold = event.pointerType === "touch" ? 48 : 72;
    const commit = !cancelled && current.direction &&
        dragDistance(current) >= Math.min(threshold, current.width * 0.22);
    if (current.flip) settleTurn(current.flip, commit ? 1 : 0);
    if (commit) turn(current.direction, current.flip);
}

stage.addEventListener("pointerup", (event) => endGesture(event, false));
stage.addEventListener("pointercancel", (event) => endGesture(event, true));
stage.addEventListener("lostpointercapture", (event) => endGesture(event, true));

updateControls([1]);
toolbar.hidden = false;
window.addEventListener("load", () => {
    if (revision === 0) prepareNeighbours([1]);
}, { once: true });
