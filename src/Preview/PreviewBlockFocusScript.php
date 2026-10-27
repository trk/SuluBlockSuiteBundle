<?php

declare(strict_types=1);

namespace Totoglu\SuluBlockSuiteBundle\Preview;

/**
 * Self-contained website iframe script for Sulu Live Preview Block Focus
 * (hover indicator, click-to-focus admin event, and admin-to-preview scroll handler).
 */
final class PreviewBlockFocusScript
{
    public static function render(): string
    {
        return <<<'HTML'
<script data-sulu-preview-block-focus="1">
(function () {
    if (window.parent === window) {
        return;
    }
    if (typeof window.__suluBlockSuitePreviewFocusCleanup === 'function') {
        try {
            window.__suluBlockSuitePreviewFocusCleanup();
        } catch (err) {}
    }
    window.__suluBlockSuitePreviewFocusInitialized = true;

    var activeBlock = null;
    var hoverIndicator = document.createElement('div');
    Object.assign(hoverIndicator.style, {
        position: 'absolute',
        width: '6px',
        backgroundColor: '#6db4c7',
        borderRadius: '2px',
        pointerEvents: 'none',
        zIndex: '99998',
        display: 'none',
        transition: 'top 0.1s ease, left 0.1s ease, height 0.1s ease'
    });

    var focusIcon = document.createElement('button');
    focusIcon.type = 'button';
    focusIcon.title = 'Focus block in editor';
    focusIcon.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"></circle><path d="M12 2v3m0 14v3m10-10h-3M5 12H2m15.07-7.07-2.12 2.12M9.05 14.95l-2.12 2.12m0-12.14 2.12 2.12m7.9 7.9 2.12 2.12"></path></svg>';
    Object.assign(focusIcon.style, {
        position: 'absolute',
        width: '27px',
        height: '26px',
        borderRadius: '3px',
        background: '#6db4c7',
        color: '#fff',
        border: 'none',
        display: 'none',
        alignItems: 'center',
        justifyContent: 'center',
        cursor: 'pointer',
        zIndex: '99999',
        boxShadow: '0 2px 6px rgba(0, 0, 0, 0.2)',
        transition: 'opacity 0.15s ease'
    });

    function ensureAttached() {
        if (!document.body) {
            return false;
        }
        if (!document.body.contains(hoverIndicator)) {
            document.body.appendChild(hoverIndicator);
        }
        if (!document.body.contains(focusIcon)) {
            document.body.appendChild(focusIcon);
        }
        return true;
    }

    function positionIndicators(target) {
        if (!target || !ensureAttached()) {
            return;
        }
        activeBlock = target;
        var rect = target.getBoundingClientRect();

        hoverIndicator.style.top = (window.scrollY + rect.top) + 'px';
        hoverIndicator.style.left = (window.scrollX + Math.max(0, rect.left)) + 'px';
        hoverIndicator.style.height = rect.height + 'px';
        hoverIndicator.style.display = 'block';

        focusIcon.style.top = (window.scrollY + rect.top + 6) + 'px';
        focusIcon.style.left = (window.scrollX + Math.max(0, rect.left) + 8) + 'px';
        focusIcon.style.display = 'flex';
    }

    function hideIndicators() {
        hoverIndicator.style.display = 'none';
        focusIcon.style.display = 'none';
        activeBlock = null;
    }

    focusIcon.addEventListener('click', function (e) {
        e.stopPropagation();
        e.preventDefault();
        if (!activeBlock) {
            return;
        }

        var ids = [];
        var current = activeBlock;
        while (current && current !== document.body) {
            if (current.hasAttribute('data-block-id')) {
                var bid = current.getAttribute('data-block-id');
                if (bid && ids.indexOf(bid) === -1) {
                    ids.unshift(bid);
                }
            }
            current = current.parentElement;
        }

        if (ids.length === 0) {
            return;
        }

        var targetOrigin = '*';
        if (document.referrer) {
            try {
                targetOrigin = new URL(document.referrer).origin;
            } catch (err) {
                targetOrigin = '*';
            }
        }

        window.parent.postMessage(
            {
                type: 'sulu-preview-block-click',
                id: ids[ids.length - 1],
                ids: ids
            },
            targetOrigin
        );
    });

    function onMouseOver(e) {
        var target = e.target && typeof e.target.closest === 'function' ? e.target.closest('[data-block-id]') : null;
        if (!target) {
            return;
        }
        positionIndicators(target);
    }

    function onMouseMove(e) {
        if (!activeBlock) {
            return;
        }
        var rect = activeBlock.getBoundingClientRect();
        var insideBlock = e.clientX >= rect.left && e.clientX <= rect.right && e.clientY >= rect.top && e.clientY <= rect.bottom;
        var overIcon = focusIcon.contains(e.target);

        if (!insideBlock && !overIcon) {
            hideIndicators();
        }
    }

    function onAdminMessage(e) {
        var data = e.data;
        if (!data || data.type !== 'sulu-admin-block-focus' || typeof data.id !== 'string' || !data.id) {
            return;
        }

        var selector = '[data-block-id="' + data.id.replace(/"/g, '\\"') + '"]';
        var target = document.querySelector(selector);
        if (!target) {
            return;
        }

        target.scrollIntoView({ behavior: 'smooth', block: 'center' });
        positionIndicators(target);
        setTimeout(function () {
            positionIndicators(target);
        }, 350);
    }

    document.addEventListener('mouseover', onMouseOver);
    document.addEventListener('mousemove', onMouseMove);
    window.addEventListener('scroll', hideIndicators, { passive: true });
    window.addEventListener('message', onAdminMessage);

    window.__suluBlockSuitePreviewFocusCleanup = function () {
        document.removeEventListener('mouseover', onMouseOver);
        document.removeEventListener('mousemove', onMouseMove);
        window.removeEventListener('scroll', hideIndicators);
        window.removeEventListener('message', onAdminMessage);
        if (hoverIndicator.parentNode) {
            hoverIndicator.parentNode.removeChild(hoverIndicator);
        }
        if (focusIcon.parentNode) {
            focusIcon.parentNode.removeChild(focusIcon);
        }
    };
})();
</script>
HTML;
    }
}
