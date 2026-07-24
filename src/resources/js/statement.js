(function () {
    'use strict';

    /* This is a plain CP form: it posts, redirects, and shows Craft's own
       flash notices. Rows are rendered by Twig macros and added or removed
       with a real submit, not assembled here. JS only hides sections that
       don't apply. */

    const root = document.getElementById('accessibility-audit-statement');
    if (!root) return;

    /* The enforcement section is compulsory in some jurisdictions and pointless
       in others, so it follows the profile rather than sitting there confusing
       everyone on a generic statement. */
    const profileEl = document.getElementById('profile');
    const enforcementPane = root.querySelector('[data-requires-enforcement]');

    function syncEnforcementVisibility() {
        if (!profileEl || !enforcementPane) return;

        enforcementPane.hidden = profileEl.value !== 'eu' && profileEl.value !== 'uk';
    }

    if (profileEl) {
        profileEl.addEventListener('change', syncEnforcementVisibility);
        syncEnforcementVisibility();
    }

    /* Likewise the evaluator name only matters for a third-party claim. */
    const methodEl = document.getElementById('preparationMethod');
    const preparedByField = document.getElementById('preparedBy')?.closest('.field');

    function syncPreparedByVisibility() {
        if (!methodEl || !preparedByField) return;

        preparedByField.hidden = methodEl.value !== 'thirdParty';
    }

    if (methodEl) {
        methodEl.addEventListener('change', syncPreparedByVisibility);
        syncPreparedByVisibility();
    }

    /* A required field inside a collapsed section is the worst way to fail a
       save, so reveal enforcement if the server just complained about it. */
    if (enforcementPane && document.querySelector('.flash-error, .error')) {
        const body = document.getElementById('enforcementBody');

        if (body && !body.value.trim()) {
            enforcementPane.hidden = false;
        }
    }

    /* Removing an entry is a real submit (the row is gone once the redirect
       lands), so put a confirm in front of it. Delegated: rows re-render on
       every round-trip. */
    root.addEventListener('click', (e) => {
        const btn = e.target.closest('button[name="removeEntry"]');

        if (btn && !window.confirm(btn.dataset.confirm || 'Remove this entry?')) {
            e.preventDefault();
        }
    });
})();
