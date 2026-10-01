<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/cloudflare_file_db.php';

use Dotenv\Dotenv;
use Jamrul\Cloudflare\R2Service;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

$r2Service = new R2Service(
    $_ENV['R2_ACCOUNT_ID'] ?? null,
    $_ENV['R2_ACCESS_KEY_ID'] ?? null,
    $_ENV['R2_SECRET_ACCESS_KEY'] ?? null,
    $_ENV['R2_BUCKET_NAME'] ?? null,
    $_ENV['R2_PUBLIC_DOMAIN'] ?? '',
    $_ENV['R2_DEFAULT_FOLDER'] ?? '',
    filter_var($_ENV['R2_ENABLE_PERMISSION_CHECK'] ?? false, FILTER_VALIDATE_BOOLEAN)
);

$fileDb = new CloudflareFileDB();

$flashSuccess = null;
$flashError = null;

// =======================
// POST Action Handling
// =======================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        // ১. Upload Action (INSERT)
        if ($action === 'upload') {
            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                throw new \Exception("Please select a valid file to upload.");
            }

            $title = trim($_POST['title'] ?? '');
            $r2Data = $r2Service->uploadFile($_FILES['file']);

            $fileDb->insert([
                'title'     => $title ?: $r2Data['file_name'],
                'file_key'  => $r2Data['key'],
                'file_name' => $r2Data['file_name'],
                'file_url'  => $r2Data['url'],
                'file_size' => $r2Data['size'],
                'file_type' => $r2Data['type'],
            ]);

            $flashSuccess = "File uploaded to R2 and saved to database successfully!";
        }

        // ২. Replace Action (UPDATE)
        elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $existingRecord = $fileDb->getById($id);

            if (!$existingRecord) {
                throw new \Exception("File record not found in database.");
            }

            $newTitle = trim($_POST['title'] ?? '');

            if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
                // R2-তে পুরাতন ফাইল রিপ্লেস করে নতুন ফাইল আপলোড
                $r2Data = $r2Service->updateFile($existingRecord['file_key'], $_FILES['file']);

                $fileDb->update($id, [
                    'title'     => $newTitle ?: $r2Data['file_name'],
                    'file_key'  => $r2Data['key'],
                    'file_name' => $r2Data['file_name'],
                    'file_url'  => $r2Data['url'],
                    'file_size' => $r2Data['size'],
                    'file_type' => $r2Data['type'],
                ]);
            } else {
                // শুধু টাইটেল আপডেট
                $fileDb->update($id, [
                    'title'     => $newTitle ?: $existingRecord['file_name'],
                    'file_key'  => $existingRecord['file_key'],
                    'file_name' => $existingRecord['file_name'],
                    'file_url'  => $existingRecord['file_url'],
                    'file_size' => $existingRecord['file_size'],
                    'file_type' => $existingRecord['file_type'],
                ]);
            }

            $flashSuccess = "Record updated successfully!";
        }

        // ৩. Delete Action (DELETE)
        elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            $existingRecord = $fileDb->getById($id);

            if ($existingRecord) {
                // R2 storage থেকে ডিলেট
                $r2Service->deleteFile($existingRecord['file_key']);
                // DB থেকে ডিলেট
                $fileDb->delete($id);

                $flashSuccess = "File deleted from R2 and database!";
            }
        }
    } catch (\Exception $e) {
        $flashError = $e->getMessage();
    }
}

$permissionInfo = $r2Service->checkPermissions();
$dbFiles = $fileDb->getAll();

?>
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cloudflare R2 File Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .card { border-radius: 10px; border: none; box-shadow: 0 4px 6px rgba(0,0,0,0.05); }
        .img-preview { height: 160px; object-fit: cover; width: 100%; border-top-left-radius: 10px; border-top-right-radius: 10px; }
    </style>
</head>
<body>

<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2 class="fw-bold text-dark">Cloudflare R2 Manager</h2>
        <span class="badge bg-primary fs-6"><?= htmlspecialchars($_ENV['R2_BUCKET_NAME'] ?? 'R2 Bucket') ?></span>
    </div>

    <!-- Alert Messages -->
    <?php if ($flashSuccess): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($flashSuccess) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($flashError): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($flashError) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Upload Form Card -->
    <div class="card p-4 mb-4">
        <h5 class="fw-bold mb-3">Upload File to R2 Storage</h5>
        <form action="" method="POST" enctype="multipart/form-data" class="row g-3">
            <input type="hidden" name="action" value="upload">
            <div class="col-md-5">
                <label class="form-label font-weight-bold">Title / Description</label>
                <input type="text" name="title" class="form-control" placeholder="e.g., Profile Picture">
            </div>
            <div class="col-md-5">
                <label class="form-label font-weight-bold">Select File *</label>
                <input type="file" name="file" class="form-control" required>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100 fw-bold">Upload File</button>
            </div>
        </form>
    </div>

    <!-- Database Files List -->
    <div class="card p-4">
        <h5 class="fw-bold mb-3">Uploaded Files (DB Tracked)</h5>

        <?php if (!empty($dbFiles)): ?>
            <div class="row g-3">
                <?php foreach ($dbFiles as $file): ?>
                    <div class="col-md-3">
                        <div class="card h-100 border">
                            <?php if (!empty($file['file_url'])): ?>
                                <img src="<?= htmlspecialchars($file['file_url']) ?>" class="img-preview" alt="<?= htmlspecialchars($file['title']) ?>" onerror="this.src='https://via.placeholder.com/300x160?text=No+Preview'">
                            <?php else: ?>
                                <div class="img-preview bg-secondary d-flex align-items-center justify-content-center text-white">No Public URL</div>
                            <?php endif; ?>

                            <div class="card-body p-3">
                                <h6 class="fw-bold text-truncate mb-1" title="<?= htmlspecialchars($file['title']) ?>">
                                    <?= htmlspecialchars($file['title']) ?>
                                </h6>
                                <p class="text-muted small mb-2 text-truncate"><?= htmlspecialchars($file['file_name']) ?></p>

                                <div class="d-flex gap-2 mt-3">
                                    <!-- Edit Button (Modal Trigger) -->
                                    <button class="btn btn-sm btn-outline-warning w-50" data-bs-toggle="modal" data-bs-target="#editModal<?= $file['id'] ?>">Edit</button>

                                    <!-- Delete Form -->
                                    <form action="" method="POST" class="w-50" onsubmit="return confirm('Are you sure to delete this file?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $file['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger w-100">Delete</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Edit Modal -->
                    <div class="modal fade" id="editModal<?= $file['id'] ?>" tabindex="-1">
                        <div class="modal-dialog">
                            <div class="modal-content">
                                <form action="" method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="id" value="<?= $file['id'] ?>">

                                    <div class="modal-header">
                                        <h5 class="modal-title">Edit / Replace File</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div class="mb-3">
                                            <label class="form-label">Title</label>
                                            <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($file['title']) ?>">
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Replace File (Optional)</label>
                                            <input type="file" name="file" class="form-control">
                                            <small class="text-muted">Leave empty if you don't want to change the file.</small>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                        <button type="submit" class="btn btn-success">Save Changes</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <p class="text-muted mb-0">No files found in database.</p>
        <?php endif; ?>
    </div>
</div>

<script https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js></script>
</body>
</html>