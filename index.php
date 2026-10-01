<?php

/**
 * Cloudflare R2 - Demo / Test Page
 *
 * এই পেজ দিয়ে প্যাকেজ ইনস্টল করে সরাসরি Upload, Replace, Delete ও List টেস্ট করা যায়।
 * কোনো ডাটাবেস লাগে না, ফাইলের তালিকা সরাসরি R2 বাকেট থেকে আসে।
 *
 * সতর্কতা: এই পেজে লগইন/অথেনটিকেশন নেই, তাই শুধু লোকাল টেস্টের জন্য ব্যবহার করুন।
 */

use Dotenv\Dotenv;
use Jamrul\Cloudflare\R2Service;

// ---------------------------------------------------------------
// ১. Autoload খুঁজে বের করা (প্যাকেজের root বা ইউজারের প্রজেক্ট, দুই জায়গাতেই চলবে)
// ---------------------------------------------------------------
$autoloadFile = null;
foreach ([
    __DIR__ . '/vendor/autoload.php',
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../autoload.php',
] as $candidate) {
    if (is_file($candidate)) {
        $autoloadFile = $candidate;
        break;
    }
}

if ($autoloadFile === null) {
    http_response_code(500);
    exit('Composer autoload not found. Run <code>composer install</code> first.');
}

require_once $autoloadFile;

// ---------------------------------------------------------------
// ২. .env লোড করা (phpdotenv ইনস্টল থাকলে)
// ---------------------------------------------------------------
if (class_exists(Dotenv::class)) {
    foreach ([__DIR__, dirname(__DIR__)] as $envDir) {
        if (is_file($envDir . '/.env')) {
            Dotenv::createImmutable($envDir)->safeLoad();
            break;
        }
    }
}

// ---------------------------------------------------------------
// ৩. হেল্পার ফাংশন
// ---------------------------------------------------------------

// .env বা সার্ভার এনভায়রনমেন্ট থেকে মান পড়া
function env_value(string $key, $default = null)
{
    $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    return ($value === false || $value === null || $value === '') ? $default : $value;
}

// HTML আউটপুট নিরাপদ করা
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// বাইটকে পড়ার উপযোগী সাইজে রূপান্তর
function format_size($bytes): string
{
    $bytes = (float) $bytes;
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, $i === 0 ? 0 : 2) . ' ' . $units[$i];
}

// ফাইলটি ছবি কিনা (এক্সটেনশন দেখে)
function is_image_file(string $name): bool
{
    return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'avif'], true);
}

// ফ্ল্যাশ মেসেজ সেশনে রাখা
function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

// PHP আপলোড এররের মানুষের পড়ার মতো মেসেজ
function upload_error_message(int $code): string
{
    $map = [
        UPLOAD_ERR_INI_SIZE   => 'File exceeds upload_max_filesize (' . ini_get('upload_max_filesize') . ') in php.ini.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form size limit.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'Please select a file.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary upload folder on the server.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write the file to disk.',
        UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the upload.',
    ];
    return $map[$code] ?? 'Unknown upload error.';
}

// ---------------------------------------------------------------
// ৪. সেশন ও CSRF টোকেন
// ---------------------------------------------------------------
session_start();

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

// ---------------------------------------------------------------
// ৫. R2Service তৈরি (কনফিগ ভুল হলে সুন্দর করে সেটআপ গাইড দেখানো)
// ---------------------------------------------------------------
try {
    $r2 = new R2Service(
        env_value('R2_ACCOUNT_ID'),
        env_value('R2_ACCESS_KEY_ID'),
        env_value('R2_SECRET_ACCESS_KEY'),
        env_value('R2_BUCKET_NAME'),
        env_value('R2_PUBLIC_DOMAIN', ''),
        env_value('R2_DEFAULT_FOLDER', ''),
        filter_var(env_value('R2_ENABLE_PERMISSION_CHECK', false), FILTER_VALIDATE_BOOLEAN)
    );
} catch (InvalidArgumentException $e) {
    http_response_code(500);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>R2 Setup Required</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
    <div class="container py-5" style="max-width: 720px;">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <h4 class="fw-bold mb-2">Setup required</h4>
                <p class="text-danger mb-3"><?= e($e->getMessage()) ?></p>
                <p class="mb-2">Create a <code>.env</code> file next to this page with:</p>
<pre class="bg-dark text-light p-3 rounded small mb-3">R2_ACCOUNT_ID=
R2_ACCESS_KEY_ID=
R2_SECRET_ACCESS_KEY=
R2_BUCKET_NAME=
R2_PUBLIC_DOMAIN=
R2_DEFAULT_FOLDER=
R2_ENABLE_PERMISSION_CHECK=false</pre>
                <p class="text-muted small mb-0">
                    <?php if (!class_exists(Dotenv::class)): ?>
                        The <code>.env</code> file is not loaded automatically yet. Run
                        <code>composer require vlucas/phpdotenv</code> first.
                    <?php else: ?>
                        Reload this page after saving the file.
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>
    </body>
    </html>
    <?php
    exit;
}

// ---------------------------------------------------------------
// ৬. POST অ্যাকশন হ্যান্ডলিং (শেষে redirect, যাতে রিফ্রেশে ফর্ম আবার সাবমিট না হয়)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // CSRF টোকেন যাচাই
        if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
            throw new RuntimeException('Invalid security token. Please reload the page and try again.');
        }

        $action = $_POST['action'] ?? '';

        // কী (key) আসলেই এই ফোল্ডারের ফাইল কিনা যাচাই (যেকোনো ফাইল ডিলেট ঠেকাতে)
        $assertOwnKey = function (string $key) use ($r2): string {
            $key = trim($key);
            $knownKeys = array_column($r2->listFiles(), 'key');
            if ($key === '' || !in_array($key, $knownKeys, true)) {
                throw new RuntimeException('File not found in the bucket.');
            }
            return $key;
        };

        if ($action === 'upload') {
            // নতুন ফাইল আপলোড
            $file = $_FILES['file'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException(upload_error_message((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)));
            }
            $result = $r2->uploadFile($file);
            flash('success', 'Uploaded: ' . $result['file_name']);

        } elseif ($action === 'replace') {
            // পুরাতন ফাইল বদলে নতুন ফাইল
            $oldKey = $assertOwnKey((string) ($_POST['key'] ?? ''));
            $file = $_FILES['file'] ?? null;
            if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
                throw new RuntimeException(upload_error_message((int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)));
            }
            $result = $r2->updateFile($oldKey, $file);
            flash('success', 'Replaced with: ' . $result['file_name']);

        } elseif ($action === 'delete') {
            // ফাইল ডিলেট
            $key = $assertOwnKey((string) ($_POST['key'] ?? ''));
            $r2->deleteFile($key);
            flash('success', 'Deleted: ' . basename($key));

        } elseif ($action === 'check') {
            // পারমিশন চেক (আলাদা বাটনে, প্রতি পেজ লোডে নয়)
            $_SESSION['permission'] = $r2->checkPermissions();
        }
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
    }

    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// ---------------------------------------------------------------
// ৭. পেজের ডেটা প্রস্তুত
// ---------------------------------------------------------------
$flashes = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);

$permission = $_SESSION['permission'] ?? null;
unset($_SESSION['permission']);

$files = [];
$listError = null;

try {
    $files = $r2->listFiles();
    // নতুন ফাইল আগে দেখানো
    usort($files, function ($a, $b) {
        return strcmp((string) $b['last_modified'], (string) $a['last_modified']);
    });
} catch (Throwable $e) {
    $listError = $e->getMessage();
}

$totalSize = array_sum(array_column($files, 'size'));
$bucketName = env_value('R2_BUCKET_NAME', 'R2 Bucket');
$folderName = trim((string) env_value('R2_DEFAULT_FOLDER', ''), '/');

// ফাইলের দেখানোর URL: public domain থাকলে সেটা, না থাকলে ৬০ মিনিটের signed URL
function resolve_view_url(R2Service $r2, array $file): string
{
    if ($file['url'] !== '') {
        return $file['url'];
    }
    try {
        return $r2->temporaryUrl($file['key'], 60);
    } catch (Throwable $e) {
        return '';
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cloudflare R2 Demo</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f5f7fa; }
        .card { border: 0; border-radius: 12px; box-shadow: 0 2px 8px rgba(16, 24, 40, .06); }
        .thumb { height: 150px; object-fit: cover; width: 100%; border-radius: 12px 12px 0 0; background: #e9ecef; }
        .thumb-ext { height: 150px; border-radius: 12px 12px 0 0; background: #e9ecef; }
        .stat-value { font-size: 1.5rem; font-weight: 700; }
    </style>
</head>
<body>

<div class="container py-4 py-md-5">

    <!-- Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h3 class="fw-bold mb-0">Cloudflare R2 Demo</h3>
            <small class="text-muted">Test page for jamrul/cloudflare-r2</small>
        </div>
        <div class="d-flex gap-2">
            <span class="badge text-bg-primary fs-6"><?= e($bucketName) ?></span>
            <?php if ($folderName !== ''): ?>
                <span class="badge text-bg-secondary fs-6"><?= e($folderName) ?>/</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Flash messages -->
    <?php foreach ($flashes as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show" role="alert">
            <?= e($f['message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endforeach; ?>

    <?php if ($listError): ?>
        <div class="alert alert-danger">Could not list files: <?= e($listError) ?></div>
    <?php endif; ?>

    <!-- Stats + permission check -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card p-3">
                <div class="text-muted small">Files</div>
                <div class="stat-value"><?= count($files) ?></div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card p-3">
                <div class="text-muted small">Total size</div>
                <div class="stat-value"><?= e(format_size($totalSize)) ?></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card p-3 h-100">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <div class="text-muted small">Connection test</div>
                        <?php if ($permission): ?>
                            <?php if (!$permission['enabled']): ?>
                                <span class="small text-muted">Disabled. Set <code>R2_ENABLE_PERMISSION_CHECK=true</code>.</span>
                            <?php else: ?>
                                <?php foreach (['read', 'write', 'delete'] as $perm): ?>
                                    <span class="badge text-bg-<?= !empty($permission['permissions'][$perm]) ? 'success' : 'danger' ?>">
                                        <?= ucfirst($perm) ?>
                                    </span>
                                <?php endforeach; ?>
                                <?php if (!$permission['success']): ?>
                                    <div class="small text-danger mt-1"><?= e($permission['message']) ?></div>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="small text-muted">Checks read, write and delete access.</span>
                        <?php endif; ?>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                        <input type="hidden" name="action" value="check">
                        <button type="submit" class="btn btn-sm btn-outline-primary">Run check</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Upload -->
    <div class="card p-4 mb-4">
        <h5 class="fw-bold mb-3">Upload a file</h5>
        <form method="POST" enctype="multipart/form-data" class="row g-3 align-items-end">
            <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
            <input type="hidden" name="action" value="upload">
            <div class="col-md-9">
                <input type="file" name="file" class="form-control" required>
                <div class="form-text">Max upload size: <?= e(ini_get('upload_max_filesize')) ?></div>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100 fw-semibold">Upload</button>
            </div>
        </form>
    </div>

    <!-- Files -->
    <div class="card p-4">
        <h5 class="fw-bold mb-3">Files in bucket</h5>

        <?php if (empty($files)): ?>
            <p class="text-muted mb-0">No files yet. Upload one above to get started.</p>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($files as $file): ?>
                    <?php $viewUrl = resolve_view_url($r2, $file); ?>
                    <div class="col-sm-6 col-lg-3">
                        <div class="card h-100 border">
                            <?php if ($viewUrl !== '' && is_image_file($file['name'])): ?>
                                <img src="<?= e($viewUrl) ?>" class="thumb" alt="<?= e($file['name']) ?>" loading="lazy">
                            <?php else: ?>
                                <div class="thumb-ext d-flex align-items-center justify-content-center">
                                    <span class="badge text-bg-secondary fs-6 text-uppercase">
                                        <?= e(pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'file') ?>
                                    </span>
                                </div>
                            <?php endif; ?>

                            <div class="card-body p-3 d-flex flex-column">
                                <div class="fw-semibold text-truncate" title="<?= e($file['name']) ?>"><?= e($file['name']) ?></div>
                                <div class="text-muted small mb-3">
                                    <?= e(format_size($file['size'])) ?> &middot; <?= e(substr((string) $file['last_modified'], 0, 10)) ?>
                                </div>

                                <div class="mt-auto d-flex gap-1">
                                    <?php if ($viewUrl !== ''): ?>
                                        <a href="<?= e($viewUrl) ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary flex-fill">Open</a>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm btn-outline-warning flex-fill"
                                            data-bs-toggle="modal" data-bs-target="#replaceModal"
                                            data-key="<?= e($file['key']) ?>" data-name="<?= e($file['name']) ?>">Replace</button>
                                    <form method="POST" class="flex-fill" onsubmit="return confirm('Delete this file permanently?');">
                                        <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="key" value="<?= e($file['key']) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger w-100">Delete</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <p class="text-center text-muted small mt-4 mb-0">
        Demo page only. Remove it before deploying to production.
    </p>
</div>

<!-- Replace modal (একটাই মডাল, সব ফাইলের জন্য) -->
<div class="modal fade" id="replaceModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf" value="<?= e($_SESSION['csrf']) ?>">
                <input type="hidden" name="action" value="replace">
                <input type="hidden" name="key" id="replaceKey" value="">

                <div class="modal-header">
                    <h5 class="modal-title">Replace file</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-3">Replacing: <strong id="replaceName"></strong></p>
                    <input type="file" name="file" class="form-control" required>
                    <div class="form-text">The old file is deleted only after the new one uploads successfully.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning fw-semibold">Replace</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Replace বাটনে ক্লিক করলে মডালে সঠিক ফাইলের key বসানো
    document.getElementById('replaceModal').addEventListener('show.bs.modal', function (event) {
        var button = event.relatedTarget;
        document.getElementById('replaceKey').value = button.getAttribute('data-key');
        document.getElementById('replaceName').textContent = button.getAttribute('data-name');
    });
</script>
</body>
</html>