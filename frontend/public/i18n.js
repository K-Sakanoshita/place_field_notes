// i18n.js – シンプルなローカライズ
const i18n = {
  dict: {},
  lang: 'ja',
  async load() {
    const res = await fetch(`i18n/${this.lang}.json`);
    this.dict = await res.json();
  },
  t(key) {
    return this.dict[key] || key;
  },
  translatePage() {
    document.querySelectorAll('[data-i18n]').forEach(el => {
      const key = el.getAttribute('data-i18n');
      el.textContent = this.t(key);
    });
  }
};
