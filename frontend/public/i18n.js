const i18n = {
  dict: {},
  lang: 'ja',
  async load(lang = null) {
    const saved = localStorage.getItem('pfn-lang');
    this.lang = lang || saved || (navigator.language?.toLowerCase().startsWith('ja') ? 'ja' : 'en');
    if (!['ja', 'en'].includes(this.lang)) this.lang = 'ja';
    const response = await fetch(`/i18n/${this.lang}.json`);
    this.dict = await response.json();
    document.documentElement.lang = this.lang;
    localStorage.setItem('pfn-lang', this.lang);
  },
  t(key, vars = {}) {
    let value = this.dict[key] || key;
    for (const [name, replacement] of Object.entries(vars)) {
      value = value.replaceAll(`{${name}}`, String(replacement));
    }
    return value;
  },
  translatePage() {
    document.querySelectorAll('[data-i18n]').forEach(el => {
      el.textContent = this.t(el.dataset.i18n);
    });
    document.querySelectorAll('[data-i18n-placeholder]').forEach(el => {
      el.placeholder = this.t(el.dataset.i18nPlaceholder);
    });
  },
};
