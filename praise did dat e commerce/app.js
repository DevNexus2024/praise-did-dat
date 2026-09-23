const tabs = [...document.querySelectorAll('.auth-tab')];
const form = document.querySelector('#auth-form');
const panel = document.querySelector('.auth-panel');
const names = document.querySelector('.name-fields');
const terms = document.querySelector('.terms-check');
const message = document.querySelector('.form-message');
let mode = panel.classList.contains('is-signup') ? 'signup' : 'login';

function setMode(nextMode) {
  mode = nextMode;
  const signup = mode === 'signup';
  form.elements.mode.value = mode;
  panel.classList.toggle('is-signup', signup);
  document.querySelector('.auth-tabs').classList.toggle('signup-active', signup);
  names.hidden = !signup;
  terms.hidden = !signup;
  document.querySelector('[name="password"]').autocomplete = signup ? 'new-password' : 'current-password';
  document.querySelector('.forgot-link').hidden = signup;
  document.querySelector('.remember').hidden = signup;
  document.querySelector('#auth-heading').innerHTML = signup ? 'Let’s make it <em>official</em><span class="heading-dot">.</span>' : 'Come on in<span class="heading-dot">.</span>';
  document.querySelector('.auth-subtitle').textContent = signup ? 'A few details and you’re part of the family.' : 'Sign in or make yourself at home.';
  document.querySelector('.submit-label').textContent = signup ? 'Create my account' : 'Sign in';
  document.querySelector('.show-password').setAttribute('aria-label', 'Show password');
  document.querySelector('.show-password').textContent = 'Show';
  document.querySelector('[name="password"]').type = 'password';
  message.textContent = '';
  message.classList.remove('error-message');
  tabs.forEach((tab) => {
    const active = tab.dataset.mode === mode;
    tab.classList.toggle('is-active', active);
    tab.setAttribute('aria-selected', String(active));
  });
  form.querySelectorAll('.field').forEach((field) => field.classList.remove('has-error'));
}

tabs.forEach((tab) => tab.addEventListener('click', () => setMode(tab.dataset.mode)));

document.querySelector('.show-password').addEventListener('click', (event) => {
  const input = document.querySelector('[name="password"]');
  const show = input.type === 'password';
  input.type = show ? 'text' : 'password';
  event.currentTarget.textContent = show ? 'Hide' : 'Show';
  event.currentTarget.setAttribute('aria-label', `${show ? 'Hide' : 'Show'} password`);
});

document.querySelector('.forgot-link').addEventListener('click', (event) => {
  event.preventDefault();
  message.textContent = 'Password reset is coming soon. Get in touch and we’ll help you out.';
  message.classList.remove('error-message');
});

document.querySelector('.social-button').addEventListener('click', () => {
  message.textContent = 'Google sign-in will be available when accounts go live.';
  message.classList.remove('error-message');
});

form.addEventListener('submit', (event) => {
  const email = form.elements.email;
  const password = form.elements.password;
  const firstName = form.elements.firstName;
  const errors = [];

  form.querySelectorAll('.field').forEach((field) => {
    field.classList.remove('has-error');
    const error = field.querySelector('.field-error');
    if (error) error.textContent = '';
  });

  const markInvalid = (input, text) => {
    const field = input.closest('.field');
    field.classList.add('has-error');
    const error = field.querySelector('.field-error');
    if (error) error.textContent = text;
    errors.push(input);
  };

  if (mode === 'signup' && !firstName.value.trim()) markInvalid(firstName, 'Please enter your first name.');
  if (!email.value.trim()) markInvalid(email, 'Please enter your email address.');
  else if (!email.validity.valid) markInvalid(email, 'Please enter a valid email address.');
  if (!password.value) markInvalid(password, 'Please enter your password.');
  else if (password.value.length < 8) markInvalid(password, 'Use at least 8 characters.');
  if (mode === 'signup' && !form.elements.terms.checked) {
    message.textContent = 'Please agree to the Terms and Privacy Policy to continue.';
    message.classList.add('error-message');
    errors.push(form.elements.terms);
  } else if (errors.length) {
    message.textContent = 'A couple of things need your attention above.';
    message.classList.add('error-message');
  }
  if (errors.length) event.preventDefault();
  errors[0]?.focus();
});
