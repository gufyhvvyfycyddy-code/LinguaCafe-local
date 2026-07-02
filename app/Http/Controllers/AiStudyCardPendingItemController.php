<?php

namespace App\Http\Controllers;

use App\Services\AiStudyCardPendingItemService;
use Illuminate\Http\Request;

class AiStudyCardPendingItemController extends Controller
{
    public function __construct(
        private AiStudyCardPendingItemService $pendingItemService,
    ) {
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'chapter_id' => ['required', 'integer', 'min:1'],
            'text_block_index' => ['required', 'integer', 'min:0'],
            'sentence_index' => ['nullable', 'integer', 'min:0'],
            'sentence_id' => ['nullable', 'string', 'max:255'],
            'word' => [
                'required',
                'string',
                'max:255',
                function (string $attribute, mixed $value, \Closure $fail) {
                    if (preg_match('/\s/u', trim((string) $value))) {
                        $fail('第一版只支持单个单词。');
                    }
                },
            ],
            'surface' => ['nullable', 'string', 'max:255'],
            'lemma' => ['nullable', 'string', 'max:255'],
            'sentence_text' => ['nullable', 'string', 'max:2000'],
            'source_payload' => ['nullable', 'array'],
        ]);

        $result = $this->pendingItemService->createOrGetPending($request->user(), $validated);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], $result['status'] ?? 422);
        }

        return response()->json([
            'success' => true,
            'created' => $result['created'],
            'message' => $result['message'],
            'item' => [
                'id' => $result['item']->id,
                'status' => $result['item']->status,
                'word' => $result['item']->word,
                'lemma' => $result['item']->lemma,
                'chapter_id' => $result['item']->chapter_id,
                'text_block_index' => $result['item']->text_block_index,
                'sentence_index' => $result['item']->sentence_index,
            ],
        ]);
    }

    /**
     * V2: 列出当前用户的待 AI 解释项。
     * V3: 扩展支持 status=pending|dismissed|all，默认 pending。
     * 可选 chapter_id 过滤。
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'chapter_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'in:pending,dismissed,all'],
        ]);

        $statusFilter = $validated['status'] ?? 'pending';

        $result = $this->pendingItemService->listPending(
            $request->user(),
            isset($validated['chapter_id']) ? (int) $validated['chapter_id'] : null,
            $statusFilter
        );

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], $result['status'] ?? 422);
        }

        $items = $result['items']->map(function ($item) {
            return [
                'id' => $item->id,
                'status' => $item->status,
                'word' => $item->word,
                'lemma' => $item->lemma,
                'surface' => $item->surface,
                'chapter_id' => $item->chapter_id,
                'text_block_index' => $item->text_block_index,
                'sentence_index' => $item->sentence_index,
                'sentence_text' => $item->sentence_text,
                'created_at' => $item->created_at,
                'updated_at' => $item->updated_at,
            ];
        });

        return response()->json([
            'success' => true,
            'items' => $items,
        ]);
    }

    /**
     * V3: 生成安全预览包（preview-package）。
     *
     * 只打包当前用户、当前语言、pending 状态的 item。
     * 不调用 AI，不生成 WordSense/ReviewCard/ReviewLog，不触发 FSRS。
     */
    public function previewPackage(Request $request)
    {
        $validated = $request->validate([
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['integer', 'min:1'],
        ]);

        $result = $this->pendingItemService->buildPreviewPackage(
            $request->user(),
            $validated['item_ids']
        );

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], $result['status'] ?? 422);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'package' => $result['package'],
        ]);
    }

    /**
     * V4: 生成最终候选包（final-candidates-package）。
     *
     * 用户粘贴 AI 返回的推荐词 JSON，前端解析并勾选后调用本接口。
     * 后端二次去重，确保不与用户已选词重复、AI 推荐词之间不重复。
     * 不调用 AI，不生成 WordSense/ReviewCard/ReviewLog，不触发 FSRS。
     * 不改变 pending item 状态。
     */
    public function finalCandidatesPackage(Request $request)
    {
        $validated = $request->validate([
            'selected_item_ids' => ['nullable', 'array'],
            'selected_item_ids.*' => ['integer', 'min:1'],
            'selected_ai_recommendations' => ['nullable', 'array'],
            'unselected_ai_recommendations' => ['nullable', 'array'],
            'dedupe_summary' => ['nullable', 'array'],
            'source_preview_package' => ['nullable', 'array'],
        ]);

        $result = $this->pendingItemService->buildFinalCandidatesPackage(
            $request->user(),
            $validated
        );

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], $result['status'] ?? 422);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'package' => $result['package'],
        ]);
    }

    /**
     * V2: 取消（dismiss）一个待解释项。
     * 状态从 pending 改为 dismissed，不物理删除。
     */
    public function dismiss(Request $request, int $id)
    {
        $result = $this->pendingItemService->dismiss($request->user(), $id);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], $result['status'] ?? 422);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'item' => [
                'id' => $result['item']->id,
                'status' => $result['item']->status,
                'word' => $result['item']->word,
            ],
        ]);
    }

    /**
     * V2: 恢复一个已 dismissed 的待解释项为 pending。
     */
    public function restore(Request $request, int $id)
    {
        $result = $this->pendingItemService->restore($request->user(), $id);

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], $result['status'] ?? 422);
        }

        return response()->json([
            'success' => true,
            'message' => $result['message'],
            'item' => [
                'id' => $result['item']->id,
                'status' => $result['item']->status,
                'word' => $result['item']->word,
            ],
        ]);
    }
}
