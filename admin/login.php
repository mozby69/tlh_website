<?php
/**
 * FILE PURPOSE: Admin authentication page and login handler.
 * DEBUGGING: Successful authentication regenerates the session ID. Password verification/session hardening helpers are in includes/functions.php.
 */
require_once __DIR__ . '/../includes/functions.php';
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Robots-Tag: noindex, nofollow, noarchive');
if(!empty($_SESSION['admin_id'])){redirect(is_calendar_viewer() ? 'booking-calendar.php' : 'index.php');}
$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  verify_csrf();
  $username=trim($_POST['username']??'');
  $password=$_POST['password']??'';
  try{
    $stmt=db()->prepare('SELECT * FROM admins WHERE username=? AND is_active=1 LIMIT 1');
    $stmt->execute([$username]);
    $user=$stmt->fetch();
    if($user&&password_verify($password,$user['password_hash'])){
      if (password_needs_rehash((string)$user['password_hash'], PASSWORD_DEFAULT)) {
        try {
          db()->prepare('UPDATE admins SET password_hash=? WHERE id=?')->execute([password_hash($password, PASSWORD_DEFAULT), (int)$user['id']]);
        } catch (Throwable $ignored) {
          // A rehash failure must not block an otherwise valid login.
        }
      }
      session_regenerate_id(true);
      unset($_SESSION['csrf_token']);
      $_SESSION['admin_id']=$user['id'];
      $_SESSION['admin_name']=$user['full_name'];
      $_SESSION['admin_role']=$user['role'];
      $welcomeName = trim((string)($user['full_name'] ?? ''));
      flash('success', $welcomeName !== ''
        ? 'Welcome back, ' . $welcomeName . '. You have signed in successfully.'
        : 'You have signed in successfully.');
      if (password_verify('Admin@123', (string)$user['password_hash'])) {
        flash('warning', ($user['role'] ?? '') === 'admin' ? 'This account is still using the default installation password. Change it from Admin > Users before production use.' : 'This account is still using the default installation password. Ask an administrator to reset it before production use.');
      }
      redirect(($user['role'] ?? '') === 'calendar_viewer' ? 'booking-calendar.php' : 'index.php');
    }
    $error='Invalid username or password.';
  }catch(Throwable $e){
    $error='Database connection failed. Check config/local.php and confirm MySQL is running.';
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="theme-color" content="#06172e">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="Leisure Hub">
  <link rel="manifest" href="../manifest.webmanifest">
  <link rel="apple-touch-icon" href="../assets/img/apple-touch-icon.png">
  <title>Admin Portal | The Leisure Hub</title>
  <script src="../assets/js/launch.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/launch.js') ?>" data-logo="../assets/img/tlh-logo.png" data-tagline="<?= e(site_tagline()) ?>"></script>
  <link rel="stylesheet" href="../assets/css/style.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/style.css') ?>">
  <link rel="stylesheet" href="../assets/css/admin.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
  <link rel="stylesheet" href="../assets/css/tokens.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/tokens.css') ?>">
  <link rel="stylesheet" href="../assets/css/modern-admin.css?v=<?= (int)@filemtime(__DIR__ . '/../assets/css/modern-admin.css') ?>">
  <script>document.documentElement.classList.add('tlh-login-motion');</script>
</head>
<body class="admin-login-body">
  <main class="admin-login-shell">
    <section class="admin-login-hero" aria-label="The Leisure Hub administration portal">
      <div class="admin-login-hero-overlay"></div>
      <div class="admin-login-hero-content">
        <a class="admin-login-logo-lockup" href="../index.php" aria-label="Return to The Leisure Hub website">
          <img src="../assets/img/tlh-logo.png" width="1091" height="722" decoding="async" alt="The Leisure Hub logo">
          <span><strong>The Leisure Hub</strong><small><?= e(site_tagline()) ?></small></span>
        </a>
        <div class="admin-login-hero-copy">
          <span class="admin-login-kicker">Venue Administration</span>
          <h1>Manage every reservation with confidence.</h1>
          <p>Reservations, schedules, payments, client records, and venue operations in one secure workspace.</p>
        </div>
        <div class="admin-login-hero-footer">
          <span class="admin-login-dot" aria-hidden="true"></span>
          <span>Authorized personnel access</span>
        </div>
      </div>
    </section>

    <section class="admin-login-panel">
      <div class="admin-login-panel-inner">
        <a class="admin-login-mobile-brand" href="../index.php">
          <img src="../assets/img/tlh-logo.png" width="1091" height="722" decoding="async" alt="The Leisure Hub logo">
          <span><strong>The Leisure Hub</strong><small><?= e(site_tagline()) ?></small></span>
        </a>

        <div class="admin-login-heading">
          <span class="admin-login-eyebrow">Admin Portal</span>
          <h2>Welcome back</h2>
          <p>Sign in with your authorized staff account to continue.</p>
        </div>

        <?php if($error): ?>
          <div class="admin-login-alert" role="alert">
            <span class="admin-login-alert-icon" aria-hidden="true">!</span>
            <span><?= e($error) ?></span>
          </div>
        <?php endif; ?>

        <form method="post" class="admin-login-form" id="adminLoginForm" novalidate>
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

          <div class="admin-login-field">
            <label for="loginUsername">Username</label>
            <div class="admin-login-input-wrap">
              <span class="admin-login-input-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" focusable="false"><path d="M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8Zm7 8a7 7 0 0 0-14 0"/></svg>
              </span>
              <input id="loginUsername" name="username" autocomplete="username" value="<?= e($_POST['username'] ?? '') ?>" required autofocus placeholder="Enter your username">
            </div>
          </div>

          <div class="admin-login-field">
            <div class="admin-login-label-row">
              <label for="loginPassword">Password</label>
              <span id="capsLockNotice" class="admin-login-caps" hidden>Caps Lock is on</span>
            </div>
            <div class="admin-login-input-wrap">
              <span class="admin-login-input-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" focusable="false"><path d="M7 10V8a5 5 0 0 1 10 0v2M6 10h12v10H6z"/></svg>
              </span>
              <input id="loginPassword" type="password" name="password" autocomplete="current-password" required placeholder="Enter your password">
              <button class="admin-login-password-toggle" id="passwordToggle" type="button" aria-label="Show password" aria-pressed="false">
                <svg class="eye-open" viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.6"/></svg>
                <svg class="eye-closed" viewBox="0 0 24 24" aria-hidden="true"><path d="m3 3 18 18M10.5 6.2A10 10 0 0 1 12 6c6 0 9.5 6 9.5 6a16 16 0 0 1-3 3.5M6.1 6.1C3.7 8 2.5 12 2.5 12s3.5 6 9.5 6c1.5 0 2.8-.4 4-1"/></svg>
              </button>
            </div>
          </div>

          <button class="admin-login-submit" id="loginSubmit" type="submit">
            <span class="admin-login-submit-label">Sign In</span>
            <span class="admin-login-spinner" aria-hidden="true"></span>
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M14 7l5 5-5 5"/></svg>
          </button>
        </form>

        <div class="admin-login-security-note">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3 5 6v5c0 4.4 2.8 8.4 7 10 4.2-1.6 7-5.6 7-10V6l-7-3Z"/><path d="m9.5 12 1.7 1.7 3.5-3.7"/></svg>
          <span>This portal is for authorized personnel only. Keep your account credentials private.</span>
        </div>

        <a class="admin-login-back" href="../index.php">
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19 12H5M10 7l-5 5 5 5"/></svg>
          Back to public website
        </a>

        <p class="admin-login-copyright">&copy; <?= date('Y') ?> The Leisure Hub. Administration Portal.</p>
      </div>
    </section>
  </main>

  <?php // Keep global flash modals outside the glass login card so fixed positioning targets the viewport. ?>
  <?php render_flashes(); ?>

<script src="../assets/js/app.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
<script>
(function(){
  const form=document.getElementById('adminLoginForm');
  const password=document.getElementById('loginPassword');
  const toggle=document.getElementById('passwordToggle');
  const caps=document.getElementById('capsLockNotice');
  const submit=document.getElementById('loginSubmit');

  if(toggle&&password){
    toggle.addEventListener('click',function(){
      const show=password.type==='password';
      password.type=show?'text':'password';
      toggle.setAttribute('aria-pressed',show?'true':'false');
      toggle.setAttribute('aria-label',show?'Hide password':'Show password');
      password.focus();
    });
  }

  function updateCaps(event){
    if(!caps||!event.getModifierState)return;
    caps.hidden=!event.getModifierState('CapsLock');
  }
  if(password){
    password.addEventListener('keydown',updateCaps);
    password.addEventListener('keyup',updateCaps);
    password.addEventListener('blur',function(){if(caps)caps.hidden=true;});
  }

  function revealLoginComposition(){
    document.documentElement.classList.add('tlh-login-motion-ready');
  }

  if(document.documentElement.classList.contains('tlh-hero-logo-wait')){
    const splashObserver=new MutationObserver(function(){
      if(document.documentElement.classList.contains('tlh-hero-logo-ready')){
        splashObserver.disconnect();
        window.requestAnimationFrame(revealLoginComposition);
      }
    });
    splashObserver.observe(document.documentElement,{attributes:true,attributeFilter:['class']});
    window.setTimeout(function(){splashObserver.disconnect();revealLoginComposition();},5400);
  }else{
    window.requestAnimationFrame(function(){window.requestAnimationFrame(revealLoginComposition);});
  }

  if(form&&submit){
    form.addEventListener('submit',function(event){
      if(!form.checkValidity()){
        event.preventDefault();
        form.reportValidity();
        return;
      }
      submit.classList.add('is-loading');
      submit.disabled=true;
      submit.setAttribute('aria-busy','true');
    });
  }
})();
</script>
</body>
</html>
