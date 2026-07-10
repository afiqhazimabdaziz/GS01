<?php

namespace App\Jobs;

use App\Models\Image;
use App\Models\VisualFeature;
use App\Models\Tag;
use Gemini; 
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AnalyzeImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 4; 

    public function backoff()
    {
        return [60, 120, 300]; 
    }

    public $image;

    public function __construct(Image $image) {
        $this->image = $image;
    }

    public function handle()
    {
        try {
            // 🟢 Gunakan nilai 'Processing...' pada clothing_type sebagai penanda status Running
            VisualFeature::where('image_ID', $this->image->image_ID)->update([
                'clothing_type' => 'Processing...',
                'face_position' => 'AI is detecting metrics (Attempt #' . $this->attempts() . ')...'
            ]);

            $fileName = $this->image->file_name;

            if (file_exists(public_path('storage/' . $fileName))) {
                $filePath = public_path('storage/' . $fileName);
            } elseif (file_exists(public_path($fileName))) {
                $filePath = public_path($fileName);
            } elseif (file_exists(storage_path('app/public/' . $fileName))) {
                $filePath = storage_path('app/public/' . $fileName);
            } else {
                Log::error("File absolutely not found for analysis: " . $fileName);
                VisualFeature::where('image_ID', $this->image->image_ID)->update([
                    'clothing_type' => 'Failed',
                    'face_position' => 'File not found on server.'
                ]);
                return;
            }

            $lowercaseFormat = strtolower($this->image->image_format);
            $mimeType = ($lowercaseFormat === 'png') ? 'image/png' : 'image/jpeg';
            $imageData = base64_encode(file_get_contents($filePath));

            $prompt = "You are an automated profile picture auditing system for a university database. " .
                      "Analyze this image and return a strict JSON object with these EXACT keys: " .
                      "clothing_type (must be exactly 'Blazer', 'Kemeja', 'Baju Kurung', or 'Casual'), " .
                      "background_type (must be exactly 'Plain White', 'Plain Blue', or 'Complex / Outdoor'), " .
                      "background_color (hex code string like '#FFFFFF'), " .
                      "face_position ('Center' or 'Tilted'), " .
                      "camera_posture ('Facing Camera' or 'Side Profile'), " .
                      "body_composition ('Half Body' or 'Full Body'). " .
                      "Return ONLY raw valid JSON text. No markdown backticks, no conversational fillers.";

            $client = Gemini::client(env('GEMINI_API_KEY'));
            $geminiMimeType = ($lowercaseFormat === 'png') ? \Gemini\Enums\MimeType::IMAGE_PNG : \Gemini\Enums\MimeType::IMAGE_JPEG;

            $response = $client->generativeModel('gemini-2.5-flash')->generateContent([
                $prompt,
                new \Gemini\Data\Blob($geminiMimeType, $imageData)
            ]);

            $responseText = $response->text();

            if (empty($responseText)) {
                throw new \Exception("Gemini returned an empty content analysis block.");
            }

            $responseText = preg_replace('/^```json\s*|\s*```$/', '', trim($responseText));
            $aiData = json_decode($responseText, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception("Failed to parse Gemini response string as JSON.");
            }

            // 🟢 JIKA BERHASIL: Status bertukar jadi 'Done' secara automatik apabila text 'Processing...' diganti dengan data AI sebenar
            $validatedData = [
                'clothing_type'    => $aiData['clothing_type'] ?? 'Unknown',
                'background_type'  => $aiData['background_type'] ?? 'Unknown',
                'background_color' => $aiData['background_color'] ?? '#FFFFFF',
                'face_position'    => $aiData['face_position'] ?? 'Center',
                'camera_posture'   => $aiData['camera_posture'] ?? 'Facing Camera',
                'body_composition' => $aiData['body_composition'] ?? 'Half Body'
            ];

            VisualFeature::where('image_ID', $this->image->image_ID)->update($validatedData);

            $isFormal = in_array($validatedData['clothing_type'], ['Blazer', 'Kemeja', 'Baju Kurung']) && 
                        str_contains(strtolower($validatedData['background_type']), 'plain');
            
            $tagLabel = $isFormal ? 'formal interview' : 'informal snap';
            $tag = Tag::firstOrCreate(['tag_name' => $tagLabel]);
            
            $this->image->tags()->syncWithoutDetaching([
                $tag->tag_ID => ['user_ID' => $this->image->user_ID]
            ]);

            Log::info("CBR Features stored successfully via Gemini for Image ID: " . $this->image->image_ID);
                
        } catch (\Exception $e) {
            Log::error("FICMS Detection Failed (Attempt #" . $this->attempts() . "): " . $e->getMessage());
            
            if ($this->attempts() >= $this->tries) {
                // Jika kehabisan had cubaan, set sebagai Failed
                VisualFeature::where('image_ID', $this->image->image_ID)->update([
                    'clothing_type' => 'Failed',
                    'face_position' => 'Permanent Failure: ' . substr($e->getMessage(), 0, 50)
                ]);
            } else {
                // Jika gagal biasa, kekalkan tanda 'Processing...' untuk teruskan putaran pemprosesan semula
                VisualFeature::where('image_ID', $this->image->image_ID)->update([
                    'clothing_type' => 'Processing...',
                    'face_position' => 'Error triggered. Retrying process automatically...'
                ]);
            }
            
            throw $e; 
        }
    }
}