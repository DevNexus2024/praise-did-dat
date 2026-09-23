const menuButton = document.querySelector('.menu-toggle');
const nav = document.querySelector('#store-nav');

if (menuButton && nav) {
  menuButton.addEventListener('click', () => {
    const open = menuButton.getAttribute('aria-expanded') !== 'true';
    menuButton.setAttribute('aria-expanded', String(open));
    menuButton.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
    nav.classList.toggle('is-open', open);
  });

  nav.querySelectorAll('a').forEach((link) => link.addEventListener('click', () => {
    menuButton.setAttribute('aria-expanded', 'false');
    menuButton.setAttribute('aria-label', 'Open menu');
    nav.classList.remove('is-open');
  }));
}

const cards = document.querySelectorAll('.reveal-card');
if ('IntersectionObserver' in window && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
  document.body.classList.add('has-motion');
  const observer = new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (entry.isIntersecting) {
        entry.target.classList.add('is-visible');
        observer.unobserve(entry.target);
      }
    });
  }, { threshold: 0.14 });
  cards.forEach((card) => observer.observe(card));
}

const hero = document.querySelector('.home-hero');
const phone = document.querySelector('.hero-phone-wrap');
let frameRequested = false;

function updateScrollDepth() {
  frameRequested = false;
  if (!hero || !phone || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  const bounds = hero.getBoundingClientRect();
  const progress = Math.max(0, Math.min(1, (window.innerHeight - bounds.top) / (window.innerHeight + bounds.height)));
  phone.style.setProperty('--phone-y', `${Math.sin(progress * Math.PI) * -17}px`);
  phone.style.setProperty('--phone-tilt', `${(progress - 0.5) * 8}deg`);
}

window.addEventListener('scroll', () => {
  if (!frameRequested) {
    frameRequested = true;
    window.requestAnimationFrame(updateScrollDepth);
  }
}, { passive: true });

const analyticsViewObserver = 'IntersectionObserver' in window
  ? new IntersectionObserver((entries, observer) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        const itemId = Number(entry.target.dataset.catalogId);
        if (itemId) sendCatalogEvent(itemId, 'view');
        observer.unobserve(entry.target);
      });
    }, { threshold: 0.2 })
  : null;

function sendCatalogEvent(itemId, eventType) {
  fetch('analytics-event.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ item_id: itemId, event_type: eventType }),
    keepalive: true,
  }).catch(() => {});
}

function textElement(tagName, className, value) {
  const element = document.createElement(tagName);
  if (className) element.className = className;
  element.textContent = value;
  return element;
}

function observeCatalogItem(element, itemId) {
  element.dataset.catalogId = String(itemId);
  if (analyticsViewObserver) analyticsViewObserver.observe(element);
  else sendCatalogEvent(itemId, 'view');
}

function makeProductCard(item, index) {
  const article = document.createElement('article');
  article.className = 'neo-product reveal-card is-visible';
  const visual = document.createElement('a');
  visual.className = `neo-product-visual neo-live-visual visual-${['waffle', 'custom', 'stickers'][index % 3]}`;
  visual.href = 'signup.html';
  visual.setAttribute('aria-label', `Explore ${item.name}`);
  visual.append(textElement('span', 'neo-badge', item.kind === 'product' ? 'MADE WITH FEELING' : 'MADE FOR YOU'));

  if (item.image_url) {
    const image = document.createElement('img');
    image.className = 'neo-live-image';
    image.src = item.image_url;
    image.alt = item.name;
    image.loading = 'lazy';
    visual.append(image);
  } else {
    const art = document.createElement('span');
    art.className = 'neo-live-art';
    art.setAttribute('aria-hidden', 'true');
    art.textContent = item.kind === 'product' ? 'p.' : '✳';
    visual.append(art);
  }
  visual.append(textElement('span', 'visual-caption', `0${index + 1} — PRAISE DID DAT`));
  visual.addEventListener('click', () => sendCatalogEvent(Number(item.id), 'click'));

  const info = document.createElement('div');
  info.className = 'neo-product-info';
  const titleBlock = document.createElement('div');
  titleBlock.append(textElement('p', '', 'PRAISE DID DAT · PRODUCT'));
  titleBlock.append(textElement('h3', '', item.name));
  titleBlock.append(textElement('small', 'neo-live-description', item.description));
  info.append(titleBlock);
  if (item.price !== null && item.price !== '') info.append(textElement('span', '', `E${Number(item.price).toFixed(2)}`));

  const action = document.createElement('a');
  action.className = 'neo-product-action';
  action.href = 'signup.html';
  action.append(textElement('span', '', 'Make it yours'), textElement('b', '', '↗'));
  action.addEventListener('click', () => sendCatalogEvent(Number(item.id), 'click'));
  article.append(visual, info, action);
  observeCatalogItem(article, Number(item.id));
  return article;
}

function makeServiceRow(item) {
  const row = document.createElement('a');
  row.className = 'neo-live-service';
  row.href = `mailto:hello@praisediddat.com?subject=${encodeURIComponent(`Enquiry: ${item.name}`)}`;
  row.append(textElement('span', '', String(item.id).padStart(2, '0')));
  const copy = document.createElement('span');
  copy.className = 'neo-live-service-copy';
  copy.append(textElement('b', '', item.name), textElement('small', '', item.description));
  row.append(copy, textElement('i', '', '↗'));
  row.addEventListener('click', () => sendCatalogEvent(Number(item.id), 'click'));
  observeCatalogItem(row, Number(item.id));
  return row;
}

async function loadLiveCatalog() {
  const productGrid = document.querySelector('.neo-product-grid');
  const serviceList = document.querySelector('.neo-service-list');
  if (!productGrid || !serviceList) return;
  try {
    const response = await fetch('catalog-api.php', { headers: { Accept: 'application/json' } });
    if (!response.ok) return;
    const catalog = await response.json();
    if (!Array.isArray(catalog.products) || !Array.isArray(catalog.services)) return;

    productGrid.replaceChildren();
    catalog.products.forEach((item, index) => productGrid.append(makeProductCard(item, index)));
    if (!catalog.products.length) productGrid.append(textElement('p', 'neo-empty-catalog', 'More good things are on the way.'));

    serviceList.replaceChildren();
    catalog.services.forEach((item) => serviceList.append(makeServiceRow(item)));
    if (!catalog.services.length) serviceList.append(textElement('p', 'neo-empty-catalog', 'Fresh ideas are welcome. Drop us a note.'));
  } catch (_) {
    // Keep the hand-built preview cards when PHP is not running.
  }
}

loadLiveCatalog();
