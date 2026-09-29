<?php

namespace App\Http\Controllers\Api\Engagement;

use App\Http\Controllers\Controller;
use App\Models\WinnerStory;
use App\Services\Engagement\WinnerStories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** The winner stories wall (Phase 11). */
class WinnerStoryController extends Controller
{
    public function __construct(private readonly WinnerStories $stories) {}

    public function mine(Request $request): JsonResponse
    {
        return response()->json(['wins' => $this->stories->postableWins($request->user()->ID)]);
    }

    public function store(Request $request): JsonResponse
    {
        $maxImage = (int) config('engagement.stories.max_image_kb', 5120);
        $maxVideo = (int) config('engagement.stories.max_video_kb', 20480);

        $data = $request->validate([
            'winner_id' => ['required', 'integer'],
            'caption' => ['nullable', 'string', 'max:500'],
            'media' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm', 'max:'.max($maxImage, $maxVideo)],
        ]);

        $file = $request->file('media');

        if (str_starts_with((string) $file->getMimeType(), 'image/') && $file->getSize() > $maxImage * 1024) {
            return response()->json(['message' => 'That photo is too big. Please use one under '.round($maxImage / 1024).' MB.'], 422);
        }

        try {
            $story = $this->stories->post($request->user(), (int) $data['winner_id'], $data['caption'] ?? null, $file);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['id' => $story->id, 'status' => $story->status], 201);
    }

    public function react(Request $request, WinnerStory $story): JsonResponse
    {
        $data = $request->validate(['emoji' => ['required', 'string', 'max:10']]);

        try {
            return response()->json($this->stories->react($request->user(), $story, $data['emoji']));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
