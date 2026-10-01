<?php

namespace Jamrul\Cloudflare;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class R2Service
{
    private S3Client $s3Client;
    private string $bucketName;
    private string $publicDomain;
    private string $defaultFolder;
    private bool $enablePermissionCheck;

    public function __construct(
        ?string $accountId,
        ?string $accessKeyId,
        ?string $secretAccessKey,
        ?string $bucketName,
        ?string $publicDomain = '',
        string $defaultFolder = '',
        bool $enablePermissionCheck = false
    ) {
        if (empty($accountId)) {
            throw new \InvalidArgumentException("R2 Configuration Error: Account ID is missing in .env file.");
        }
        if (empty($accessKeyId)) {
            throw new \InvalidArgumentException("R2 Configuration Error: Access Key ID is missing in .env file.");
        }
        if (empty($secretAccessKey)) {
            throw new \InvalidArgumentException("R2 Configuration Error: Secret Access Key is missing in .env file.");
        }
        if (empty($bucketName)) {
            throw new \InvalidArgumentException("R2 Configuration Error: Bucket Name is missing in .env file.");
        }

        $this->bucketName = $bucketName;
        $this->publicDomain = rtrim($publicDomain ?? '', '/');
        $this->defaultFolder = trim($defaultFolder, '/');
        $this->enablePermissionCheck = $enablePermissionCheck;

        $this->s3Client = new S3Client([
            'version'     => 'latest',
            'region'      => 'auto',
            'endpoint'    => "https://{$accountId}.r2.cloudflarestorage.com",
            'credentials' => [
                'key'    => $accessKeyId,
                'secret' => $secretAccessKey,
            ],
        ]);
    }

    /**
     * R2-তে নতুন ফাইল আপলোড করা
     */
    public function uploadFile(array $file, ?string $folder = null): array
    {
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \Exception("File upload error code: " . $file['error']);
        }

        $folderName = $folder !== null ? trim($folder, '/') : $this->defaultFolder;
        $fileName = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($file['name']));
        $key = !empty($folderName) ? "{$folderName}/{$fileName}" : $fileName;

        try {
            $result = $this->s3Client->putObject([
                'Bucket'      => $this->bucketName,
                'Key'         => $key,
                'SourceFile'  => $file['tmp_name'],
                'ContentType' => $file['type'] ?? mime_content_type($file['tmp_name']),
            ]);

            $url = $this->publicDomain ? "{$this->publicDomain}/{$key}" : '';

            return [
                'key'       => $key,
                'file_name' => basename($file['name']),
                'url'       => $url,
                'size'      => $file['size'],
                'type'      => $file['type'],
            ];
        } catch (AwsException $e) {
            throw new \Exception("R2 Upload Failed: " . $e->getAwsErrorMessage());
        }
    }

    /**
     * R2 থেকে ফাইল ডিলেট করা
     */
    public function deleteFile(string $key): bool
    {
        try {
            $this->s3Client->deleteObject([
                'Bucket' => $this->bucketName,
                'Key'    => $key,
            ]);
            return true;
        } catch (AwsException $e) {
            throw new \Exception("R2 Delete Failed: " . $e->getAwsErrorMessage());
        }
    }

    /**
     * পুরাতন ফাইল ডিলেট করে নতুন ফাইল আপলোড (Update)
     */
    public function updateFile(string $oldKey, array $newFile, ?string $folder = null): array
    {
        if (!empty($oldKey)) {
            $this->deleteFile($oldKey);
        }
        return $this->uploadFile($newFile, $folder);
    }

    public function getImages(?string $folder = null): array
    {
        $prefix = $folder ?? $this->defaultFolder;
        if (!empty($prefix) && !str_ends_with($prefix, '/')) {
            $prefix .= '/';
        }

        try {
            $params = ['Bucket' => $this->bucketName];
            if (!empty($prefix)) {
                $params['Prefix'] = $prefix;
            }

            $results = $this->s3Client->listObjectsV2($params);

            $images = [];
            if (isset($results['Contents'])) {
                foreach ($results['Contents'] as $object) {
                    $key = $object['Key'];
                    if ($key === $prefix) continue;

                    $images[] = [
                        'key'  => $key,
                        'name' => basename($key),
                        'url'  => $this->publicDomain ? $this->publicDomain . '/' . $key : '',
                    ];
                }
            }

            return $images;
        } catch (AwsException $e) {
            throw new \Exception("R2 API Error: " . $e->getAwsErrorMessage());
        }
    }

    public function checkPermissions(): array
    {
        if (!$this->enablePermissionCheck) {
            return [
                'enabled'     => false,
                'success'     => true,
                'permissions' => [],
                'message'     => 'Permission check is disabled in .env.'
            ];
        }

        $status = ['read' => false, 'write' => false, 'delete' => false];
        $testFileName = 'permission_test_' . time() . '.txt';

        try {
            $this->s3Client->listObjectsV2(['Bucket' => $this->bucketName, 'MaxKeys' => 1]);
            $status['read'] = true;

            $this->s3Client->putObject([
                'Bucket' => $this->bucketName,
                'Key'    => $testFileName,
                'Body'   => 'Permission test',
            ]);
            $status['write'] = true;

            $this->s3Client->deleteObject([
                'Bucket' => $this->bucketName,
                'Key'    => $testFileName,
            ]);
            $status['delete'] = true;

            return [
                'enabled'     => true,
                'success'     => true,
                'permissions' => $status,
                'message'     => 'All permissions are working perfectly!',
            ];
        } catch (AwsException $e) {
            return [
                'enabled'     => true,
                'success'     => false,
                'permissions' => $status,
                'message'     => "Permission Test Failed: " . $e->getAwsErrorMessage(),
            ];
        }
    }
}