/**
 * SPA Router for RaffleKings
 * Handles smooth, app-like navigation between core views without full page reloads.
 */
const SPARouter = {
    isNavigating: false,
    views: {}, // Stores DOM elements for each path

    init() {
        const mainContainer = document.getElementById('app-main');

        // Wrap initial content in a view container
        const initialPath = window.location.pathname + window.location.search;
        const initialView = document.createElement('div');
        initialView.className = 'spa-view w-full h-full';
        initialView.dataset.path = initialPath;

        // Move all children of app-main into the new view wrapper
        while (mainContainer.firstChild) {
            initialView.appendChild(mainContainer.firstChild);
        }
        mainContainer.appendChild(initialView);

        this.views[initialPath] = initialView;

        // Intercept clicks on SPA links
        document.addEventListener('click', (e) => {
            const link = e.target.closest('a[data-spa="true"], a.nav-item');
            if (!link) return;

            const url = new URL(link.href, window.location.origin);

            // Don't intercept if it's the exact same page including hash, or if opening in new tab
            if (url.origin !== window.location.origin ||
                (url.pathname === window.location.pathname && url.search === window.location.search) ||
                link.target === '_blank' ||
                e.ctrlKey || e.metaKey) {
                return;
            }

            e.preventDefault();
            this.navigate(url.pathname + url.search);
        });

        // Handle browser back/forward
        window.addEventListener('popstate', (e) => {
            this.navigate(window.location.pathname + window.location.search, false);
        });
    },

    async navigate(path, pushState = true) {
        if (this.isNavigating) return;
        this.isNavigating = true;

        const mainContainer = document.getElementById('app-main');
        const currentActiveView = Object.values(this.views).find(v => v.style.display !== 'none');

        try {
            let targetView = this.views[path];

            // 1. Fetch new view if not cached (while current view remains visible)
            if (!targetView) {
                const response = await fetch(path);
                if (!response.ok) throw new Error('Network response was not ok');
                const fullHtml = await response.text();

                // Extract <main id="app-main"> content
                const parser = new DOMParser();
                const doc = parser.parseFromString(fullHtml, 'text/html');
                const newMain = doc.getElementById('app-main');

                if (newMain) {
                    targetView = document.createElement('div');
                    targetView.className = 'spa-view w-full h-full';
                    targetView.dataset.path = path;
                    targetView.innerHTML = newMain.innerHTML;
                    targetView.style.display = 'none';

                    mainContainer.appendChild(targetView);
                    this.views[path] = targetView;

                    // Execute scripts ONLY on initial load
                    this.executeScripts(targetView);
                } else {
                    // Fallback to full reload
                    window.location.href = path;
                    return;
                }
            }

            // 2. Transition Out (Hide old view)
            if (currentActiveView && currentActiveView !== targetView) {
                currentActiveView.style.transition = 'opacity 0.2s ease-out, transform 0.2s ease-out';
                currentActiveView.style.opacity = '0';
                currentActiveView.style.transform = 'translateY(10px)';
                await new Promise(r => setTimeout(r, 200));
                currentActiveView.style.display = 'none';
            }

            // 3. Update History
            if (pushState) {
                history.pushState({ path }, '', path);
            }

            // 4. Re-initialize UI global state
            if (typeof lucide !== 'undefined' && lucide.createIcons) {
                lucide.createIcons();
            }
            if (typeof setupActiveNavigation === 'function') {
                setupActiveNavigation();
            }
            if (typeof setupExitTrap === 'function') {
                setupExitTrap();
            }

            // Scroll to top
            window.scrollTo({ top: 0, behavior: 'instant' });

            // 5. Transition In (Show new view)
            if (currentActiveView !== targetView) {
                targetView.style.display = 'block';
                targetView.style.opacity = '0';
                targetView.style.transform = 'translateY(10px)';

                requestAnimationFrame(() => {
                    targetView.style.transition = 'opacity 0.2s ease-out, transform 0.2s ease-out';
                    targetView.style.opacity = '1';
                    targetView.style.transform = 'translateY(0)';

                    setTimeout(() => {
                        targetView.style.transition = '';
                        this.isNavigating = false;
                    }, 200);
                });
            } else {
                this.isNavigating = false;
            }

        } catch (error) {
            console.error('SPA Navigation failed:', error);
            window.location.href = path; // Fallback to normal navigation
        }
    },

    executeScripts(container) {
        const scripts = container.querySelectorAll('script');
        scripts.forEach(oldScript => {
            const newScript = document.createElement('script');

            Array.from(oldScript.attributes).forEach(attr => {
                newScript.setAttribute(attr.name, attr.value);
            });

            if (oldScript.innerHTML) {
                newScript.innerHTML = oldScript.innerHTML;
            }

            oldScript.parentNode.replaceChild(newScript, oldScript);
        });
    }
};

// Initialize the router
document.addEventListener('DOMContentLoaded', () => {
    SPARouter.init();
});
