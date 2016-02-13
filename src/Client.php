<?php

namespace ChrisWhite\B2;

use ChrisWhite\B2\Exceptions\ValidationException;
use ChrisWhite\B2\Http\Client as HttpClient;

class Client
{
    protected $accountId;
    protected $applicationKey;

    protected $authToken;
    protected $apiUrl;
    protected $downloadUrl;

    protected $client;

    /**
     * Client constructor. Accepts the account ID, application key and an optional array of options.
     *
     * @param $accountId
     * @param $applicationKey
     * @param array $options
     */
    public function __construct($accountId, $applicationKey, array $options = [])
    {
        $this->accountId = $accountId;
        $this->applicationKey = $applicationKey;

        if (isset($options['client'])) {
            $this->client = $options['client'];
        } else {
            $this->client = new HttpClient(['exceptions' => false]);
        }

        $this->authoriseAccount();
    }

    /**
     * Create a bucket with the given name and type.
     *
     * @param array $options
     * @return Bucket
     * @throws ValidationException
     */
    public function createBucket(array $options)
    {
        if (!in_array($options['Type'], [Bucket::TYPE_PUBLIC, Bucket::TYPE_PRIVATE])) {
            throw new ValidationException(
                sprintf('Bucket type must be %s or %s', Bucket::TYPE_PRIVATE, Bucket::TYPE_PUBLIC)
            );
        }

        $response = $this->client->request('POST', $this->apiUrl.'/b2_create_bucket', [
            'headers' => [
                'Authorization' => $this->authToken,
            ],
            'json' => [
                'accountId' => $this->accountId,
                'bucketName' => $options['Bucket'],
                'bucketType' => $options['Type']
            ]
        ]);

        return new Bucket($response['bucketId'], $response['bucketName'], $response['bucketType']);
    }

    /**
     * Updates the type attribute of a bucket by the given ID.
     *
     * @param $id
     * @param $type
     * @return Bucket
     * @throws ValidationException
     */
    public function updateBucket($id, $type)
    {
        if (!in_array($type, [Bucket::TYPE_PUBLIC, Bucket::TYPE_PRIVATE])) {
            throw new ValidationException(
                sprintf('Bucket type must be %s or %s', Bucket::TYPE_PRIVATE, Bucket::TYPE_PUBLIC)
            );
        }

        $response = $this->client->request('POST', $this->apiUrl.'/b2_update_bucket', [
            'headers' => [
                'Authorization' => $this->authToken,
            ],
            'json' => [
                'accountId' => $this->accountId,
                'bucketId' => $id,
                'bucketType' => $type
            ]
        ]);

        return new Bucket($response['bucketId'], $response['bucketName'], $response['bucketType']);
    }

    /**
     * Returns a list of bucket objects representing the buckets on the account.
     *
     * @return array
     */
    public function listBuckets()
    {
        $buckets = [];

        $response = $this->client->request('POST', $this->apiUrl.'/b2_list_buckets', [
            'headers' => [
                'Authorization' => $this->authToken,
            ],
            'json' => [
                'accountId' => $this->accountId
            ]
        ]);

        foreach ($response['buckets'] as $bucket) {
            $buckets[] = new Bucket($bucket['bucketId'], $bucket['bucketName'], $bucket['bucketType']);
        }

        return $buckets;
    }

    /**
     * Deletes the bucket identified by its ID.
     * TODO: what if you delete a bucket that already exists?
     *
     * @param $id
     * @return bool
     */
    public function deleteBucket($id)
    {
        $this->client->request('POST', $this->apiUrl.'/b2_delete_bucket', [
            'headers' => [
                'Authorization' => $this->authToken
            ],
            'json' => [
                'accountId' => $this->accountId,
                'bucketId' => $id
            ]
        ]);

        return true;
    }

    /**
     * Uploads a file to a bucket and returns a File object.
     *
     * @param $bucketId
     * @param $path
     * @param $body
     * @return bool
     */
    public function upload($bucketId, $path, $body)
    {
        // Clean the path if it starts with /.
        if (substr($path, 0, 1) === '/') {
            $path = ltrim($path, '/');
        }

        // Retrieve the URL that we should be uploading to.
        $response = $this->client->request('POST', $this->apiUrl.'/b2_get_upload_url', [
            'headers' => [
                'Authorization' => $this->authToken
            ],
            'json' => [
                'bucketId' => $bucketId
            ]
        ]);

        $uploadEndpoint = $response['uploadUrl'];
        $uploadAuthToken = $response['authorizationToken'];

        if (is_resource($body)) {
            // We need to calculate the hash incrementally from the stream.
            $context = hash_init('sha1');
            hash_update_stream($context, $body);
            $hash = hash_final($context);

            // Similarly, we have to use fstat to get the size of the stream.
            $size = fstat($body)['size'];
        } else {
            // We've been given a simple string body, it's super simple to calculate the hash and size.
            $hash = sha1($body);
            $size = mb_strlen($body);
        }

        $response = $this->client->request('POST', $uploadEndpoint, [
            'headers' => [
                'Authorization' => $uploadAuthToken,
                // TODO: work out the content type, or allow it to be passed in
                'Content-Type' => 'application/octet-stream',
                'Content-Length' => $size,
                'X-Bz-File-Name' => $path,
                'X-Bz-Content-Sha1' => $hash,
                // TODO: work out the last modified time
                'X-Bz-Info-src_last_modified_millis' => round(microtime(true) * 1000)
            ],
            'body' => $body
        ]);

        return new File(
            $response['fileId'],
            $response['fileName'],
            $response['contentSha1'],
            $response['contentLength'],
            $response['contentType'],
            $response['fileInfo']
        );
    }

    /**
     * Download a file identified by its ID.
     *
     * @param $fileId
     * @param null $savePathOrResource
     * @return bool|string
     */
    public function downloadById($fileId, $savePathOrResource = null)
    {
        $response = $this->client->request('GET', $this->downloadUrl.'/b2api/v1/b2_download_file_by_id', [
            'headers' => [
                'Authorization' => $this->authToken
            ],
            'query' => [
                'fileId' => $fileId
            ],
            'sink' => $savePathOrResource
        ], false);

        return is_null($savePathOrResource) ? $response : true;
    }

    /**
     * Download a file identified by its path.
     *
     * @param $bucketName
     * @param $filePath
     * @param null $savePathOrResource
     * @return bool|string
     */
    public function downloadByPath($bucketName, $filePath, $savePathOrResource = null)
    {
        $url = sprintf('%s/file/%s/%s', $this->downloadUrl, $bucketName, $filePath);

        $response = $this->client->request('GET', $url, [
            'headers' => [
                'Authorization' => $this->authToken
            ],
            'sink' => $savePathOrResource
        ], false);

        return is_null($savePathOrResource) ? $response : true;
    }

    /**
     * Retrieve a collection of File objects representing the files stored inside a bucket.
     *
     * @param $bucketId
     * @return array
     */
    public function listFiles($bucketId)
    {
        $nextFileName = null;
        $files = [];

        // B2 returns, at most, 1000 files per "page". Loop through the pages and compile an array of File objects.
        while (true) {
            $response = $this->client->request('POST', $this->apiUrl.'/b2_list_file_names', [
                'headers' => [
                    'Authorization' => $this->authToken
                ],
                'json' => [
                    'bucketId' => $bucketId,
                    'startFileName' => $nextFileName,
                    'maxFileCount' => 10
                ]
            ]);

            foreach ($response['files'] as $file) {
                $files[] = new File($file['fileId'], $file['fileName'], null, $file['size']);
            }

            if ($response['nextFileName'] === null) {
                // We've got all the files - break out of loop.
                break;
            }

            $nextFileName = $response['nextFileName'];
        }

        return $files;
    }

    /**
     * Returns a single File object representing a file stored on B2.
     *
     * @param $fileId
     * @return File
     */
    public function getFile($fileId)
    {
        $response = $this->client->request('POST', $this->apiUrl.'/b2_get_file_info', [
            'headers' => [
                'Authorization' => $this->authToken
            ],
            'json' => [
                'fileId' => $fileId
            ]
        ]);

        return new File(
            $response['fileId'],
            $response['fileName'],
            $response['contentSha1'],
            $response['contentLength'],
            $response['contentType'],
            $response['fileInfo']
        );
    }

    /**
     * Deletes the file identified by ID from Backblaze B2.
     *
     * @param $fileId
     * @param null $fileName
     * @return bool
     */
    public function deleteFile($fileId, $fileName = null)
    {
        if ($fileName === null) {
            $file = $this->getFile($fileId);

            $fileName = $file->getPath();
        }

        $this->client->request('POST', $this->apiUrl.'/b2_delete_file_version', [
            'headers' => [
                'Authorization' => $this->authToken
            ],
            'json' => [
                'fileName' => $fileName,
                'fileId' => $fileId
            ]
        ]);

        return true;
    }

    /**
     * Authorise the B2 account in order to get an auth token and API/download URLs.
     *
     * @throws \Exception
     */
    protected function authoriseAccount()
    {
        $response = $this->client->request('GET', 'https://api.backblaze.com/b2api/v1/b2_authorize_account', [
            'auth' => [$this->accountId, $this->applicationKey]
        ]);

        $this->authToken = $response['authorizationToken'];
        $this->apiUrl = $response['apiUrl'].'/b2api/v1';
        $this->downloadUrl = $response['downloadUrl'];
    }
}