<?php
session_start();
require_once __DIR__ . '/lib/database.php';

if (empty($_SESSION['pdd_csrf'])) {
    $_SESSION['pdd_csrf'] = bin2hex(random_bytes(32));
}

$requestedMode = (string) ($_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['mode'] ?? '') : ($_GET['mode'] ?? 'login'));
$mode = in_array($requestedMode, ['signup', 'admin', 'admin_setup'], true) ? $requestedMode : 'login';
$isAdminMode = in_array($mode, ['admin', 'admin_setup'], true);
$isLocalRequest = in_array((string) ($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
$adminSetupAvailable = false;
$databaseError = '';
$adminCount = null;
$values = ['firstName' => '', 'lastName' => '', 'email' => ''];
$errors = [];
$formMessage = '';
$formMessageIsError = false;
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

if ($isAdminMode && isset($_SESSION['pdd_admin_notice'])) {
    $formMessage = (string) $_SESSION['pdd_admin_notice'];
    $formMessageIsError = !empty($_SESSION['pdd_admin_notice_error']);
    unset($_SESSION['pdd_admin_notice'], $_SESSION['pdd_admin_notice_error']);
}

if ($isAdminMode) {
    try {
        $adminDb = pdd_db();
        $adminCount = (int) $adminDb->query('SELECT COUNT(*) FROM admins')->fetchColumn();
        $adminSetupAvailable = $adminCount === 0 && $isLocalRequest;
        if ($adminCount === 0 && !$isLocalRequest) {
            $databaseError = 'The first admin account must be created from the local XAMPP machine.';
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_SESSION['pdd_admin_id'])) {
            header('Location: admin.php', true, 303);
            exit;
        }
    } catch (Throwable $exception) {
        $databaseError = 'We could not connect to the Praise Did Dat database. Make sure XAMPP MySQL is running and the database schema has been imported.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($values as $key => $_) {
        $values[$key] = trim((string) ($_POST[$key] ?? ''));
    }
    $email = $values['email'];
    $password = (string) ($_POST['password'] ?? '');

    if (!hash_equals($_SESSION['pdd_csrf'], (string) ($_POST['csrf_token'] ?? ''))) {
        $formMessage = 'Your session expired. Refresh the page and try again.';
        $formMessageIsError = true;
    } elseif ($mode === 'admin_setup') {
        if (!$isLocalRequest) {
            $formMessage = 'The first admin account must be created from the local XAMPP machine.';
            $formMessageIsError = true;
        } elseif ($databaseError !== '') {
            $formMessage = $databaseError;
            $formMessageIsError = true;
        } elseif (!$adminSetupAvailable) {
            $formMessage = 'Admin setup is closed. Please sign in with an existing admin account.';
            $formMessageIsError = true;
        } else {
            $password = (string) ($_POST['password'] ?? '');
            $confirmation = (string) ($_POST['password_confirmation'] ?? '');
            if (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
                $formMessage = 'Enter a valid admin email address.';
                $formMessageIsError = true;
            } elseif (strlen($password) < 12) {
                $formMessage = 'Use at least 12 characters for the admin password.';
                $formMessageIsError = true;
            } elseif (!hash_equals($password, $confirmation)) {
                $formMessage = 'The password confirmation does not match.';
                $formMessageIsError = true;
            } else {
                try {
                    $statement = $adminDb->prepare("INSERT INTO admins (email, password_hash, role) VALUES (:email, :password_hash, 'owner')");
                    $statement->execute(['email' => $values['email'], 'password_hash' => password_hash($password, PASSWORD_DEFAULT)]);
                    unset($_SESSION['pdd_customer_id'], $_SESSION['pdd_customer_name'], $_SESSION['pdd_customer_email'], $_SESSION['pdd_cart'], $_SESSION['pdd_direct_order']);
                    session_regenerate_id(true);
                    $_SESSION['pdd_admin_id'] = (int) $adminDb->lastInsertId();
                    $_SESSION['pdd_admin_email'] = $values['email'];
                    $_SESSION['pdd_admin_role'] = 'owner';
                    header('Location: admin.php', true, 303);
                    exit;
                } catch (PDOException $exception) {
                    $formMessage = 'That admin email could not be saved. It may already be in use.';
                    $formMessageIsError = true;
                }
            }
        }
    } elseif ($mode === 'admin') {
        if ($databaseError !== '') {
            $formMessage = $databaseError;
            $formMessageIsError = true;
        } elseif ($adminCount === 0) {
            $formMessage = 'No admin account exists yet. Create the first one from this local XAMPP machine.';
            $formMessageIsError = true;
        } else {
            try {
                $password = (string) ($_POST['password'] ?? '');
                $statement = $adminDb->prepare('SELECT id, email, password_hash, is_active, role FROM admins WHERE email = :email LIMIT 1');
                $statement->execute(['email' => $values['email']]);
                $admin = $statement->fetch();
                if (!$admin || (int) $admin['is_active'] !== 1 || !password_verify($password, $admin['password_hash'])) {
                    $formMessage = 'Email or password is incorrect.';
                    $formMessageIsError = true;
                } else {
                    unset($_SESSION['pdd_customer_id'], $_SESSION['pdd_customer_name'], $_SESSION['pdd_customer_email'], $_SESSION['pdd_cart'], $_SESSION['pdd_direct_order']);
                    session_regenerate_id(true);
                    $_SESSION['pdd_admin_id'] = (int) $admin['id'];
                    $_SESSION['pdd_admin_email'] = $admin['email'];
                    $_SESSION['pdd_admin_role'] = $admin['role'];
                    $adminDb->prepare('UPDATE admins SET last_login_at = CURRENT_TIMESTAMP WHERE id = :id')->execute(['id' => $admin['id']]);
                    header('Location: admin.php', true, 303);
                    exit;
                }
            } catch (Throwable $exception) {
                $formMessage = 'We could not sign you in. Check that the MySQL database is available.';
                $formMessageIsError = true;
            }
        }
    } else {
        if ($mode === 'signup' && $values['firstName'] === '') {
            $errors['firstName'] = 'Please enter your first name.';
        }
        if ($email === '') {
            $errors['email'] = 'Please enter your email address.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address.';
        } elseif (strlen($email) > 254) {
            $errors['email'] = 'Your email address is too long.';
        }
        if ($password === '') {
            $errors['password'] = 'Please enter your password.';
        } elseif (strlen($password) < ($mode === 'signup' ? 8 : 1)) {
            $errors['password'] = 'Use at least 8 characters.';
        }
        if ($mode === 'signup' && empty($_POST['terms'])) {
            $errors['terms'] = 'Please agree to the Terms and Privacy Policy to continue.';
        }

        if ($errors) {
            $formMessage = reset($errors);
            $formMessageIsError = true;
        } else {
            try {
                $db = pdd_db();
                if ($mode === 'signup') {
                    $statement = $db->prepare('INSERT INTO customers (first_name, last_name, email, password_hash) VALUES (:first_name, :last_name, :email, :password_hash)');
                    $statement->execute([
                        'first_name' => $values['firstName'],
                        'last_name' => $values['lastName'] !== '' ? $values['lastName'] : null,
                        'email' => $email,
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    ]);
                    $customerId = (int) $db->lastInsertId();
                    $customerName = $values['firstName'];
                } else {
                    $statement = $db->prepare('SELECT id, first_name, email, password_hash FROM customers WHERE email = :email AND is_active = 1 LIMIT 1');
                    $statement->execute(['email' => $email]);
                    $customer = $statement->fetch();
                    if (!$customer || !password_verify($password, $customer['password_hash'])) {
                        $formMessage = 'Email or password is incorrect.';
                        $formMessageIsError = true;
                    } else {
                        $customerId = (int) $customer['id'];
                        $customerName = (string) $customer['first_name'];
                    }
                }

                if (!$formMessageIsError) {
                    unset($_SESSION['pdd_admin_id'], $_SESSION['pdd_admin_email'], $_SESSION['pdd_admin_role']);
                    session_regenerate_id(true);
                    $_SESSION['pdd_customer_id'] = $customerId;
                    $_SESSION['pdd_customer_name'] = $customerName;
                    $_SESSION['pdd_customer_email'] = $email;
                    header('Location: shop.php', true, 303);
                    exit;
                }
            } catch (PDOException $exception) {
                if ($mode === 'signup' && $exception->getCode() === '23000') {
                    $formMessage = 'An account with this email already exists. Please sign in instead.';
                } else {
                    $formMessage = 'We could not connect to the Praise Did Dat database. Check that MySQL is running and the database schema has been imported.';
                }
                $formMessageIsError = true;
            } catch (Throwable $exception) {
                $formMessage = 'We could not connect to the Praise Did Dat database. Check that MySQL is running and the database schema has been imported.';
                $formMessageIsError = true;
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="theme-color" content="#f8f5ef" />
    <meta name="description" content="Sign in or create an account at Praise Did Dat — a little bit of everything, made with love." />
    <title><?= $isAdminMode ? 'Admin sign in' : ($mode === 'signup' ? 'Create account' : 'Sign in') ?> — Praise Did Dat</title>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:ital,wght@0,600;0,700;1,600;1,700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="styles.css" />
    <script src="app.js" defer></script>
  </head>
  <body class="auth-future">
    <header class="topbar">
      <a class="wordmark" href="#home" aria-label="Praise Did Dat home"><span class="wordmark-icon">p.</span><span class="kinetic-wordmark" aria-hidden="true"><span class="kinetic-word">praise</span><span class="kinetic-word">did</span><span class="kinetic-word kinetic-green">dat</span><span class="wordmark-period">.</span></span></a>
      <a class="back-link" href="index.html"><span aria-hidden="true">←</span> <span>Back to home</span></a>
    </header>

    <main class="page-shell" id="home">
      <section class="welcome-panel" aria-label="A preview of Praise Did Dat">
        <div class="panel-grain"></div>
        <div class="panel-copy"><p class="eyebrow"><span class="eyebrow-star">✳</span> A little bit of everything</p>
          <h1>Good things.<br /><em>Made by us.</em><br />For you.</h1>
          <p class="panel-description">Objects that make you smile.<br />Services that make your day.</p>
        </div>

        <div class="product-scene" aria-hidden="true">
          <div class="scene-orbit orbit-one"></div><div class="scene-orbit orbit-two"></div>
          <div class="sparkle sparkle-one">✳</div><div class="sparkle sparkle-two">✳</div><div class="sparkle sparkle-three">✦</div>
          <div class="phone-shadow"></div>
          <div class="phone">
            <div class="phone-camera"><span></span><span></span><span></span><i></i></div>
            <div class="waffle-grid"></div>
            <div class="case-label"><span>made with</span><strong>LOVE<br />&amp; SYRUP</strong><i>✿</i></div>
            <div class="phone-cutout"></div>
          </div>
          <div class="product-note"><span class="note-line"></span><span>the little things<br /><em>hit different</em></span></div>
          <div class="sticker">sweet<br />on you <span>♡</span></div>
        </div>

        <div class="panel-footer"><span>Independent by nature</span><span class="footer-flower">✿</span><span>Made with feeling</span></div>
      </section>

      <section class="auth-panel<?= $mode === 'signup' ? ' is-signup' : '' ?>" aria-labelledby="auth-heading">
        <div class="auth-wrap">
          <div class="mobile-mark"><span class="wordmark-icon">p.</span> praise did dat<span class="wordmark-period">.</span></div>
          <div class="auth-intro"><p class="eyebrow auth-eyebrow"><?= $isAdminMode ? 'PRIVATE STUDIO ACCESS' : 'YOUR CORNER OF THE INTERNET' ?></p><h2 id="auth-heading"><?php if ($isAdminMode): ?><?= $mode === 'admin_setup' && $adminSetupAvailable ? 'Create your <em>admin account</em>' : 'Admin sign in' ?><?php elseif ($mode === 'signup'): ?>Let’s make it <em>official</em><?php else: ?>Come on in<?php endif; ?><span class="heading-dot">.</span></h2><p class="auth-subtitle"><?php if ($isAdminMode): ?><?= $mode === 'admin_setup' && $adminSetupAvailable ? 'Set up the first admin account for this shop.' : 'Sign in to manage the shop and view its performance.' ?><?php else: ?><?= $mode === 'signup' ? 'A few details and you’re part of the family.' : 'Sign in or make yourself at home.' ?><?php endif; ?></p></div>

          <div class="auth-tabs" role="tablist" aria-label="Customer account access"<?= $isAdminMode ? ' hidden' : '' ?>>
            <button class="auth-tab<?= $mode === 'login' ? ' is-active' : '' ?>" id="login-tab" type="button" role="tab" aria-selected="<?= $mode === 'login' ? 'true' : 'false' ?>" aria-controls="auth-form" data-mode="login">Sign in</button>
            <button class="auth-tab<?= $mode === 'signup' ? ' is-active' : '' ?>" id="signup-tab" type="button" role="tab" aria-selected="<?= $mode === 'signup' ? 'true' : 'false' ?>" aria-controls="auth-form" data-mode="signup">Create account</button>
            <span class="tab-indicator"<?= $mode === 'signup' ? ' style="transform:translateX(100%)"' : '' ?>></span>
          </div>

          <?php if ($databaseError !== ''): ?><p class="form-message error-message" role="alert"><?= $escape($databaseError) ?></p><?php endif; ?>
          <?php if (!$isAdminMode || $databaseError === ''): ?><form id="auth-form" method="post" action="index.php">
            <input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['pdd_csrf']) ?>" />
            <input type="hidden" name="mode" value="<?= $isAdminMode ? ($adminSetupAvailable ? 'admin_setup' : 'admin') : $mode ?>" />
            <div class="name-fields"<?= $mode !== 'signup' ? ' hidden' : '' ?>>
              <label class="field<?= isset($errors['firstName']) ? ' has-error' : '' ?>"><span>First name</span><input name="firstName" type="text" placeholder="Your first name" autocomplete="given-name" value="<?= $escape($values['firstName']) ?>" /><span class="field-error"><?= $escape($errors['firstName'] ?? '') ?></span></label>
              <label class="field"><span>Last name <small>OPTIONAL</small></span><input name="lastName" type="text" placeholder="Your last name" autocomplete="family-name" value="<?= $escape($values['lastName']) ?>" /></label>
            </div>
            <label class="field<?= isset($errors['email']) ? ' has-error' : '' ?>"><span>Email address</span><input name="email" type="email" placeholder="you@example.com" autocomplete="email" required value="<?= $escape($values['email']) ?>" /><span class="field-error"><?= $escape($errors['email'] ?? '') ?></span></label>
            <label class="field password-field<?= isset($errors['password']) ? ' has-error' : '' ?>"><span>Password</span><span class="password-wrap"><input name="password" type="password" placeholder="<?= $mode === 'admin_setup' ? 'At least 12 characters' : ($mode === 'signup' ? 'At least 8 characters' : 'Your password') ?>" autocomplete="<?= $mode === 'signup' || $mode === 'admin_setup' ? 'new-password' : 'current-password' ?>" required minlength="<?= $mode === 'admin_setup' ? '12' : ($mode === 'signup' ? '8' : '1') ?>" /><button class="show-password" type="button" aria-label="Show password">Show</button></span><span class="field-error"><?= $escape($errors['password'] ?? '') ?></span></label>
            <?php if ($mode === 'admin_setup' && $adminSetupAvailable): ?><label class="field"><span>Confirm password</span><input name="password_confirmation" type="password" placeholder="Type it again" autocomplete="new-password" required minlength="12" /></label><?php endif; ?>
            <div class="form-extras"<?= $isAdminMode ? ' hidden' : '' ?>><label class="remember"><input type="checkbox" name="remember" /><span class="custom-check"></span><span>Keep me signed in</span></label><a class="text-link forgot-link" href="#forgot">Forgot password?</a></div>
            <label class="terms-check"<?= $mode !== 'signup' ? ' hidden' : '' ?>><input type="checkbox" name="terms"<?= !empty($_POST['terms']) ? ' checked' : '' ?> /><span class="custom-check"></span><span>I agree to the <a class="text-link" href="#terms">Terms</a> and <a class="text-link" href="#privacy">Privacy Policy</a></span></label>
            <button class="submit-button" type="submit"><span class="submit-label"><?php if ($isAdminMode): ?><?= $mode === 'admin_setup' && $adminSetupAvailable ? 'Create admin account' : 'Sign in as admin' ?><?php else: ?><?= $mode === 'signup' ? 'Create my account' : 'Sign in' ?><?php endif; ?></span><span class="submit-arrow" aria-hidden="true">↗</span></button>
            <p class="form-message<?= $formMessageIsError ? ' error-message' : '' ?>" role="status" aria-live="polite"><?= $escape($formMessage) ?></p>
          </form><?php endif; ?>
          <?php if (!$isAdminMode): ?><noscript><p class="auth-legal">Need another option? <a href="?mode=signup">Create an account</a> · <a href="?mode=login">Sign in</a></p></noscript><?php endif; ?>

          <div class="divider"<?= $isAdminMode ? ' hidden' : '' ?>><span></span><p>OR CONTINUE WITH</p><span></span></div>
          <button class="social-button" type="button"<?= $isAdminMode ? ' hidden' : '' ?>><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92a5.06 5.06 0 0 1-2.2 3.32v2.77h3.57c2.08-1.91 3.27-4.73 3.27-8.1z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.99 7.28-2.65l-3.57-2.77c-.99.66-2.25 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.15v2.84A11 11 0 0 0 12 23z"/><path fill="#FBBC05" d="M5.84 14.11A6.6 6.6 0 0 1 5.5 12c0-.73.13-1.45.34-2.11V7.05H2.15A11 11 0 0 0 1 12c0 1.78.43 3.46 1.15 4.95l3.69-2.84z"/><path fill="#EA4335" d="M12 5.36c1.62 0 3.06.56 4.21 1.64l3.16-3.16C17.45 2.04 14.97 1 12 1a11 11 0 0 0-9.85 6.05l3.69 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>Continue with Google</button>
          <?php if (!$isAdminMode): ?><p class="auth-legal">By continuing, you agree to our <a href="#terms">Terms of Service</a> and <a href="#privacy">Privacy Policy</a>.</p><?php endif; ?>
        </div>
      </section>
    </main>
    <footer class="site-footer"><span>© 2025 Praise Did Dat</span><span>Made for the joy of it <b>♥</b></span><div class="footer-links"><a href="#help">Need a hand?</a><a href="#instagram" aria-label="Instagram">ig ↗</a></div></footer>
  </body>
</html>
