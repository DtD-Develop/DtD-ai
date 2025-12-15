<?php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Feedback;
use App\Services\EmbeddingService;
use App\Services\QdrantService;
use GuzzleHttp\Client;

class TrainController extends Controller
{
    protected $qdrant;
    protected $embedSvc;
    protected $http;

    public function __construct(
        EmbeddingService $embedSvc,
        QdrantService $qdrant,
    ) {
        $this->embedSvc = $embedSvc;
        $this->qdrant = $qdrant;
        $this->http = new Client(["timeout" => 10]);
    }

    /**
     * POST /api/train/feedback
     * body: { question, answer, score (0-10), user_id, conversation_id, message_id }
     * If score >= threshold -> auto-add to KB by embedding the Q/A pair
     */
    public function feedback(Request $req)
    {
        $req->validate([
            "question" => "required|string",
            "answer" => "required|string",
            "score" => "required|integer|min:0|max:10",
            "user_id" => "nullable|integer",
            "conversation_id" => "nullable|integer",
            "message_id" => "nullable|integer",
        ]);

        $fb = Feedback::create([
            "question" => $req->input("question"),
            "answer" => $req->input("answer"),
            "score" => $req->input("score"),
            "user_id" => $req->input("user_id"),
            "conversation_id" => $req->input("conversation_id"),
            "message_id" => $req->input("message_id"),
            "meta" => $req->only(["meta"]),
        ]);

        // if score high enough -> upsert to Qdrant (auto-train)
        $threshold = intval(env("DTD_TRAIN_MIN_SCORE", 8));
        if ($fb->score >= $threshold) {
            // create a small doc text combining Q & A
            $docText = "Q: " . $fb->question . "\nA: " . $fb->answer;
            // embedding
            $vec = $this->embedSvc->getEmbedding($docText);

            // create point
            $point = [
                "id" => (string) \Str::uuid(),
                "vector" => $vec,
                "payload" => [
                    "text" => $docText,
                    "source" => "train_auto",
                    "title" => "AutoTrain - user_feedback",
                    "kb_file_id" => -1,
                ],
            ];

            $this->qdrant->upsertPoints([$point]);
        }

        return response()->json(["status" => "ok", "saved" => $fb->id]);
    }
}
