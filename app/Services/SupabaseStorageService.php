<?php namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SupabaseStorageService
{
    protected string $baseUrl;
    protected string $serviceKey;
    protected string $bucketName;

    public function __construct()
    {
        $this->baseUrl = rtrim(config("services.supabase.url"), "/"); // Ensure no trailing slash
        $this->serviceKey = config("services.supabase.service_key");
        $this->bucketName = config("services.supabase.bucket");

        if (!$this->baseUrl || !$this->serviceKey || !$this->bucketName) {
            Log::critical(
                "Supabase credentials or bucket not fully configured."
            );
        }
    }

    /**
     * Uploads a file to Supabase Storage.
     *
     * @param string $filePathInBucket The desired path and filename in the bucket (e.g., "prescriptions/file.pdf").
     * @param string $fileContent The raw file content.
     * @param array $options Additional options:
     *                       - 'contentType': string, e.g., 'application/pdf' (defaults to 'application/octet-stream')
     *                       - 'cacheControl': string, e.g., 'max-age=3600' (defaults to 'max-age=3600')
     *                       - 'upsert': bool (defaults to false)
     * @return string|null The path of the uploaded file in the bucket ($filePathInBucket), or null on failure.
     */
    public function uploadFile(
        string $filePathInBucket,
        string $fileContent,
        array $options = []
    ): ?string {
        if (!$this->serviceKey) {
            Log::error(
                "Supabase service key not configured. Cannot upload file."
            );
            return null;
        }

        $uploadUrl = "{$this->baseUrl}/object/{$this->bucketName}/{$filePathInBucket}";

        $contentType = $options["contentType"] ?? "application/pdf"; // Defaulted to PDF
        $cacheControl = $options["cacheControl"] ?? "max-age=3600";
        $upsert = $options["upsert"] ?? false; // Supabase uses 'false'/'true' as strings for x-upsert header

        try {
            $response = Http::withHeaders([
                "Authorization" => "Bearer {$this->serviceKey}",
                "cache-control" => $cacheControl,
                "x-upsert" => $upsert ? "true" : "false",
            ])
                ->withBody($fileContent, $contentType)
                ->post($uploadUrl);

            if ($response->successful()) {
                // Successful upload typically returns 200 OK with JSON: e.g. {"Key": "bucket/path/file.pdf"}
                // We can verify $response->json('Key') if needed.
                // Log::info("File uploaded successfully to Supabase Storage.", [
                //     "bucket" => $this->bucketName,
                //     "path" => $filePathInBucket,
                //     "response_status" => $response->status(),
                // ]);
                return $filePathInBucket; // Return the path used for upload
            } else {
                Log::error("Failed to upload file to Supabase Storage.", [
                    "url" => $uploadUrl,
                    "status" => $response->status(),
                    "response_body" => $response->body(),
                ]);
                return null;
            }
        } catch (\Exception $e) {
            Log::error("Exception during Supabase file upload.", [
                "url" => $uploadUrl,
                "error" => $e->getMessage(),
                "trace" => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Deletes one or more files from Supabase Storage.
     *
     * @param array|string $filePaths A single file path (string) or array of file paths to delete.
     * @return bool True if deletion was successful, false otherwise.
     */
    public function deleteFiles(array|string $filePaths): bool
    {
        if (!$this->serviceKey) {
            Log::error(
                "Supabase service key not configured. Cannot delete files."
            );
            return false;
        }

        // Convert single path to array for consistent handling
        $paths = is_array($filePaths) ? $filePaths : [$filePaths];

        $deleteUrl = "{$this->baseUrl}/object/{$this->bucketName}";

        try {
            $response = Http::withHeaders([
                "Authorization" => "Bearer {$this->serviceKey}",
                "Content-Type" => "application/json",
            ])->delete($deleteUrl, [
                "prefixes" => $paths,
            ]);

            if ($response->successful()) {
                // Log::info("Files deleted successfully from Supabase Storage.", [
                //     "bucket" => $this->bucketName,
                //     "paths" => $paths,
                //     "response_status" => $response->status(),
                // ]);
                return true;
            } else {
                Log::error("Failed to delete files from Supabase Storage.", [
                    "url" => $deleteUrl,
                    "paths" => $paths,
                    "status" => $response->status(),
                    "response_body" => $response->body(),
                ]);
                return false;
            }
        } catch (\Exception $e) {
            Log::error("Exception during Supabase file deletion.", [
                "url" => $deleteUrl,
                "paths" => $paths,
                "error" => $e->getMessage(),
                "trace" => $e->getTraceAsString(),
            ]);
            return false;
        }
    }

    /**
     * Deletes a single file from Supabase Storage.
     *
     * @param string $filePathInBucket The path of the file to delete in the bucket.
     * @return bool True if deletion was successful, false otherwise.
     */
    public function deleteFile(string $filePathInBucket): bool
    {
        return $this->deleteFiles($filePathInBucket);
    }

    /**
     * Gets the public URL for a file in Supabase Storage.
     * This method constructs the URL and does not check for file existence or permissions.
     * Assumes the bucket has appropriate RLS policies for public access.
     *
     * @param string $filePathInBucket The path of the file in the bucket.
     * @return string
     */
    public function getPublicUrl(string $filePathInBucket): string
    {
        return "{$this->baseUrl}/object/public/{$this->bucketName}/{$filePathInBucket}";
    }

    /**
     * Creates a signed URL for a file in Supabase Storage.
     *
     * @param string $filePathInBucket The path of the file in the bucket.
     * @param int $expiresIn Expiration time in seconds (e.g., 3600 for 1 hour).
     * @return string|null The full signed URL or null on failure.
     */
    public function createSignedUrl(
        string $filePathInBucket,
        int $expiresIn
    ): ?string {
        if (!$this->serviceKey) {
            Log::error(
                "Supabase service key not configured. Cannot create signed URL."
            );
            return null;
        }

        $signUrl = "{$this->baseUrl}/object/sign/{$this->bucketName}/{$filePathInBucket}";

        try {
            $response = Http::withHeaders([
                "Authorization" => "Bearer {$this->serviceKey}",
                "Content-Type" => "application/json",
            ])->post($signUrl, ["expiresIn" => $expiresIn]);

            if ($response->successful()) {
                $signedPath = $response->json("signedURL");
                if ($signedPath) {
                    $fullSignedUrl = $this->baseUrl . $signedPath;
                    // Log::info("Successfully created Supabase signed URL.", [
                    //     "path" => $filePathInBucket,
                    //     "expires_in" => $expiresIn,
                    //     "signed_url_preview" =>
                    //         substr($fullSignedUrl, 0, 100) . "...",
                    // ]);
                    return $fullSignedUrl;
                } else {
                    Log::error(
                        "Supabase signed URL path was empty in response.",
                        [
                            "url" => $signUrl,
                            "response_body" => $response->json(),
                        ]
                    );
                    return null;
                }
            } else {
                Log::error("Failed to create Supabase signed URL.", [
                    "url" => $signUrl,
                    "status" => $response->status(),
                    "response_body" => $response->body(),
                ]);
                return null;
            }
        } catch (\Exception $e) {
            Log::error("Exception during Supabase signed URL creation.", [
                "url" => $signUrl,
                "error" => $e->getMessage(),
                "trace" => $e->getTraceAsString(),
            ]);
            return null;
        }
    }
}
