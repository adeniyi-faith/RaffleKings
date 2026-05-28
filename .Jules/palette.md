## 2026-05-20 - Add aria-labels to toggle password buttons
**Learning:** Found a missing aria-label pattern for icon-only password toggle buttons across multiple auth-related pages (login, register, register-special, reset-password). Adding these improves accessibility.
**Action:** Next time looking for accessibility improvements, check icon-only buttons across multiple related flows (like authentication) to ensure a consistent fix.
## 2024-05-18 - ARIA Live Regions for Error Messages
**Learning:** Screen readers need context cues for dynamic elements that appear without page reloads (like error or success messages in SPA/fetch forms). While `role="alert"` implies `aria-live="assertive"`, explicitly providing these attributes ensures that status updates are announced reliably.
**Action:** When creating or modifying dynamic message containers (like error banners or status text), always ensure they use `role="alert"` and an appropriate `aria-live` attribute (`assertive` for errors, `polite` for non-critical status updates) so they are read aloud by screen readers.
