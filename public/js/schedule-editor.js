document.querySelectorAll('[data-schedule-editor]').forEach((form) => {
    const toggle = form.querySelector('[data-different-schedules]');
    const panel = form.querySelector('[data-different-panel]');
    if (!toggle || !panel) return;

    const common = form.querySelectorAll('[data-common-schedule]');
    const different = form.querySelectorAll('[data-different-input]');
    const weekdays = form.querySelectorAll('input[name="weekdays[]"]');
    const apply = () => {
        panel.hidden = !toggle.checked;
        common.forEach((input) => { input.disabled = toggle.checked; });
        weekdays.forEach((input) => { input.disabled = toggle.checked; });
        different.forEach((input) => { input.disabled = !toggle.checked; });
    };
    toggle.addEventListener('change', apply);
    apply();
});
