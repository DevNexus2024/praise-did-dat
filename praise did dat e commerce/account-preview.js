const previewForms = document.querySelectorAll('.preview-form');
let phpAvailable = false;

document.querySelectorAll('.show-password').forEach((button) => {
  button.addEventListener('click', () => {
    const input = button.closest('.password-wrap').querySelector('input');
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    button.textContent = show ? 'Hide' : 'Show';
    button.setAttribute('aria-label', `${show ? 'Hide' : 'Show'} password`);
  });
});

previewForms.forEach((form) => {
  form.addEventListener('submit', (event) => {
    if (window.location.protocol === 'file:' || !phpAvailable) {
      event.preventDefault();
      const message = form.querySelector('.form-message');
      message.textContent = 'Your form is ready. To submit it, open the site through a PHP web server.';
    }
  });
});

if (window.location.protocol !== 'file:') {
  const mode = document.querySelector('.preview-form')?.dataset.mode;
  const phpPage = mode === 'signup' ? 'signup.php' : 'login.php';
  fetch(phpPage, { headers: { Accept: 'text/html' } })
    .then(async (response) => {
      const content = await response.text();
      phpAvailable = response.ok && response.headers.get('content-type')?.includes('text/html') && !content.trimStart().startsWith('<?php');
      if (phpAvailable) window.location.replace(phpPage);
    })
    .catch(() => {});
}
