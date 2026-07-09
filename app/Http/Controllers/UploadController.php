<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Image;
use App\Models\VisualFeature;
use App\Jobs\AnalyzeImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class UploadController extends Controller
{
    // 1. Shows the Dashboard
    public function index(Request $request) 
    {
        // Start with the base query eager-loading our multimedia relations
        $query = Image::with(['visualFeature', 'tags', 'user']);

        // Check if the user is searching for a tag (Text-Based Retrieval - TBR)
        if ($request->filled('search')) {
            $searchTerm = $request->search;
            $query->whereHas('tags', function($q) use ($searchTerm) {
                $q->where('tag_name', 'like', '%' . $searchTerm . '%');
            });
        }

        $images = $query->latest()->get();
        
        return view('dashboard', compact('images'));
    }

    // 2. Handles the image upload
    public function store(Request $request)
    {
        // 1. Validation (Matches project scope file constraints)
        $request->validate([
            'image' => 'required|image|mimes:jpeg,png,jpg,webp|max:5120', // Supports up to 5MB
        ], [
            'image.required' => 'Please upload the file!', 
        ]);

        // 2. Wrap database creation in an atomic transaction (ACID Compliance: Atomicity & Isolation)
        $image = DB::transaction(function () use ($request) {
            
            $file = $request->file('image');
            
            // Save file to storage/app/public/uploads
            $path = $file->store('uploads', 'public');

            dd($path);

            // Explicitly extract the authenticated user's custom PK identifier
            $userId = Auth::user() ? Auth::user()->user_ID : 1; 

            // Save to 'image' table using your database columns
            $newImage = Image::create([
                'user_ID'      => $userId, 
                'file_name'    => $path,                      
                'file_size'    => round($file->getSize() / 1024), // Saved as KB 
                'upload_date'  => now(),
                'image_format' => strtoupper($file->getClientOriginalExtension()), // e.g., 'PNG' or 'JPG'
                'description'  => $request->description,
            ]);

            // Create placeholder or fallback fallback visual features
            // This pulls form values from Puteri's UI selections if the AI job isn't actively modifying them later
            VisualFeature::create([
                'image_ID'         => $newImage->image_ID,
                'clothing_type'    => $request->input('clothing_type', 'Processing...'),
                'background_type'  => $request->input('background_type', 'Processing...'),
                'background_color' => $request->input('background_color', '#FFFFFF'),
                'face_position'    => $request->input('face_position', 'Processing...'),
                'camera_posture'   => $request->input('camera_posture', 'Processing...'),
                'body_composition' => $request->input('body_composition', 'Processing...'),
            ]);

            return $newImage;
        }); 

        // 3. Dispatch the background AI analysis job outside the transaction lock
        AnalyzeImage::dispatch($image)->afterCommit();

        // 4. Redirect back to dashboard layout template frame
        return redirect()->route('dashboard')->with('success', 'Image uploaded! Your GS01 AI model is analyzing it now.');
    }

    // 3. Shows the Result page
    public function show($id) {
        // Load image with its visual classifications
        $image = Image::with('visualFeature')->findOrFail($id);
        return view('result', compact('image'));
    }
}