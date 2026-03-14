<?php

namespace App\Http\Controllers;

use App\Events\ProjectRealtimeEvent;
use App\Models\Project;
use App\Models\ProjectCodeComment;
use App\Models\User;
use App\Services\ProjectAccessService;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectCodeCommentController extends Controller
{
    public function index(Request $request, int $projectId, ProjectAccessService $access): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate([
            'path' => ['required', 'string', 'max:2048'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);

        $project = Project::query()->findOrFail($projectId);
        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $path = trim((string) $data['path']);
        $limit = (int) ($data['limit'] ?? 300);

        $comments = ProjectCodeComment::query()
            ->with(['author:user_id,name,email,avatar_type,avatar_preset,avatar_path'])
            ->where('project_id', $project->project_id)
            ->where('path', $path)
            ->orderBy('line_number')
            ->orderBy('comment_id')
            ->limit($limit)
            ->get();

        return response()->json([
            'status' => 'ok',
            'comments' => $comments->map(fn (ProjectCodeComment $comment): array => $this->serializeComment($comment))->all(),
        ]);
    }

    public function store(Request $request, int $projectId, ProjectAccessService $access): JsonResponse
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = Project::query()->findOrFail($projectId);
        if (! $access->canWriteProject($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $data = $request->validate([
            'path' => ['required', 'string', 'max:2048'],
            'line_number' => ['required', 'integer', 'min:1', 'max:1000000'],
            'body' => ['required', 'string', 'max:3000'],
        ]);

        $body = trim((string) $data['body']);
        if ($body === '') {
            return response()->json(['message' => 'Comment cannot be empty.'], 422);
        }

        $comment = ProjectCodeComment::query()->create([
            'project_id' => $project->project_id,
            'author_user_id' => $user->user_id,
            'path' => trim((string) $data['path']),
            'line_number' => (int) $data['line_number'],
            'body' => $body,
            'created_at' => now(),
        ]);

        $comment->loadMissing('author:user_id,name,email,avatar_type,avatar_preset,avatar_path');
        $serialized = $this->serializeComment($comment);
        $this->broadcastSafely(new ProjectRealtimeEvent(
            $project->project_id,
            (int) $user->user_id,
            'realtime.code_comment.updated',
            [
                'action' => 'created',
                'comment' => $serialized,
            ]
        ));

        return response()->json([
            'status' => 'ok',
            'comment' => $serialized,
        ], 201);
    }

    public function destroy(
        Request $request,
        int $projectId,
        int $commentId,
        ProjectAccessService $access
    ): JsonResponse|Response {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $project = Project::query()->findOrFail($projectId);
        if (! $access->userHasAccess($project, $user)) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $comment = ProjectCodeComment::query()
            ->where('project_id', $project->project_id)
            ->findOrFail($commentId);

        $isAuthor = (int) ($comment->author_user_id ?? 0) === (int) $user->user_id;
        $canDeleteAsProjectWriter = $access->canWriteProject($project, $user);

        if (! $isAuthor && ! $canDeleteAsProjectWriter) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        $deletedComment = [
            'comment_id' => (int) $comment->comment_id,
            'path' => (string) $comment->path,
            'line_number' => max(1, (int) $comment->line_number),
        ];

        $comment->delete();
        $this->broadcastSafely(new ProjectRealtimeEvent(
            $project->project_id,
            (int) $user->user_id,
            'realtime.code_comment.updated',
            [
                'action' => 'deleted',
                'comment_id' => (int) $deletedComment['comment_id'],
                'path' => (string) $deletedComment['path'],
                'line_number' => (int) $deletedComment['line_number'],
                'comment' => $deletedComment,
            ]
        ));

        return response()->noContent();
    }

    private function broadcastSafely(object $event): void
    {
        try {
            event($event);
        } catch (\Throwable) {
            // Broadcast transport should not break API response paths.
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeComment(ProjectCodeComment $comment): array
    {
        $author = $comment->author;
        $authorName = trim((string) ($author?->name ?? ''));
        $authorEmail = trim((string) ($author?->email ?? ''));

        return [
            'comment_id' => (int) $comment->comment_id,
            'project_id' => (int) $comment->project_id,
            'path' => (string) $comment->path,
            'line_number' => max(1, (int) $comment->line_number),
            'body' => (string) $comment->body,
            'created_at' => $comment->created_at?->toISOString() ?? '',
            'author' => [
                'user_id' => (int) ($author?->user_id ?? 0),
                'name' => $authorName !== '' ? $authorName : ($authorEmail !== '' ? $authorEmail : 'User'),
                'email' => $authorEmail,
                'avatar_preset' => trim((string) ($author?->avatar_preset ?? '')) !== ''
                    ? (string) $author?->avatar_preset
                    : 'robot',
                'avatar_url' => $author ? $this->resolveAvatarUrl($author) : null,
            ],
        ];
    }

    private function resolveAvatarUrl(User $user): ?string
    {
        if ((string) $user->avatar_type !== 'upload') {
            return null;
        }

        $path = trim((string) ($user->avatar_path ?? ''));
        if ($path === '') {
            return null;
        }

        return '/storage/'.ltrim($path, '/');
    }
}
