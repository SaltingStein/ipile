<?php

namespace App\Services\Media;

use App\Services\Integrations\IntegrationTester;
use App\Services\Integrations\IntegrationTestResult;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * "Test connection" for the storage bucket: writes a tiny file, checks it is there and deletes it again.
 */
class BucketStorageTester implements IntegrationTester
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function test(array $config): IntegrationTestResult
    {
        $disk = Storage::build(MediaStorage::diskConfig($config));
        $path = 'ipile-connection-test/'.Str::random(16).'.txt';

        $disk->put($path, 'Ìpìlẹ̀ connection test. Safe to delete.');
        $found = $disk->exists($path);
        $disk->delete($path);

        return $found
            ? IntegrationTestResult::passed(__('Connected. A test file was written to the bucket and deleted again.'))
            : IntegrationTestResult::failed(__('The bucket accepted the test file but it could not be found afterwards. Check the bucket name and region.'));
    }
}
