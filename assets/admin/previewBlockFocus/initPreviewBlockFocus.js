// @flow

let isProgrammaticAction = false;

function runProgrammatically(fn: () => void): void {
    isProgrammaticAction = true;
    try {
        fn();
    } finally {
        setTimeout(() => {
            isProgrammaticAction = false;
        }, 50);
    }
}

function findFiberWithBlockId(dom: HTMLElement): ?{id: string, props: Object} {
    const key = Object.keys(dom).find(
        (k) => k.startsWith('__reactFiber$') || k.startsWith('__reactInternalInstance$')
    );
    if (!key) {
        return null;
    }

    let fiber = (dom: any)[key];
    for (let i = 0; i < 12 && fiber; i++) {
        const memoizedId = fiber.memoizedProps && fiber.memoizedProps.value && fiber.memoizedProps.value._id;
        const pendingId = fiber.pendingProps && fiber.pendingProps.value && fiber.pendingProps.value._id;
        const id = memoizedId || pendingId;
        if (typeof id === 'string' && id.length > 0) {
            return {id, props: fiber.memoizedProps || fiber.pendingProps};
        }
        fiber = fiber.return;
    }

    return null;
}

function getAllBlockSections(): Array<HTMLElement> {
    return (Array.from(
        document.querySelectorAll('[class*="sortableBlockList"] section[role="switch"]')
    ).filter((el) => el instanceof HTMLElement): any);
}

function findBlockSectionById(id: string): ?HTMLElement {
    const blocks = getAllBlockSections();
    for (let i = 0; i < blocks.length; i++) {
        const el = blocks[i];
        const fiberInfo = findFiberWithBlockId(el);
        if (fiberInfo && fiberInfo.id === id) {
            return el;
        }
    }

    return null;
}

function getOwnHeader(block: HTMLElement): ?HTMLElement {
    const contentDiv = block.querySelector(':scope > [class*="content"]');
    if (contentDiv instanceof HTMLElement) {
        const header = contentDiv.querySelector(':scope > header');
        if (header instanceof HTMLElement) {
            return header;
        }
    }

    const fallback = block.querySelector('header');
    return fallback instanceof HTMLElement ? fallback : null;
}

function getOwnCollapseButton(block: HTMLElement): ?HTMLElement {
    const header = getOwnHeader(block);
    if (!header) {
        return null;
    }

    const icon = header.querySelector('[aria-label="su-collapse-vertical"]');
    if (!icon) {
        return null;
    }

    const btn = typeof icon.closest === 'function' ? icon.closest('button') : null;
    return btn instanceof HTMLElement ? btn : null;
}

function isBlockExpanded(block: HTMLElement): boolean {
    if (getOwnCollapseButton(block) !== null) {
        return true;
    }

    return typeof block.className === 'string' && block.className.indexOf('expanded') !== -1;
}

function ensureContainingTabPanelsActive(element: HTMLElement): void {
    let current: ?HTMLElement = element.parentElement;
    while (current && current !== document.body) {
        if (current.getAttribute('role') === 'tabpanel') {
            const tabId = current.getAttribute('aria-labelledby');
            if (tabId) {
                const tabBtn = document.getElementById(tabId);
                if (
                    tabBtn instanceof HTMLElement &&
                    tabBtn.getAttribute('aria-selected') !== 'true'
                ) {
                    runProgrammatically(() => {
                        tabBtn.click();
                    });
                }
            }
        }
        current = current.parentElement;
    }
}

function expandBlockSection(block: HTMLElement): void {
    ensureContainingTabPanelsActive(block);
    if (!isBlockExpanded(block)) {
        runProgrammatically(() => {
            block.click();
        });
    }
}

function collapseBlockSection(block: HTMLElement): void {
    const collapseBtn = getOwnCollapseButton(block);
    if (collapseBtn) {
        runProgrammatically(() => {
            collapseBtn.click();
        });
    }
}

function getScrollParent(element: HTMLElement): HTMLElement | Element {
    let style = window.getComputedStyle(element);
    const excludeStaticParent = style.position === 'absolute';
    const overflowRegex = /(auto|scroll)/;

    if (style.position === 'fixed') {
        return document.scrollingElement || document.documentElement || document.body;
    }

    for (let parent = element.parentElement; parent; parent = parent.parentElement) {
        style = window.getComputedStyle(parent);
        if (excludeStaticParent && style.position === 'static') {
            continue;
        }
        if (overflowRegex.test(style.overflow + style.overflowY + style.overflowX)) {
            return parent;
        }
    }

    return document.scrollingElement || document.documentElement || document.body;
}

function scrollAdminToBlock(target: HTMLElement): void {
    setTimeout(() => {
        const scrollContainer = getScrollParent(target);
        const containerRect = scrollContainer.getBoundingClientRect();
        const targetRect = target.getBoundingClientRect();
        const offset = targetRect.top - containerRect.top + scrollContainer.scrollTop - 24;

        if (typeof scrollContainer.scrollTo === 'function') {
            scrollContainer.scrollTo({
                top: Math.max(0, offset),
                behavior: 'smooth',
            });
        }
    }, 120);
}

function waitForBlockInDom(id: string, maxAttempts: number = 15): Promise<?HTMLElement> {
    return new Promise((resolve) => {
        let attempts = 0;
        const check = () => {
            const found = findBlockSectionById(id);
            if (found) {
                resolve(found);
                return;
            }
            attempts += 1;
            if (attempts >= maxAttempts) {
                resolve(null);
                return;
            }
            setTimeout(check, 25);
        };
        check();
    });
}

async function expandChainAndFocus(ids: Array<string>): Promise<void> {
    let lastMatched: ?HTMLElement = null;

    for (let i = 0; i < ids.length; i++) {
        const id = ids[i];
        if (!id) {
            continue;
        }

        // If we already expanded a parent block that uses TabSection and the active tab wasn't 'content',
        // make sure the 'content' tab inside that parent block is selected so nested blocks mount.
        if (lastMatched) {
            const contentTabBtn = lastMatched.querySelector('button[role="tab"][id$="-tab-content"]');
            if (
                contentTabBtn instanceof HTMLElement &&
                contentTabBtn.getAttribute('aria-selected') !== 'true'
            ) {
                runProgrammatically(() => {
                    contentTabBtn.click();
                });
            }
        }

        const blockEl = await waitForBlockInDom(id, i === 0 ? 8 : 24);
        if (!blockEl) {
            continue;
        }

        expandBlockSection(blockEl);
        lastMatched = blockEl;
    }

    if (!lastMatched) {
        return;
    }

    const finalTarget: HTMLElement = lastMatched;

    // Collapse all unrelated expanded blocks that do not contain finalTarget
    const allBlocks = getAllBlockSections();
    allBlocks.forEach((block) => {
        if (block !== finalTarget && !block.contains(finalTarget) && isBlockExpanded(block)) {
            collapseBlockSection(block);
        }
    });

    scrollAdminToBlock(finalTarget);
}

function postFocusToPreviewIframes(blockId: string): void {
    const iframes = Array.from(document.querySelectorAll('iframe'));
    iframes.forEach((iframe) => {
        if (iframe instanceof HTMLIFrameElement && iframe.contentWindow) {
            try {
                iframe.contentWindow.postMessage(
                    {
                        type: 'sulu-admin-block-focus',
                        id: blockId,
                    },
                    '*'
                );
            } catch (e) {}
        }
    });
}

export default function initPreviewBlockFocus(): void {
    if (typeof window === 'undefined' || (window: any).__suluBlockSuitePreviewBlockFocusRegistered) {
        return;
    }
    (window: any).__suluBlockSuitePreviewBlockFocusRegistered = true;

    // 1. Preview -> Admin: When user clicks a block's focus icon in Sulu Live Preview
    window.addEventListener('message', (event: MessageEvent) => {
        const data: any = event.data;
        if (!data || data.type !== 'sulu-preview-block-click') {
            return;
        }

        const ids: Array<string> = Array.isArray(data.ids) && data.ids.length > 0
            ? data.ids.filter((item) => typeof item === 'string' && item.length > 0)
            : (typeof data.id === 'string' && data.id.length > 0 ? [data.id] : []);

        if (ids.length === 0) {
            return;
        }

        expandChainAndFocus(ids);
    });

    // 2. Admin -> Preview: When user clicks a block in the left Admin panel to open/focus it
    document.addEventListener(
        'click',
        (event: MouseEvent) => {
            if (isProgrammaticAction || !event.isTrusted) {
                return;
            }

            const rawTarget = event.target;
            if (!(rawTarget instanceof Element)) {
                return;
            }

            // Ignore clicks on buttons, selects, inputs, tabs, or drag handles
            if (
                rawTarget.closest('button') ||
                rawTarget.closest('input') ||
                rawTarget.closest('textarea') ||
                rawTarget.closest('select') ||
                rawTarget.closest('[role="tab"]') ||
                rawTarget.closest('[class*="handle"]') ||
                rawTarget.closest('[class*="types"]') ||
                rawTarget.closest('[class*="iconButtons"]')
            ) {
                return;
            }

            const blockSection = rawTarget.closest('[class*="sortableBlockList"] section[role="switch"]');
            if (!(blockSection instanceof HTMLElement)) {
                return;
            }

            // Only trigger when clicking a collapsed block or clicking the block's own header
            const ownHeader = getOwnHeader(blockSection);
            const clickedOwnHeader = ownHeader ? ownHeader.contains(rawTarget) : false;
            const wasExpanded = isBlockExpanded(blockSection);

            if (wasExpanded && !clickedOwnHeader) {
                return;
            }

            const fiberInfo = findFiberWithBlockId(blockSection);
            if (!fiberInfo || !fiberInfo.id) {
                return;
            }

            const targetId = fiberInfo.id;

            // Defer sibling collapse and preview focus until after React finishes handling the click
            setTimeout(() => {
                const parentList = blockSection.closest('[class*="sortableBlockList"]');
                if (parentList instanceof HTMLElement) {
                    const siblingBlocks = Array.from(
                        parentList.querySelectorAll(':scope > section[role="switch"]')
                    );
                    siblingBlocks.forEach((sibling) => {
                        if (
                            sibling instanceof HTMLElement &&
                            sibling !== blockSection &&
                            !sibling.contains(blockSection) &&
                            isBlockExpanded(sibling)
                        ) {
                            collapseBlockSection(sibling);
                        }
                    });
                }

                postFocusToPreviewIframes(targetId);
            }, 0);
        },
        true
    );
}
