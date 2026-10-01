<?php

namespace Jamrul\Cloudflare;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class R2Service
{
    // AWS S3 ক্লায়েন্ট অবজেক্ট (R2 এই S3 API-ই সাপোর্ট করে)
    private S3Client $s3Client;

    // R2 বাকেটের নাম
    private string $bucketName;

    // ফাইলের public URL বানানোর জন্য ডোমেইন (যেমন: https://cdn.example.com)
    private string $publicDomain;

    // ফোল্ডার না দিলে এই ডিফল্ট ফোল্ডারে ফাইল যাবে
    private string $defaultFolder;

    // পারমিশন চেক ফিচার চালু/বন্ধ করার ফ্ল্যাগ
    private bool $enablePermissionCheck;

    /**
     * কনস্ট্রাক্টর: কনফিগারেশন যাচাই করে R2 ক্লায়েন্ট তৈরি করে
     */
    public function __construct(
        ?string $accountId,
        ?string $accessKeyId,
        ?string $secretAccessKey,
        ?string $bucketName,
        ?string $publicDomain = '',
        string $defaultFolder = '',
        bool $enablePermissionCheck = false
    ) {
        // অ্যাকাউন্ট আইডি না থাকলে এরর দাও
        if (empty($accountId)) {
            throw new \InvalidArgumentException('R2 Configuration Error: Account ID is missing.');
        }
        // অ্যাক্সেস কী না থাকলে এরর দাও
        if (empty($accessKeyId)) {
            throw new \InvalidArgumentException('R2 Configuration Error: Access Key ID is missing.');
        }
        // সিক্রেট কী না থাকলে এরর দাও
        if (empty($secretAccessKey)) {
            throw new \InvalidArgumentException('R2 Configuration Error: Secret Access Key is missing.');
        }
        // বাকেটের নাম না থাকলে এরর দাও
        if (empty($bucketName)) {
            throw new \InvalidArgumentException('R2 Configuration Error: Bucket Name is missing.');
        }

        // প্রপার্টিগুলো সেট করা (শেষের স্ল্যাশ বাদ দিয়ে)
        $this->bucketName = $bucketName;
        $this->publicDomain = rtrim($publicDomain ?? '', '/');
        $this->defaultFolder = trim($defaultFolder, '/');
        $this->enablePermissionCheck = $enablePermissionCheck;

        // R2-এর জন্য S3 ক্লায়েন্ট কনফিগার করা
        $this->s3Client = new S3Client([
            'version'     => 'latest',
            // R2-তে রিজিয়ন সবসময় 'auto' হয়
            'region'      => 'auto',
            // অ্যাকাউন্ট আইডি দিয়ে R2 এন্ডপয়েন্ট বানানো
            'endpoint'    => "https://{$accountId}.r2.cloudflarestorage.com",
            'credentials' => [
                'key'    => $accessKeyId,
                'secret' => $secretAccessKey,
            ],
            // নতুন AWS SDK-র ডিফল্ট চেকসাম R2-তে সমস্যা করে, তাই শুধু দরকারে চালু (SDK 3.337+)
            'request_checksum_calculation' => 'when_required',
            'response_checksum_validation' => 'when_required',
        ]);
    }

    /**
     * R2-তে নতুন ফাইল আপলোড করা ($_FILES-এর একটি এন্ট্রি দিতে হবে)
     */
    public function uploadFile(array $file, ?string $folder = null): array
    {
        // দরকারি কী-গুলো আছে কিনা দেখা
        if (!isset($file['tmp_name'], $file['name'], $file['error'])) {
            throw new \InvalidArgumentException('Invalid file array: tmp_name, name and error are required.');
        }

        // আপলোডে কোনো এরর হলে থামিয়ে দাও
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('File upload error code: ' . $file['error']);
        }

        // টেম্প ফাইলটি আসলেই আছে কিনা যাচাই করা
        if (!is_file($file['tmp_name'])) {
            throw new \RuntimeException('Temporary upload file not found.');
        }

        // ফাইলের আসল MIME টাইপ বের করা (ক্লায়েন্টের পাঠানো টাইপ বিশ্বাসযোগ্য নয়)
        $contentType = $this->detectMimeType($file);

        // ইউনিক ফাইলের নাম ও সম্পূর্ণ key তৈরি করা
        $fileName = $this->generateFileName($file['name']);
        $key = $this->buildKey($fileName, $folder);

        try {
            // ফাইলটি R2-তে পাঠানো
            $this->s3Client->putObject([
                'Bucket'      => $this->bucketName,
                'Key'         => $key,
                'SourceFile'  => $file['tmp_name'],
                'ContentType' => $contentType,
            ]);

            // সফল হলে ফাইলের তথ্য ফেরত দেওয়া
            return [
                'key'       => $key,
                'file_name' => basename($file['name']),
                'url'       => $this->getUrl($key),
                'size'      => $file['size'] ?? filesize($file['tmp_name']),
                'type'      => $contentType,
            ];
        } catch (AwsException $e) {
            throw $this->wrapAwsException('R2 Upload Failed', $e);
        }
    }

    /**
     * লোকাল পাথ থেকে সরাসরি ফাইল আপলোড করা (ফর্ম ছাড়া, যেমন: ক্রন বা স্ক্রিপ্ট)
     */
    public function uploadFromPath(string $path, ?string $folder = null, ?string $customName = null): array
    {
        // ফাইল না থাকলে এরর দাও
        if (!is_file($path)) {
            throw new \InvalidArgumentException("File not found: {$path}");
        }

        // $_FILES-এর মতো অ্যারে বানিয়ে একই মেথডে পাঠানো
        return $this->uploadFile([
            'name'     => $customName ?? basename($path),
            'tmp_name' => $path,
            'error'    => UPLOAD_ERR_OK,
            'size'     => filesize($path),
        ], $folder);
    }

    /**
     * স্ট্রিং/কনটেন্ট সরাসরি R2-তে সেভ করা (যেমন: জেনারেট করা PDF বা JSON)
     */
    public function uploadContent(string $content, string $fileName, ?string $folder = null, string $contentType = 'application/octet-stream'): array
    {
        // ইউনিক নাম ও key বানানো
        $key = $this->buildKey($this->generateFileName($fileName), $folder);

        try {
            // কনটেন্ট সরাসরি Body হিসেবে পাঠানো
            $this->s3Client->putObject([
                'Bucket'      => $this->bucketName,
                'Key'         => $key,
                'Body'        => $content,
                'ContentType' => $contentType,
            ]);

            return [
                'key'       => $key,
                'file_name' => basename($fileName),
                'url'       => $this->getUrl($key),
                'size'      => strlen($content),
                'type'      => $contentType,
            ];
        } catch (AwsException $e) {
            throw $this->wrapAwsException('R2 Upload Failed', $e);
        }
    }

    /**
     * R2 থেকে একটি ফাইল ডিলেট করা
     */
    public function deleteFile(string $key): bool
    {
        try {
            // key অনুযায়ী অবজেক্ট মুছে ফেলা
            $this->s3Client->deleteObject([
                'Bucket' => $this->bucketName,
                'Key'    => ltrim($key, '/'),
            ]);
            return true;
        } catch (AwsException $e) {
            throw $this->wrapAwsException('R2 Delete Failed', $e);
        }
    }

    /**
     * একসাথে অনেকগুলো ফাইল ডিলেট করা (একবারে সর্বোচ্চ ১০০০টি করে)
     */
    public function deleteFiles(array $keys): bool
    {
        // খালি অ্যারে হলে কিছু করার নেই
        if (empty($keys)) {
            return true;
        }

        try {
            // ১০০০টি করে ভাগ করে ডিলেট রিকোয়েস্ট পাঠানো (S3 API-র লিমিট)
            foreach (array_chunk($keys, 1000) as $chunk) {
                $objects = array_map(function ($key) {
                    return ['Key' => ltrim($key, '/')];
                }, $chunk);

                $this->s3Client->deleteObjects([
                    'Bucket' => $this->bucketName,
                    'Delete' => ['Objects' => $objects, 'Quiet' => true],
                ]);
            }
            return true;
        } catch (AwsException $e) {
            throw $this->wrapAwsException('R2 Bulk Delete Failed', $e);
        }
    }

    /**
     * নতুন ফাইল আপলোড করে তারপর পুরাতন ফাইল ডিলেট করা (Update)
     * আগে আপলোড করা হয় যাতে আপলোড ফেইল করলে পুরাতন ফাইল হারিয়ে না যায়
     */
    public function updateFile(string $oldKey, array $newFile, ?string $folder = null): array
    {
        // আগে নতুন ফাইল আপলোড
        $result = $this->uploadFile($newFile, $folder);

        // আপলোড সফল হলে তবেই পুরাতন ফাইল মুছে ফেলা
        if (!empty($oldKey) && $oldKey !== $result['key']) {
            $this->deleteFile($oldKey);
        }

        return $result;
    }

    /**
     * ফাইল R2-তে আছে কিনা চেক করা
     */
    public function fileExists(string $key): bool
    {
        try {
            // doesObjectExist ফাইল থাকলে true, না থাকলে false দেয়
            return $this->s3Client->doesObjectExist($this->bucketName, ltrim($key, '/'));
        } catch (AwsException $e) {
            throw $this->wrapAwsException('R2 Exists Check Failed', $e);
        }
    }

    /**
     * ফাইলের public URL বের করা (publicDomain সেট না থাকলে ফাঁকা স্ট্রিং)
     */
    public function getUrl(string $key): string
    {
        // ডোমেইন না থাকলে URL বানানো সম্ভব নয়
        if ($this->publicDomain === '') {
            return '';
        }
        return $this->publicDomain . '/' . ltrim($key, '/');
    }

    /**
     * প্রাইভেট ফাইলের জন্য সময়-সীমাবদ্ধ (signed) URL তৈরি করা
     */
    public function temporaryUrl(string $key, int $minutes = 60): string
    {
        try {
            // GetObject কমান্ড তৈরি
            $command = $this->s3Client->getCommand('GetObject', [
                'Bucket' => $this->bucketName,
                'Key'    => ltrim($key, '/'),
            ]);

            // নির্দিষ্ট মিনিট পর মেয়াদ শেষ হবে এমন signed রিকোয়েস্ট বানানো
            $request = $this->s3Client->createPresignedRequest($command, "+{$minutes} minutes");

            return (string) $request->getUri();
        } catch (AwsException $e) {
            throw $this->wrapAwsException('R2 Temporary URL Failed', $e);
        }
    }

    /**
     * ফোল্ডারের সব ফাইলের তালিকা আনা (১০০০-এর বেশি হলেও পেজিনেশন নিজে হ্যান্ডেল করে)
     */
    public function listFiles(?string $folder = null): array
    {
        // প্রিফিক্স ঠিক করা (শেষে '/' সহ)
        $prefix = $this->normalizePrefix($folder);

        try {
            $params = ['Bucket' => $this->bucketName];
            if ($prefix !== '') {
                $params['Prefix'] = $prefix;
            }

            $files = [];

            // পেজিনেটর দিয়ে সব পেজ ঘুরে ফাইল সংগ্রহ করা
            foreach ($this->s3Client->getPaginator('ListObjectsV2', $params) as $page) {
                foreach ($page['Contents'] ?? [] as $object) {
                    $key = $object['Key'];

                    // ফোল্ডারের নিজের এন্ট্রি বাদ দেওয়া
                    if ($key === $prefix) {
                        continue;
                    }

                    $files[] = [
                        'key'           => $key,
                        'name'          => basename($key),
                        'size'          => $object['Size'] ?? 0,
                        'last_modified' => isset($object['LastModified']) ? $object['LastModified']->format('Y-m-d H:i:s') : null,
                        'url'           => $this->getUrl($key),
                    ];
                }
            }

            return $files;
        } catch (AwsException $e) {
            throw $this->wrapAwsException('R2 API Error', $e);
        }
    }

    /**
     * পুরাতন কোডের সাথে মিল রাখার জন্য: listFiles-এর অ্যালিয়াস
     */
    public function getImages(?string $folder = null): array
    {
        return $this->listFiles($folder);
    }

    /**
     * পারমিশন চেক: পড়া, লেখা ও ডিলেট করা যাচ্ছে কিনা পরীক্ষা করে
     */
    public function checkPermissions(): array
    {
        // ফিচার বন্ধ থাকলে সরাসরি ফেরত
        if (!$this->enablePermissionCheck) {
            return [
                'enabled'     => false,
                'success'     => true,
                'permissions' => [],
                'message'     => 'Permission check is disabled.',
            ];
        }

        // শুরুতে সব পারমিশন false ধরা হলো
        $status = ['read' => false, 'write' => false, 'delete' => false];

        // টেস্টের জন্য ইউনিক নামের ডামি ফাইল
        $testFileName = 'permission_test_' . time() . '_' . bin2hex(random_bytes(3)) . '.txt';

        try {
            // রিড টেস্ট: লিস্ট করে দেখা
            $this->s3Client->listObjectsV2(['Bucket' => $this->bucketName, 'MaxKeys' => 1]);
            $status['read'] = true;

            // রাইট টেস্ট: ছোট ফাইল আপলোড করা
            $this->s3Client->putObject([
                'Bucket' => $this->bucketName,
                'Key'    => $testFileName,
                'Body'   => 'Permission test',
            ]);
            $status['write'] = true;

            // ডিলেট টেস্ট: টেস্ট ফাইল মুছে ফেলা
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
            // কোনো ধাপে ফেইল করলে কতদূর পর্যন্ত সফল হয়েছে তা জানিয়ে দেওয়া
            return [
                'enabled'     => true,
                'success'     => false,
                'permissions' => $status,
                'message'     => 'Permission Test Failed: ' . $this->extractAwsMessage($e),
            ];
        }
    }

    /**
     * ফোল্ডার ও ফাইলের নাম জুড়ে সম্পূর্ণ key বানানো
     */
    private function buildKey(string $fileName, ?string $folder = null): string
    {
        // ফোল্ডার দেওয়া থাকলে সেটা, না থাকলে ডিফল্ট ফোল্ডার
        $folderName = $folder !== null ? trim($folder, '/') : $this->defaultFolder;

        return $folderName !== '' ? "{$folderName}/{$fileName}" : $fileName;
    }

    /**
     * নিরাপদ ও ইউনিক ফাইলের নাম তৈরি করা
     */
    private function generateFileName(string $originalName): string
    {
        // অক্ষর, সংখ্যা, ডট, ডাশ ও আন্ডারস্কোর ছাড়া সব কিছু '_' করে দেওয়া
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($originalName));

        // সময় + র‍্যান্ডম অংশ যোগ করে নাম ডুপ্লিকেট হওয়া ঠেকানো
        return time() . '_' . bin2hex(random_bytes(3)) . '_' . $safeName;
    }

    /**
     * ফাইলের আসল MIME টাইপ বের করা
     */
    private function detectMimeType(array $file): string
    {
        // fileinfo এক্সটেনশন থাকলে আসল কনটেন্ট দেখে টাইপ বের করা
        if (function_exists('mime_content_type')) {
            $detected = @mime_content_type($file['tmp_name']);
            if (!empty($detected)) {
                return $detected;
            }
        }

        // না পেলে ক্লায়েন্টের দেওয়া টাইপ, তাও না থাকলে সাধারণ বাইনারি টাইপ
        return !empty($file['type']) ? $file['type'] : 'application/octet-stream';
    }

    /**
     * ফোল্ডারের নাম থেকে প্রিফিক্স বানানো (শেষে '/' নিশ্চিত করে)
     */
    private function normalizePrefix(?string $folder): string
    {
        // ফোল্ডার না দিলে ডিফল্ট ফোল্ডার ব্যবহার
        $prefix = $folder !== null ? trim($folder, '/') : $this->defaultFolder;

        // ফাঁকা হলে পুরো বাকেট, নাহলে শেষে '/' যোগ
        return $prefix === '' ? '' : $prefix . '/';
    }

    /**
     * AwsException থেকে পরিষ্কার এরর মেসেজ বের করা
     */
    private function extractAwsMessage(AwsException $e): string
    {
        // AWS-এর মেসেজ না থাকলে সাধারণ মেসেজ ব্যবহার
        return $e->getAwsErrorMessage() ?: $e->getMessage();
    }

    /**
     * AwsException-কে RuntimeException-এ মুড়িয়ে আসল এরর সংরক্ষণ করা
     */
    private function wrapAwsException(string $prefix, AwsException $e): \RuntimeException
    {
        // previous হিসেবে আসল এক্সেপশন পাঠানো যাতে ডিবাগ করা যায়
        return new \RuntimeException($prefix . ': ' . $this->extractAwsMessage($e), 0, $e);
    }
}