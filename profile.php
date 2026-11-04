<?php
declare(strict_types=1);
require_once __DIR__ . '/config/db.php';
$pdo = getDB();
$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) { header('Location: login.php'); exit; }

ensureUserSignatureProfile($pdo);

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string)($_POST['name'] ?? ''));
    $email = trim((string)($_POST['email'] ?? ''));
    $phone = trim((string)($_POST['phone'] ?? ''));
    $authorizedName = trim((string)($_POST['authorized_name'] ?? ''));
    $signatureData = trim((string)($_POST['signature_data'] ?? ''));
    if ($name === '' || strlen($name) > 150) $error = 'Enter a name of 150 characters or fewer.';
    elseif ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150)) $error = 'Enter a valid email address of 150 characters or fewer.';
    elseif (strlen($authorizedName) > 150) $error = 'Authorized name must be 150 characters or fewer.';
    elseif ($signatureData !== '' && (!preg_match('~^data:image/(png|jpeg|webp);base64,([A-Za-z0-9+/]+=*)$~', $signatureData, $m) || strlen($signatureData) > 700000 || base64_decode($m[2] ?? '', true) === false)) $error = 'Signature image is invalid or too large.';
    else {
        try {
            $pdo->prepare('UPDATE users SET name=?, email=?, phone=?, authorized_name=?, profile_signature_data=? WHERE id=?')
                ->execute([$name, $email ?: null, $phone ?: null, $authorizedName ?: $name, $signatureData ?: null, $userId]);
            $_SESSION['user_name'] = $name;
            $_SESSION['user_email'] = $email;
            $success = 'Profile and document signature saved. They will be used for new documents.';
        } catch (PDOException $e) {
            $error = $e->getCode() === '23000' ? 'That email address is already in use.' : 'Unable to save your profile right now.';
        }
    }
}
$stmt = $pdo->prepare('SELECT name,email,phone,username,authorized_name,profile_signature_data FROM users WHERE id=? LIMIT 1');
$stmt->execute([$userId]);
$profile = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$pageTitle = 'My Profile';
include __DIR__ . '/includes/header.php';
?>
<section class="profile-page">
    <div class="section-header"><span class="section-title">My Profile</span></div>
    <?php if ($success): ?><div class="settings-alert settings-alert-success"><?= h($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="settings-alert settings-alert-danger"><?= h($error) ?></div><?php endif; ?>
    <div class="card profile-card">
        <div class="card-header"><span class="card-title">Document signatory defaults</span></div>
        <p class="profile-hint">Update your contact information and set a signature for new invoices, receipts, delivery notes and quotations.</p>
        <p class="profile-identity"><strong><?= h($profile['name'] ?? '') ?></strong> · <?= h($profile['email'] ?? $profile['username'] ?? '') ?></p>
        <form method="post" id="profileSignatureForm">
            <div class="form-group"><label for="profile_name">Full name</label><input id="profile_name" name="name" maxlength="150" value="<?= h($profile['name'] ?? '') ?>" required></div>
            <div class="form-group"><label for="profile_email">Email</label><input id="profile_email" name="email" type="email" maxlength="150" value="<?= h($profile['email'] ?? '') ?>"></div>
            <div class="form-group"><label for="profile_phone">Phone</label><input id="profile_phone" name="phone" maxlength="30" value="<?= h($profile['phone'] ?? '') ?>"></div>
            <div class="form-group"><label for="authorized_name">Authorized Name</label><input id="authorized_name" name="authorized_name" maxlength="150" value="<?= h($profile['authorized_name'] ?? '') ?>" required></div>
            <div class="form-group"><label for="signatureFile">Upload signature (PNG, JPG or WEBP)</label><input id="signatureFile" type="file" accept="image/png,image/jpeg,image/webp"><small>Or sign in the box below.</small></div>
            <label for="signaturePad">Saved Signature</label>
            <div class="profile-signature-wrap"><canvas id="signaturePad" width="720" height="180" aria-label="Draw your signature"></canvas></div>
            <input type="hidden" name="signature_data" id="signatureData" value="<?= h($profile['profile_signature_data'] ?? '') ?>">
            <div class="profile-actions"><button type="button" class="btn btn-outline btn-sm" id="clearSignature">Clear / redraw</button><button type="submit" class="btn btn-primary">Save Profile</button></div>
        </form>
    </div>
</section>
<script>
(() => {
 const canvas=document.getElementById('signaturePad'),ctx=canvas.getContext('2d'),input=document.getElementById('signatureData');
 let drawing=false,dirty=false;
 ctx.lineWidth=3;ctx.lineCap='round';ctx.lineJoin='round';ctx.strokeStyle='#172554';
 const saved=input.value;
  if(saved){const img=new Image();img.onload=()=>ctx.drawImage(img,0,0,canvas.width,canvas.height);img.src=saved;}
 const point=e=>{const r=canvas.getBoundingClientRect();return{x:(e.clientX-r.left)*canvas.width/r.width,y:(e.clientY-r.top)*canvas.height/r.height};};
 canvas.addEventListener('pointerdown',e=>{drawing=true;dirty=true;canvas.setPointerCapture(e.pointerId);const p=point(e);ctx.beginPath();ctx.moveTo(p.x,p.y);});
 canvas.addEventListener('pointermove',e=>{if(!drawing)return;const p=point(e);ctx.lineTo(p.x,p.y);ctx.stroke();});
 canvas.addEventListener('pointerup',()=>drawing=false);canvas.addEventListener('pointercancel',()=>drawing=false);
  document.getElementById('clearSignature').addEventListener('click',()=>{ctx.clearRect(0,0,canvas.width,canvas.height);input.value='';dirty=true;});
  document.getElementById('signatureFile').addEventListener('change',e=>{const file=e.target.files[0];if(!file)return;if(file.size>500000){showToast('Signature file must be 500 KB or smaller.','error');e.target.value='';return;}const reader=new FileReader();reader.onload=()=>{input.value=reader.result;dirty=false;ctx.clearRect(0,0,canvas.width,canvas.height);const img=new Image();img.onload=()=>ctx.drawImage(img,0,0,canvas.width,canvas.height);img.src=reader.result;};reader.readAsDataURL(file);});
  document.getElementById('profileSignatureForm').addEventListener('submit',e=>{if(dirty)input.value=canvas.toDataURL('image/png');});
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
