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
    $authorizedName = trim((string)($_POST['authorized_name'] ?? ''));
    $signatureData = trim((string)($_POST['signature_data'] ?? ''));
    if (strlen($authorizedName) > 150) $error = 'Authorized name must be 150 characters or fewer.';
    elseif ($signatureData !== '' && (!preg_match('~^data:image/png;base64,([A-Za-z0-9+/]+=*)$~', $signatureData, $m) || strlen($signatureData) > 700000 || base64_decode($m[1] ?? '', true) === false)) $error = 'Signature image is invalid or too large.';
    else {
        $pdo->prepare('UPDATE users SET authorized_name=?, profile_signature_data=? WHERE id=?')
            ->execute([$authorizedName ?: null, $signatureData ?: null, $userId]);
        $success = 'Profile signature and authorized name saved. They will be used for new documents.';
    }
}
$stmt = $pdo->prepare('SELECT name,email,username,authorized_name,profile_signature_data FROM users WHERE id=? LIMIT 1');
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
        <p class="profile-hint">Set these once. New invoices, receipts and quotations will use them automatically. You can still change the signature on an individual document.</p>
        <p class="profile-identity"><strong><?= h($profile['name'] ?? '') ?></strong> · <?= h($profile['email'] ?? $profile['username'] ?? '') ?></p>
        <form method="post" id="profileSignatureForm">
            <div class="form-group"><label for="authorized_name">Authorized Name</label><input id="authorized_name" name="authorized_name" maxlength="150" value="<?= h($profile['authorized_name'] ?? '') ?>" required></div>
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
 document.getElementById('profileSignatureForm').addEventListener('submit',e=>{if(dirty)input.value=canvas.toDataURL('image/png');if(!input.value){e.preventDefault();alert('Please draw a signature before saving.');}});
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
