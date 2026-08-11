<?php

namespace App\Http\Controllers\Api\V1\Community;

use App\Http\Controllers\Controller;
use App\Http\Resources\CommunityPostCommentResource;
use App\Http\Resources\CommunityPostResource;
use App\Http\Resources\KulCoinTransactionResource;
use App\Models\CommunityPost;
use App\Models\CommunityPostComment;
use App\Models\CommunityPostGift;
use App\Models\CommunityPostLike;
use App\Models\CommunityPostPollVote;
use App\Models\CommunityPostShare;
use App\Models\KulCoinGift;
use App\Models\Subscription;
use App\Models\UserFollow;
use App\Services\CommunityMediaService;
use App\Services\KulCoinService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class CommunityPostController extends Controller
{
    public function __construct(
        private readonly KulCoinService $kulCoinService,
        private readonly CommunityMediaService $communityMediaService,
    ) {}

    public function index(Request $request)
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $viewerId = (int) $request->user()->id;
        $perPage = (int) ($validated['per_page'] ?? 20);
        $page = max(1, (int) $request->query('page', 1));
        $subscribedCreatorIds = $this->subscribedCreatorIds($viewerId);

        $posts = CommunityPost::query()
            ->with([
                'user.roles:id,name',
                'media',
                'pollVotes',
                'comments' => function ($query): void {
                    $query->whereNull('parent_id')
                        ->latest()
                        ->with([
                            'user.roles:id,name',
                            'replies' => function ($replyQuery): void {
                                $replyQuery->oldest()->with('user.roles:id,name')->withCount('replies');
                            },
                        ])
                        ->withCount('replies');
                },
            ])
            ->withCount(['likes', 'comments', 'shares', 'gifts'])
            ->where(function ($query) use ($viewerId, $subscribedCreatorIds): void {
                $query->where('audience', 'public')
                    ->orWhere('user_id', $viewerId)
                    ->orWhere(function ($query) use ($subscribedCreatorIds): void {
                        $query->where('audience', 'subscribers')
                            ->whereIn('user_id', $subscribedCreatorIds);
                    });
            })
            ->latest('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $postIds = $posts->getCollection()->pluck('id')->all();
        $authorIds = $posts->getCollection()->pluck('user_id')->filter()->map(static fn ($id) => (int) $id)->unique()->values()->all();

        $likedPostIds = CommunityPostLike::query()
            ->where('user_id', $viewerId)
            ->whereIn('community_post_id', $postIds)
            ->pluck('community_post_id')
            ->map(static fn ($id) => (int) $id)
            ->flip();

        $sharedPostIds = CommunityPostShare::query()
            ->where('user_id', $viewerId)
            ->whereIn('community_post_id', $postIds)
            ->pluck('community_post_id')
            ->map(static fn ($id) => (int) $id)
            ->flip();

        $followedAuthorIds = UserFollow::query()
            ->where('follower_id', $viewerId)
            ->whereIn('followed_id', $authorIds)
            ->pluck('followed_id')
            ->map(static fn ($id) => (int) $id)
            ->flip();

        $posts->getCollection()->each(function (CommunityPost $post) use ($likedPostIds, $sharedPostIds, $followedAuthorIds): void {
            $post->setAttribute('is_liked', $likedPostIds->has((int) $post->id));
            $post->setAttribute('is_shared', $sharedPostIds->has((int) $post->id));
            $post->setAttribute('is_following', $followedAuthorIds->has((int) $post->user_id));
            $post->setRelation('user', $post->user);
        });

        return response()->json([
            'data' => CommunityPostResource::collection($posts)->resolve($request),
            'meta' => [
                'current_page' => $posts->currentPage(),
                'last_page' => $posts->lastPage(),
                'per_page' => $posts->perPage(),
                'total' => $posts->total(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $this->validatePostPayload($request);

        $uploadedMedia = [];
        $mediaFiles = [];

        if ($request->hasFile('media')) {
            $mediaFiles = array_values(array_filter(
                is_array($request->file('media')) ? $request->file('media') : [$request->file('media')],
                static fn ($file) => $file instanceof UploadedFile
            ));
        }

        try {
            foreach ($mediaFiles as $index => $file) {
                $uploadedMedia[] = $this->communityMediaService->storeMediaForPost(
                    $file,
                    $request->user(),
                    $index
                );
            }

            $post = DB::transaction(function () use ($request, $validated, $uploadedMedia): CommunityPost {
                $post = CommunityPost::query()->create([
                    'user_id' => $request->user()->id,
                    'type' => $validated['type'],
                    'content' => $validated['content'] ?? null,
                    'audience' => $validated['audience'],
                    'status' => 'published',
                    'views_count' => 0,
                    'media_ids' => $validated['media_ids'] ?? [],
                    'poll' => $validated['poll'] ?? null,
                ]);

                foreach ($uploadedMedia as $index => $media) {
                    $post->media()->create(array_merge($media, [
                        'sort_order' => $media['sort_order'] ?? $index,
                    ]));
                }

                return $post->load(['user.roles:id,name', 'media']);
            });
        } catch (Throwable $throwable) {
            foreach ($uploadedMedia as $media) {
                $this->communityMediaService->deleteStoredMedia($media);
            }

            throw $throwable;
        }

        $post->load(['user.roles:id,name', 'media']);
        $post->loadCount(['likes', 'comments', 'shares', 'gifts']);
        $post->setAttribute('is_liked', false);
        $post->setAttribute('is_shared', false);
        $post->setAttribute('is_following', false);

        return response()->json([
            'message' => 'Community post created successfully.',
            'data' => new CommunityPostResource($post),
        ], 201);
    }

    public function show(Request $request, string $communityPost)
    {
        $post = $this->findCommunityPost($communityPost);
        $this->authorizeView($request, $post);

        $post->load([
            'user.roles:id,name',
            'media',
            'pollVotes',
            'comments' => function ($query): void {
                $query->whereNull('parent_id')
                    ->latest()
                    ->with([
                        'user.roles:id,name',
                        'replies' => function ($replyQuery): void {
                            $replyQuery->oldest()->with('user.roles:id,name')->withCount('replies');
                        },
                    ])
                    ->withCount('replies');
            },
        ]);
        $post->loadCount(['likes', 'comments', 'shares', 'gifts']);
        $post->setAttribute('is_liked', CommunityPostLike::query()
            ->where('user_id', $request->user()->id)
            ->where('community_post_id', $post->id)
            ->exists());
        $post->setAttribute('is_shared', CommunityPostShare::query()
            ->where('user_id', $request->user()->id)
            ->where('community_post_id', $post->id)
            ->exists());
        $post->setAttribute('is_following', UserFollow::query()
            ->where('follower_id', $request->user()->id)
            ->where('followed_id', $post->user_id)
            ->exists());

        return response()->json([
            'data' => new CommunityPostResource($post),
        ]);
    }

    public function comments(Request $request, string $communityPost)
    {
        $post = $this->findCommunityPost($communityPost);
        $this->authorizeView($request, $post);

        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $page = max(1, (int) $request->query('page', 1));
        $perPage = (int) ($validated['per_page'] ?? 20);

        $comments = CommunityPostComment::query()
            ->where('community_post_id', $post->id)
            ->whereNull('parent_id')
            ->with([
                'user.roles:id,name',
                'replies' => function ($query): void {
                    $query->oldest()->with('user.roles:id,name')->withCount('replies');
                },
            ])
            ->withCount('replies')
            ->latest()
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => CommunityPostCommentResource::collection($comments->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $comments->currentPage(),
                'last_page' => $comments->lastPage(),
                'per_page' => $comments->perPage(),
                'total' => $comments->total(),
            ],
        ]);
    }

    public function comment(Request $request, string $communityPost)
    {
        $post = $this->findCommunityPost($communityPost);
        $this->authorizeView($request, $post);

        $validated = $request->validate([
            'body' => ['required', 'string', 'min:1', 'max:5000'],
            'parent_id' => ['nullable', 'integer', 'exists:community_post_comments,id'],
        ]);

        if (($validated['parent_id'] ?? null) !== null) {
            $parent = CommunityPostComment::query()
                ->where('community_post_id', $post->id)
                ->whereKey($validated['parent_id'])
                ->first();

            abort_unless($parent, 404, 'Parent comment not found.');
        }

        $comment = CommunityPostComment::query()->create([
            'community_post_id' => $post->id,
            'user_id' => $request->user()->id,
            'parent_id' => $validated['parent_id'] ?? null,
            'body' => $validated['body'],
        ]);

        $comment->load([
            'user.roles:id,name',
            'replies' => function ($query): void {
                $query->oldest()->with('user.roles:id,name')->withCount('replies');
            },
        ]);

        $isReply = array_key_exists('parent_id', $validated) && $validated['parent_id'] !== null;

        return response()->json([
            'message' => $isReply ? 'Reply added successfully.' : 'Comment added successfully.',
            'data' => new CommunityPostCommentResource($comment),
        ], 201);
    }

    public function like(Request $request, string $communityPost)
    {
        $post = $this->findCommunityPost($communityPost);
        $this->authorizeView($request, $post);

        $created = false;

        \DB::transaction(function () use ($request, $post, &$created): void {
            $like = CommunityPostLike::query()->firstOrCreate([
                'community_post_id' => $post->id,
                'user_id' => $request->user()->id,
            ]);

            $created = $like->wasRecentlyCreated;
        });

        return response()->json([
            'message' => $created ? 'Community post liked successfully.' : 'Community post was already liked.',
            'data' => $this->postState($request, $post, isLiked: true, isShared: $this->postSharedByUser($request, $post)),
        ]);
    }

    public function unlike(Request $request, string $communityPost)
    {
        $post = $this->findCommunityPost($communityPost);
        $this->authorizeView($request, $post);

        CommunityPostLike::query()
            ->where('community_post_id', $post->id)
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json([
            'message' => 'Community post unliked successfully.',
            'data' => $this->postState($request, $post, isLiked: false, isShared: $this->postSharedByUser($request, $post)),
        ]);
    }

    public function share(Request $request, string $communityPost)
    {
        $post = $this->findCommunityPost($communityPost);
        $this->authorizeView($request, $post);

        $created = false;

        \DB::transaction(function () use ($request, $post, &$created): void {
            $share = CommunityPostShare::query()->firstOrCreate([
                'community_post_id' => $post->id,
                'user_id' => $request->user()->id,
            ]);

            $created = $share->wasRecentlyCreated;
        });

        return response()->json([
            'message' => $created ? 'Community post shared successfully.' : 'Community post was already shared.',
            'data' => $this->postState($request, $post, isLiked: $this->postLikedByUser($request, $post), isShared: true),
        ]);
    }

    public function gift(Request $request, string $communityPost)
    {
        $post = $this->findCommunityPost($communityPost);
        $this->authorizeView($request, $post);

        $validated = $request->validate([
            'gift_id' => ['required', 'integer', 'exists:kulcoin_gifts,id'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'message' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
            'device_info' => ['nullable', 'array'],
        ]);

        try {
            $gift = KulCoinGift::query()->findOrFail($validated['gift_id']);
            $transaction = $this->kulCoinService->sendGift(
                sender: $request->user(),
                creator: $post->user,
                gift: $gift,
                quantity: (int) ($validated['quantity'] ?? 1),
                data: array_merge($validated, [
                    'ip_address' => $request->ip(),
                    'device_info' => $validated['device_info'] ?? null,
                ]),
                actor: $request->user()
            );

            $communityGift = CommunityPostGift::query()->create([
                'community_post_id' => $post->id,
                'sender_id' => $request->user()->id,
                'recipient_user_id' => $post->user_id,
                'gift_id' => $gift->id,
                'kulcoin_transaction_id' => $transaction->id,
                'quantity' => (int) ($validated['quantity'] ?? 1),
                'coin_amount' => (int) $transaction->coin_amount,
                'message' => $validated['message'] ?? null,
            ]);

            $post->loadCount(['likes', 'comments', 'shares', 'gifts']);

            return response()->json([
                'message' => 'Community post gifted successfully.',
                'data' => [
                    'post' => $this->postState($request, $post, isLiked: $this->postLikedByUser($request, $post), isShared: $this->postSharedByUser($request, $post)),
                    'gift' => [
                        'id' => $communityGift->id,
                        'gift_id' => $communityGift->gift_id,
                        'quantity' => $communityGift->quantity,
                        'coin_amount' => $communityGift->coin_amount,
                        'message' => $communityGift->message,
                        'transaction' => new KulCoinTransactionResource($transaction),
                    ],
                ],
            ], 201);
        } catch (Throwable $throwable) {
            report($throwable);

            if ($throwable instanceof ValidationException) {
                return response()->json([
                    'message' => 'Unable to gift community post.',
                    'errors' => $throwable->errors(),
                ], 422);
            }

            return response()->json([
                'message' => 'Unable to gift community post.',
                'error' => $throwable->getMessage(),
            ], 500);
        }
    }

    public function vote(Request $request, string $communityPost)
    {
        $post = $this->findCommunityPost($communityPost);
        $this->authorizeView($request, $post);

        if ($post->type !== 'poll') {
            throw ValidationException::withMessages([
                'community_post' => 'The selected community post is not a poll.',
            ]);
        }

        $validated = $request->validate([
            'option_id' => ['required', 'integer', 'min:1'],
        ]);

        $poll = is_array($post->poll) ? $post->poll : [];
        $options = array_values(array_filter(
            $poll['options'] ?? [],
            static fn ($option) => is_string($option) && trim($option) !== ''
        ));
        $optionIndex = ((int) $validated['option_id']) - 1;

        if (! array_key_exists($optionIndex, $options)) {
            throw ValidationException::withMessages([
                'option_id' => 'The selected poll option is invalid.',
            ]);
        }

        if (! empty($poll['closes_at']) && Carbon::parse($poll['closes_at'])->isPast()) {
            throw ValidationException::withMessages([
                'community_post' => 'This poll is closed.',
            ]);
        }

        $created = false;

        DB::transaction(function () use ($request, $post, $optionIndex, &$created): void {
            $vote = CommunityPostPollVote::query()->firstOrCreate(
                [
                    'community_post_id' => $post->id,
                    'user_id' => $request->user()->id,
                ],
                ['poll_option_index' => $optionIndex]
            );

            $created = $vote->wasRecentlyCreated;
        });

        $post->unsetRelation('pollVotes');

        return response()->json([
            'message' => $created ? 'Poll vote submitted successfully.' : 'You have already voted in this poll.',
            'data' => $this->postState(
                $request,
                $post,
                isLiked: $this->postLikedByUser($request, $post),
                isShared: $this->postSharedByUser($request, $post),
            ),
        ], $created ? 201 : 200);
    }

    private function validatePostPayload(Request $request): array
    {
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(['text', 'image', 'video', 'poll'])],
            'content' => ['nullable', 'string', 'max:5000'],
            'audience' => ['required', 'string', Rule::in(['public', 'subscribers'])],
            'media' => ['sometimes', 'array', 'min:1'],
            'media.*' => ['required', 'file', 'max:102400'],
            'media_ids' => ['sometimes', 'array', 'min:1'],
            'media_ids.*' => ['required', 'string', 'max:255', 'distinct'],
            'poll' => ['sometimes', 'array'],
            'poll.options' => ['required_with:poll', 'array', 'min:2'],
            'poll.options.*' => ['required_with:poll.options', 'string', 'min:1', 'max:255', 'distinct'],
            'poll.closes_at' => ['nullable', 'date', 'after:now'],
        ]);

        if ($validated['type'] === 'text') {
            if (! array_key_exists('content', $validated) || trim((string) ($validated['content'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    'content' => 'The content field is required for text posts.',
                ]);
            }

            unset($validated['media_ids'], $validated['poll']);
        }

        if ($validated['type'] === 'image' || $validated['type'] === 'video') {
            $mediaIds = array_values(array_filter(
                is_array($validated['media_ids'] ?? null) ? ($validated['media_ids'] ?? []) : [],
                static fn ($mediaId) => is_string($mediaId) && $mediaId !== ''
            ));

            if ((! $request->hasFile('media')) && $mediaIds === []) {
                throw ValidationException::withMessages([
                    'media' => 'The media field is required for image and video posts.',
                ]);
            }

            $validated['media_ids'] = $mediaIds;
            unset($validated['poll']);
        }

        if ($validated['type'] === 'poll') {
            if (! isset($validated['poll']['options']) || ! is_array($validated['poll']['options']) || count($validated['poll']['options']) < 2) {
                throw ValidationException::withMessages([
                    'poll.options' => 'Poll posts require at least two options.',
                ]);
            }

            $validated['poll'] = [
                'options' => array_values(array_filter($validated['poll']['options'], static fn ($option) => is_string($option) && trim($option) !== '')),
                'closes_at' => $validated['poll']['closes_at'] ?? null,
            ];

            unset($validated['media_ids']);
        }

        return $validated;
    }

    private function postState(Request $request, CommunityPost $post, bool $isLiked, bool $isShared): array
    {
        $post->loadMissing(['user.roles:id,name', 'media', 'pollVotes']);
        $post->loadCount(['likes', 'comments', 'shares', 'gifts']);
        $post->setAttribute('is_liked', $isLiked);
        $post->setAttribute('is_shared', $isShared);

        return (new CommunityPostResource($post))->resolve($request);
    }

    private function postLikedByUser(Request $request, CommunityPost $post): bool
    {
        return CommunityPostLike::query()
            ->where('user_id', $request->user()->id)
            ->where('community_post_id', $post->id)
            ->exists();
    }

    private function postSharedByUser(Request $request, CommunityPost $post): bool
    {
        return CommunityPostShare::query()
            ->where('user_id', $request->user()->id)
            ->where('community_post_id', $post->id)
            ->exists();
    }

    private function subscribedCreatorIds(int $viewerId): array
    {
        return Subscription::query()
            ->where('subscriber_id', $viewerId)
            ->where('status', 'active')
            ->pluck('creator_id')
            ->map(static fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    private function authorizeView(Request $request, CommunityPost $post): void
    {
        if ($post->audience !== 'subscribers') {
            return;
        }

        if ((string) $post->user_id === (string) $request->user()->id) {
            return;
        }

        $isSubscribed = Subscription::query()
            ->where('subscriber_id', $request->user()->id)
            ->where('creator_id', $post->user_id)
            ->where('status', 'active')
            ->exists();

        abort_unless($isSubscribed, 403, 'This community post is only available to subscribers.');
    }

    private function findCommunityPost(string|int $communityPost): CommunityPost
    {
        return CommunityPost::query()->with(['user.roles:id,name', 'media', 'pollVotes'])->findOrFail($communityPost);
    }
}
