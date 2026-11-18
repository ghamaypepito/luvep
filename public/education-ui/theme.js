(() => {
  const key = 'education-theme';
  const system = window.matchMedia('(prefers-color-scheme: dark)');
  let preference;
  try { preference = localStorage.getItem(key); } catch (_) {}
  const apply = () => {
    const dark = preference === 'dark' || (preference !== 'light' && system.matches);
    document.documentElement.dataset.educationTheme = dark ? 'dark' : 'light';
    document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
    document.querySelectorAll('[data-education-theme-switch]').forEach(button => {
      button.setAttribute('aria-checked', String(dark));
    });
  };
  apply();
  system.addEventListener('change', apply);
  window.addEventListener('storage', event => {
    if (event.key === key || event.key === null) {
      preference = event.newValue;
      apply();
    }
  });
  document.addEventListener('DOMContentLoaded', () => {
    apply();
    document.querySelectorAll('[data-education-theme-switch]').forEach(button => {
      button.addEventListener('click', () => {
        preference = document.documentElement.dataset.educationTheme === 'dark' ? 'light' : 'dark';
        try { localStorage.setItem(key, preference); } catch (_) {}
        apply();
      });
    });
  });
})();
