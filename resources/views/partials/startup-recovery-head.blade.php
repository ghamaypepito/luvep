{{-- Kept in the HTML so recovery works when the stylesheet or JS bundle cannot download. --}}
<style>
    #luvep-startup { position: fixed; inset: 0; z-index: 2147483647; display: grid; place-items: center; padding: 24px; box-sizing: border-box; background: #f7f9f7; color: #193c34; font: 16px/1.5 system-ui, sans-serif; }
    #luvep-startup[hidden], #luvep-startup button[hidden] { display: none; }
    #luvep-startup .startup-card { width: 100%; max-width: 420px; text-align: center; }
    #luvep-startup .startup-brand { margin: 0 0 28px; font-size: 24px; font-weight: 700; }
    #luvep-startup h1 { margin: 0 0 12px; font-size: 23px; line-height: 1.3; }
    #luvep-startup .startup-detail { margin: 0; color: #53675f; }
    #luvep-startup button { margin-top: 24px; padding: 12px 20px; border: 0; border-radius: 10px; background: #193c34; color: white; font: inherit; cursor: pointer; }
    #luvep-startup button:focus-visible { outline: 3px solid #b39547; outline-offset: 4px; }
</style>
<script>
    (function () {
        var finished = false;
        var crashed = false;
        var state = 'loading';
        var timer;
        function render() {
            var panel = document.getElementById('luvep-startup');
            if (!panel) return;
            panel.hidden = finished;
            panel.setAttribute('aria-busy', state === 'loading' && !finished ? 'true' : 'false');
            var retry = document.getElementById('luvep-startup-retry');
            retry.hidden = state === 'loading';
            retry.onclick = function () { window.location.reload(); };
            if (state === 'failed') {
                document.getElementById('luvep-startup-title').textContent = 'We couldn’t finish loading Luvep.';
                document.getElementById('luvep-startup-detail').textContent = 'Your connection may have been interrupted. Reload to try again.';
            } else if (state === 'slow') {
                document.getElementById('luvep-startup-title').textContent = 'Loading is taking longer than usual.';
                document.getElementById('luvep-startup-detail').textContent = 'You can keep waiting or reload Luvep to try again.';
            }
        }
        function fail() {
            finished = false;
            state = 'failed';
            clearTimeout(timer);
            render();
        }
        function resourceError(event) {
            if (finished) return;
            var target = event.target;
            if (!target || !['SCRIPT', 'LINK'].includes(target.tagName)) return;
            var url = target.src || target.href;
            if (!url) return;
            try {
                var asset = new URL(url, window.location.href);
                if (asset.origin === window.location.origin && asset.pathname.indexOf('/build/') === 0) fail();
            } catch (_) {}
        }
        window.luvepStartup = {
            // Explicit startup/root errors are terminal until the user reloads.
            fail: function () { crashed = true; fail(); },
            ready: function () {
                if (crashed) return;
                finished = true;
                clearTimeout(timer);
                document.removeEventListener('readystatechange', render);
                window.removeEventListener('error', resourceError, true);
                window.removeEventListener('vite:preloadError', fail);
                render();
            }
        };
        window.addEventListener('error', resourceError, true);
        window.addEventListener('vite:preloadError', fail);
        // interactive fires after parsing, without waiting for deferred module downloads.
        document.addEventListener('readystatechange', render);
        document.addEventListener('DOMContentLoaded', render, { once: true });
        timer = setTimeout(function () {
            if (finished || state === 'failed') return;
            state = 'slow';
            render();
        }, 15000);
    })();
</script>
