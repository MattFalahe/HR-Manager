{{--
    Re-apply HR button styling INSIDE modals.

    HR modals are hoisted out of .hr-manager-wrapper (so the wrapper's
    dark-on-dark text cascade doesn't make the form unreadable), but that
    also means the wrapper-scoped .btn-hr-* rules never reach the modal's
    buttons, leaving Cancel / Save as bare, near-invisible Bootstrap
    buttons on the dark footer. These rules re-scope the same look to
    .modal so the buttons are visible regardless of the wrapper.

    Lives in the rendered page (not the external stylesheet) so it takes
    effect on pull without republishing assets. @once keeps it to a single
    copy even if multiple hoisted modals include it. The --hr-* variables
    are defined in :root so they resolve here; literal fallbacks cover the
    case where the stylesheet hasn't loaded.
--}}
@once
<style>
.modal .btn-hr-primary {
    background: linear-gradient(135deg, var(--hr-primary-start, #667eea) 0%, var(--hr-primary-end, #764ba2) 100%);
    border: none;
    color: var(--hr-text-white, #fff);
}
.modal .btn-hr-primary:hover {
    background: linear-gradient(135deg, #5568d3 0%, #6a3d8f 100%);
    color: var(--hr-text-white, #fff);
}
.modal .btn-hr-secondary {
    background-color: var(--hr-dark-card, #23262d);
    border: 1px solid var(--hr-border, #2c3138);
    color: var(--hr-text-light, #d1d1d1);
}
.modal .btn-hr-secondary:hover {
    background-color: #2c3138;
    border-color: rgba(102, 126, 234, 0.5);
    color: var(--hr-text-white, #fff);
}
.modal .btn-icon {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}
</style>
@endonce
