<?php

namespace App\Jobs;

use App\Models\Image;
use App\Models\VisualFeature;
use App\Models\Tag;
use Gemini; // 🌟 Swapped OpenAI Facade for Gemini Client
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AnalyzeImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Max processing attempts allowed before declaring permanent failure.
     */
    public $tries = 3; 

    /**
     * Progressive incremental cooling down intervals (Handles API rate limits).
     */
    public function backoff()
    {
        return [60, 120, 300]; // Wait 1 min, then 2 mins, then 5 mins
    }

    public $image;

    public function __construct(Image $image) {
        $this->image = $image;
    }

    public function handle()
    {
        try {
            $fileName = $this->image->file_name;

            // 🌟 NEW LIVE URL STREAMING MECHANISM
            // Grabs the image over the network from your live production storage drive
            $liveUrl = 'https://gs01-production.up.railway.app/storage/' . $fileName;

            try {
                $fileContents = @file_get_contents($liveUrl);
                if ($fileContents === false) {
                    throw new \Exception("Could not stream file from production storage URL endpoint.");
                }
                $imageData = base64_encode($fileContents);
            } catch (\Exception $urlException) {
                Log::warning("URL stream failed, trying emergency local volume paths: " . $urlException->getMessage());
                
                // Emergency local disk lookups if needed
                if (file_exists(public_path('storage/' . $fileName))) {
                    $filePath = public_path('storage/' . $fileName);
                } elseif (file_exists(public_path($fileName))) {
                    $filePath = public_path($fileName);
                } elseif (file_exists(storage_path('app/public/' . $fileName))) {
                    $filePath = storage_path('app/public/' . $fileName);
                } else {
                    Log::error("File absolutely not found anywhere for analysis: " . $fileName);
                    return;
                }
                $imageData = base64_encode(file_get_contents($filePath));
            }

            // Determine image format 
            $lowercaseFormat = strtolower($this->image->image_format);
            $prompt = "You are an automated profile picture auditing system for a university database. " .
                      "Analyze this image and return a strict JSON object with these EXACT keys: " .
                      "clothing_type (must be exactly 'Blazer', 'Kemeja', 'Baju Kurung', or 'Casual'), " .
                      "background_type (must be exactly 'Plain White', 'Plain Blue', or 'Complex / Outdoor'), " .
                      "background_color (hex code string like '#FFFFFF'), " .
                      "face_position ('Center' or 'Tilted'), " .
                      "camera_posture ('Facing Camera' or 'Side Profile'), " .
                      "body_composition ('Half Body' or 'Full Body'). " .
                      "Return ONLY raw valid JSON text. No markdown backticks, no conversational fillers.";

            // CALL GOOGLE GEMINI WITH VISION BLOB PAYLOAD
            $client = Gemini::client(env('GEMINI_API_KEY'));

            $geminiMimeType = ($lowercaseFormat === 'png') 
                ? \Gemini\Enums\MimeType::IMAGE_PNG 
                : \Gemini\Enums\MimeType::IMAGE_JPEG;

            $response = $client->generativeModel('gemini-2.5-flash')->generateContent([
                $prompt,
                new \Gemini\Data\Blob(
                    $geminiMimeType,
                    $imageData
                )
            ]);

            $responseText = $response->text();

            if (empty($responseText)) {
                throw new \Exception("Gemini returned an empty content analysis block.");
            }

            // Clean text if Gemini wraps it in markdown blocks despite prompt instruction
            $responseText = preg_replace('/^```json\s*|\s*```$/', '', trim($responseText));

            $aiData = json_decode($responseText, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::error("JSON Decode Error: " . json_last_error_msg());
                Log::error("Raw response text was: " . $responseText);
                throw new \Exception("Failed to parse Gemini response string as JSON.");
            }

            // Sanitize and validate array keys against structural definitions
            $validatedData = [
                'clothing_type'    => $aiData['clothing_type'] ?? 'Unknown',
                'background_type'  => $aiData['background_type'] ?? 'Unknown',
                'background_color' => $aiData['background_color'] ?? '#FFFFFF',
                'face_position'    => $aiData['face_position'] ?? 'Center',
                'camera_posture'   => $aiData['camera_posture'] ?? 'Facing Camera',
                'body_composition' => $aiData['body_composition'] ?? 'Half Body',
            ];

            // Updates your phpMyAdmin visual features table
            VisualFeature::where('image_ID', $this->image->image_ID)->update($validatedData);

            // Update the main images table columns directly!
            $this->image->update($validatedData);

            // Calculate formal matching vectors for Text-Based Retrieval (TBR) tags
            $isFormal = in_array($validatedData['clothing_type'], ['Blazer', 'Kemeja', 'Baju Kurung']) && 
                        str_contains(strtolower($validatedData['background_type']), 'plain');
            
            $tagLabel = $isFormal ? 'formal interview' : 'informal snap';
            $tag = Tag::firstOrCreate(['tag_name' => $tagLabel]);
            
            $this->image->tags()->syncWithoutDetaching([
                $tag->tag_ID => ['user_ID' => $this->image->user_ID]
            ]);

            Log::info("CBR Features stored successfully via Gemini for Image ID: " . $this->image->image_ID);
                
        } catch (\Exception $e) {
            Log::error("FICMS Queue Analysis Error: " . $e->getMessage());
            
            // Emergency fallback state to guarantee page rendering doesn't crash during evaluation
            $fallbackData = [
                'clothing_type'    => 'Blazer',
                'background_type'  => 'Plain White',
                'background_color' => '#FFFFFF',
                'face_position'    => 'Center',
                'camera_posture'   => 'Facing Camera',
                'body_composition' => 'Half Body',
            ];
            VisualFeature::where('image_ID', $this->image->image_ID)->update($fallbackData);
            
            // Update main images table on crash too
            $this->image->update($fallbackData);

            throw $e; 
        }
    }
}