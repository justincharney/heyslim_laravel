<?php

namespace App\Http\Controllers;

use App\Models\UserFile;
use App\Services\SupabaseStorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class UserFileController extends Controller
{
    protected $supabaseStorageService;

    public function __construct(SupabaseStorageService $supabaseStorageService)
    {
        $this->supabaseStorageService = $supabaseStorageService;
    }

    /**
     * Store a newly uploaded file.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            "file" => "required|file|mimes:jpg,jpeg,png,pdf,doc,docx|max:10240",
            "description" => "nullable|string|max:255",
        ]);

        if ($validator->fails()) {
            return response()->json(["errors" => $validator->errors()], 422);
        }

        $user = Auth::user();
        $file = $request->file("file");

        $originalFileName = $file->getClientOriginalName();
        $sanitizedFileName =
            Str::slug(pathinfo($originalFileName, PATHINFO_FILENAME)) .
            "." .
            $file->getClientOriginalExtension();
        $fileContent = file_get_contents($file->getRealPath());
        $mimeType = $file->getMimeType();
        $size = $file->getSize();

        // Define a path in Supabase, e.g., user_id/original_file_name_timestamp.ext
        $timestamp = now()->format("YmdHis");
        $supabasePath = "user_uploads/{$user->id}/{$timestamp}_{$sanitizedFileName}";

        try {
            $uploadedPath = $this->supabaseStorageService->uploadFile(
                $supabasePath,
                $fileContent,
                ["contentType" => $mimeType, "upsert" => false]
            );

            if (!$uploadedPath) {
                Log::error(
                    "Supabase upload failed in UserFileController for user: " .
                        $user->id
                );
                return response()->json(
                    ["message" => "File upload failed."],
                    500
                );
            }

            $userFile = UserFile::create([
                "user_id" => $user->id,
                "file_name" => $originalFileName,
                "supabase_path" => $uploadedPath,
                "mime_type" => $mimeType,
                "size" => $size,
                "description" => $request->input("description"),
                "uploaded_at" => now(),
            ]);

            // Optionally, generate a signed URL for immediate access if needed
            // $signedUrl = $this->supabaseStorageService->createSignedUrl($uploadedPath, 3600); // Expires in 1 hour

            return response()->json(
                [
                    "message" => "File uploaded successfully.",
                    "file" => $userFile,
                    // 'url' => $signedUrl // If you want to return a temporary URL
                ],
                201
            );
        } catch (\Exception $e) {
            Log::error("Error storing user file: " . $e->getMessage(), [
                "user_id" => $user->id,
                "file_name" => $originalFileName,
                "trace" => $e->getTraceAsString(),
            ]);
            return response()->json(
                ["message" => "An error occurred during file upload."],
                500
            );
        }
    }

    /**
     * Display a listing of the user's files.
     */
    public function index()
    {
        $user = Auth::user();
        $files = UserFile::where("user_id", $user->id)
            ->orderBy("uploaded_at", "desc")
            ->get();

        // Generate signed URLs for each file if they are not publicly accessible by default
        $filesWithUrls = $files->map(function ($file) {
            $file->url = $this->supabaseStorageService->createSignedUrl(
                $file->supabase_path,
                3600
            ); // 1 hour expiry
            return $file;
        });

        return response()->json(["files" => $files]);
    }

    /**
     * Display the specified file resource.
     */
    public function show($id)
    {
        $user = Auth::user();
        $userFile = UserFile::where("id", $id)
            ->where("user_id", $user->id)
            ->firstOrFail();

        // Optionally generate a signed URL
        // $userFile->url = $this->supabaseStorageService->createSignedUrl($userFile->supabase_path, 3600);

        return response()->json(["file" => $userFile]);
    }

    /**
     * Remove the specified file resource from storage and database.
     */
    public function destroy($id)
    {
        $user = Auth::user();
        $userFile = UserFile::where("id", $id)
            ->where("user_id", $user->id)
            ->firstOrFail();

        $this->supabaseStorageService->deleteFile($userFile->supabase_path);

        $userFile->delete();

        return response()->json([
            "message" => "File record deleted successfully.",
        ]);
    }
}
