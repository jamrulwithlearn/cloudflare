<?php

return [

    // Cloudflare অ্যাকাউন্ট আইডি (R2 এন্ডপয়েন্ট বানাতে লাগে)
    'account_id' => env('R2_ACCOUNT_ID'),

    // R2 API টোকেনের Access Key ID
    'access_key_id' => env('R2_ACCESS_KEY_ID'),

    // R2 API টোকেনের Secret Access Key
    'secret_access_key' => env('R2_SECRET_ACCESS_KEY'),

    // বাকেটের নাম
    'bucket' => env('R2_BUCKET_NAME'),

    // ফাইলের public URL-এর ডোমেইন (যেমন: https://cdn.example.com), না থাকলে ফাঁকা
    'public_domain' => env('R2_PUBLIC_DOMAIN', ''),

    // ফোল্ডার না দিলে ফাইল যে ডিফল্ট ফোল্ডারে যাবে, না থাকলে ফাঁকা
    'default_folder' => env('R2_DEFAULT_FOLDER', ''),

    // পারমিশন চেক ফিচার চালু করতে true দিন (ডিফল্ট বন্ধ)
    'permission_check' => env('R2_ENABLE_PERMISSION_CHECK', false),

];
