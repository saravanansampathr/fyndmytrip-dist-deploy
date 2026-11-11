<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Backend half of the storefront "AI chat" (see frontend
 * src/lib/chatAssistant.ts's docblock for the full story). The frontend's
 * rule-based intent matcher handles search deep-links and policy questions
 * entirely in the browser, for free, with no server round-trip at all —
 * this controller is only reached for the handful of free-form messages
 * that matcher couldn't make sense of, and only once a real API key is
 * configured.
 *
 * Deliberately NOT the "AI Gateway (Python FastAPI)" microservice sketched
 * in the System Architecture Document — that assumed infrastructure
 * (Redis/Horizon, a separately deployed service) this tenant doesn't have
 * on shared cPanel hosting. This is a direct, synchronous call to Google's
 * Gemini API (genuinely free within its quota, no card required) straight
 * from this Laravel app, because that's what's actually deployable here.
 *
 * Fully optional and fails soft: GEMINI_API_KEY unset means status()
 * reports {enabled: false} and the frontend never calls chat() at all —
 * the widget simply keeps using its free local replies. A quota/network
 * failure from Gemini returns a 503 the frontend already treats the same
 * way (falls back to its own generic reply), so a bad day for Google never
 * breaks the chat widget.
 */
class AssistantController extends Controller
{
    /** Hard cap on how much conversation history we forward — keeps each call small (cost/latency) regardless of how long the on-screen chat has grown. */
    private const MAX_HISTORY_MESSAGES = 6;

    private const SYSTEM_INSTRUCTION = <<<'PROMPT'
You are a short, friendly support assistant embedded on FyndMyTrip, an Indian
online travel platform selling flights, hotels, buses, cabs, holiday
packages, cruises, activities, travel insurance and visa services.

Rules you must follow:
- Keep every reply under 50 words, plain text, no markdown, no links.
- You do NOT have access to live fares, availability, or any specific
  customer's bookings — never invent a price, flight number, hotel name,
  booking status, or refund amount. If asked for one of these, say you
  can't look that up here and suggest using the search bar for fares/
  availability, "My trips" for a booking's status, or raising a support
  ticket for anything account-specific.
- Only discuss FyndMyTrip and travel planning in general. If asked about
  something unrelated, briefly decline and steer back to travel.
- Never claim a certification, accreditation, discount, or policy detail
  you are not told here.
PROMPT;

    public function status(Request $request)
    {
        return response()->json(['enabled' => (bool) config('services.assistant.gemini_key')]);
    }

    public function chat(Request $request)
    {
        $apiKey = config('services.assistant.gemini_key');
        if (! $apiKey) {
            return response()->json(['error' => 'assistant_disabled'], 503);
        }

        $data = $request->validate([
            'message' => ['required', 'string', 'max:400'],
            'history' => ['sometimes', 'array', 'max:' . self::MAX_HISTORY_MESSAGES],
            'history.*.from' => ['required_with:history', 'in:user,assistant'],
            'history.*.text' => ['required_with:history', 'string', 'max:400'],
        ]);

        $contents = [];
        foreach (array_slice($data['history'] ?? [], -self::MAX_HISTORY_MESSAGES) as $turn) {
            $contents[] = [
                'role' => $turn['from'] === 'user' ? 'user' : 'model',
                'parts' => [['text' => $turn['text']]],
            ];
        }
        $contents[] = ['role' => 'user', 'parts' => [['text' => $data['message']]]];

        try {
            $response = Http::timeout(8)
                ->post(
                    'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=' . $apiKey,
                    [
                        'contents' => $contents,
                        'systemInstruction' => ['parts' => [['text' => self::SYSTEM_INSTRUCTION]]],
                        'generationConfig' => ['maxOutputTokens' => 120, 'temperature' => 0.4],
                    ]
                );
        } catch (\Throwable $e) {
            Log::warning('Assistant Gemini call failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'assistant_unavailable'], 503);
        }

        if (! $response->successful()) {
            Log::warning('Assistant Gemini call returned error', ['status' => $response->status(), 'body' => $response->body()]);
            return response()->json(['error' => 'assistant_unavailable'], 503);
        }

        $reply = $response->json('candidates.0.content.parts.0.text');
        if (! $reply) {
            return response()->json(['error' => 'assistant_unavailable'], 503);
        }

        return response()->json(['reply' => trim($reply)]);
    }
}
