<?php

namespace App\Services;

use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient;
use RuntimeException;

class NexframePlayStoreReportClient
{
    private ?Bucket $bucket = null;

    private ?string $configurationKey = null;

    public function downloadFirst(array $config, string $prefix, array $preferredSuffixes): ?array
    {
        $bucket = $this->bucket($config);
        $objects = [];

        foreach ($bucket->objects(['prefix' => $prefix]) as $object) {
            $name = (string) $object->name();
            if (str_ends_with(strtolower($name), '.csv')) {
                $objects[$name] = $object;
            }
        }

        if (! $objects) {
            return null;
        }

        ksort($objects);
        foreach ($preferredSuffixes as $suffix) {
            foreach ($objects as $name => $object) {
                if (str_ends_with($name, $suffix)) {
                    return ['name' => $name, 'contents' => $object->downloadAsString()];
                }
            }
        }

        return null;
    }

    private function bucket(array $config): Bucket
    {
        $bucketUri = preg_replace('#^gs://#', '', trim((string) ($config['bucket'] ?? '')));
        $bucketName = explode('/', trim($bucketUri, '/'))[0] ?? '';
        $credentialsPath = trim((string) ($config['credentials_path'] ?? ''));
        $projectId = trim((string) ($config['project_id'] ?? ''));
        $key = hash('sha256', $bucketName.'|'.$credentialsPath.'|'.$projectId);

        if ($this->bucket && $this->configurationKey === $key) {
            return $this->bucket;
        }
        if ($bucketName === '') {
            throw new RuntimeException('Google Play report bucket is not configured.');
        }

        $options = [];
        if ($credentialsPath !== '') {
            if (! is_file($credentialsPath) || ! is_readable($credentialsPath)) {
                throw new RuntimeException('Google service-account credentials file is not readable.');
            }
            $options['keyFilePath'] = $credentialsPath;
        }
        if ($projectId !== '') {
            $options['projectId'] = $projectId;
        }

        $this->bucket = (new StorageClient($options))->bucket($bucketName);
        $this->configurationKey = $key;

        return $this->bucket;
    }
}
