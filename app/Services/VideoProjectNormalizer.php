<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VideoProjectNormalizer
{
    /**
     * Normalize the v3 editor payload into a project object plus a render-friendly timeline.
     *
     * @param  array<string, mixed>  $payload
     * @return array{
     *     schema_version: int,
     *     project: array<string, mixed>,
     *     timeline: array<string, mixed>
     * }
     */
    public function normalize(array $payload, int $userId): array
    {
        if ($this->extractSchemaVersion($payload['schemaVersion'] ?? $payload['schema_version'] ?? $payload['version'] ?? null) !== 3) {
            throw ValidationException::withMessages([
                'schemaVersion' => 'Only schemaVersion 3.x editor projects are supported.',
            ]);
        }

        return $this->normalizeV3Project($payload, $userId);
    }

    /**
     * Normalize the v3 frontend project schema into a backend project document
     * plus a render-friendly timeline.
     *
     * @param  array<string, mixed>  $payload
     * @return array{schema_version:int,project:array<string, mixed>,timeline:array<string, mixed>}
     */
    private function normalizeV3Project(array $payload, int $userId): array
    {
        $schemaVersionLabel = (string) ($payload['schemaVersion'] ?? $payload['schema_version'] ?? $payload['version'] ?? '3.0.0');
        $schemaVersion = $this->extractSchemaVersion($schemaVersionLabel);

        if ($schemaVersion !== 3) {
            throw ValidationException::withMessages([
                'schemaVersion' => 'The editor project schema version must be 3.x.',
            ]);
        }

        $metadata = $this->normalizeProjectMetadata(is_array($payload['metadata'] ?? null) ? $payload['metadata'] : []);
        $canvas = $this->normalizeV3Canvas(is_array($payload['canvas'] ?? null) ? $payload['canvas'] : []);
        $output = $this->normalizeV3Output(is_array($payload['output'] ?? null) ? $payload['output'] : []);
        $assets = $this->normalizeAssetReferences(
            is_array($payload['assets'] ?? null) ? $payload['assets'] : [],
            $userId,
            isset($payload['video_id']) ? (int) $payload['video_id'] : null,
        );
        $assetIndex = $this->indexAssetsById($assets);
        $projectDuration = $this->resolveV3ProjectDuration($payload, $metadata, $canvas, $payload['scenes'] ?? []);

        $scenes = [];
        $tracks = [];
        $renderLayers = [];
        $sceneInput = is_array($payload['scenes'] ?? null) ? array_values($payload['scenes']) : [];

        usort($sceneInput, static function (mixed $left, mixed $right): int {
            $leftOrder = is_array($left) && is_numeric($left['order'] ?? null) ? (int) $left['order'] : PHP_INT_MAX;
            $rightOrder = is_array($right) && is_numeric($right['order'] ?? null) ? (int) $right['order'] : PHP_INT_MAX;

            return ($leftOrder <=> $rightOrder)
                ?: strcmp((string) (is_array($left) ? ($left['id'] ?? '') : ''), (string) (is_array($right) ? ($right['id'] ?? '') : ''));
        });

        foreach (array_values($sceneInput) as $sceneIndex => $scene) {
            if (! is_array($scene)) {
                throw ValidationException::withMessages([
                    "scenes.{$sceneIndex}" => 'Each scene must be an array.',
                ]);
            }

            $normalizedScene = $this->normalizeV3Scene($scene, $sceneIndex, $assetIndex, $projectDuration);
            $scenes[] = $normalizedScene['scene'];

            foreach ($normalizedScene['tracks'] as $normalizedTrack) {
                $tracks[] = $normalizedTrack['track'];

                if (($normalizedScene['scene']['enabled'] ?? true) && $normalizedTrack['render_layer'] !== null) {
                    $renderLayers[] = $normalizedTrack['render_layer'];
                }
            }
        }

        $renderLayers = $this->normalizeRenderPlanTransforms($renderLayers, $canvas, $output);

        $globalAudioTracks = $this->normalizeV3GlobalAudioTracks(is_array($payload['globalAudioTracks'] ?? null) ? $payload['globalAudioTracks'] : []);
        $globalEffects = $this->normalizeV3GlobalEffects(is_array($payload['globalEffects'] ?? null) ? $payload['globalEffects'] : []);
        $guides = $this->normalizeV3Guides(is_array($payload['guides'] ?? null) ? $payload['guides'] : []);
        $derivedDuration = $projectDuration;

        if ($derivedDuration === null) {
            $derivedDuration = $this->deriveDurationFromScenes($scenes, $tracks);
            if ($derivedDuration === null) {
                $derivedDuration = $metadata['duration'] ?? null;
            }
        }

        if ($derivedDuration !== null) {
            $metadata['duration'] = $derivedDuration;
        }

        $project = [
            'version' => 3,
            'schemaVersion' => $schemaVersionLabel,
            'metadata' => $metadata,
            'project_id' => $metadata['id'] ?? null,
            'duration' => $derivedDuration,
            'canvas' => $canvas,
            'output' => $output,
            'assets' => $assets,
            'scenes' => $scenes,
            'tracks' => $tracks,
            'globalAudioTracks' => $globalAudioTracks,
            'globalEffects' => $globalEffects,
            'guides' => $guides,
            'global_filters' => is_array($payload['global_filters'] ?? null) ? array_values($payload['global_filters']) : [],
            'raw_payload' => $payload,
        ];

        return [
            'schema_version' => 3,
            'project' => $project,
            'timeline' => [
                'video_id' => isset($payload['video_id']) ? (int) $payload['video_id'] : null,
                'canvas' => $canvas,
                'layers' => $renderLayers,
                'filters' => $this->normalizeFilters($payload['filters'] ?? $project['global_filters']),
                'audio' => $globalAudioTracks !== [] ? $globalAudioTracks : $this->normalizeAudio($payload['audio'] ?? []),
                'trim' => $this->normalizeTrim($payload['trim'] ?? []),
                'output' => $output,
                'raw_payload' => $payload,
            ],
        ];
    }

    private function extractSchemaVersion(mixed $schemaVersion): int
    {
        if (is_int($schemaVersion)) {
            return $schemaVersion;
        }

        if (is_float($schemaVersion)) {
            return (int) floor($schemaVersion);
        }

        if (is_string($schemaVersion)) {
            if (preg_match('/^(\d+)/', trim($schemaVersion), $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $canvas
     */
    private function resolveV3ProjectDuration(array $payload, array $metadata, array $canvas, mixed $scenes): ?float
    {
        foreach ([
            $metadata['duration'] ?? null,
            $payload['duration'] ?? null,
            data_get($canvas, 'duration'),
        ] as $candidate) {
            if (is_numeric($candidate) && (float) $candidate > 0) {
                return max(0.0, (float) $candidate);
            }
        }

        $sceneDurations = [];
        if (is_array($scenes)) {
            foreach ($scenes as $scene) {
                if (! is_array($scene)) {
                    continue;
                }

                $timeline = is_array($scene['timeline'] ?? null) ? $scene['timeline'] : [];
                $start = is_numeric($timeline['start'] ?? null) ? max(0.0, (float) $timeline['start']) : 0.0;
                $duration = is_numeric($timeline['duration'] ?? null) ? max(0.0, (float) $timeline['duration']) : null;

                if ($duration !== null) {
                    $sceneDurations[] = $start + $duration;
                }
            }
        }

        return $sceneDurations === [] ? null : max($sceneDurations);
    }

    /**
     * @param  array<string, mixed>  $scene
     * @param  array<string, array<string, mixed>>  $assetIndex
     * @return array{scene:array<string, mixed>,tracks:array<int, array{track:array<string, mixed>,render_layer:array<string, mixed>|null}>}
     */
    private function normalizeV3Scene(array $scene, int $sceneIndex, array $assetIndex, ?float $projectDuration): array
    {
        $timeline = is_array($scene['timeline'] ?? null) ? $scene['timeline'] : [];
        $sceneStart = isset($timeline['start']) && is_numeric($timeline['start']) ? max(0.0, (float) $timeline['start']) : 0.0;
        $sceneDuration = isset($timeline['duration']) && is_numeric($timeline['duration']) ? max(0.0, (float) $timeline['duration']) : null;
        $sceneTracksInput = is_array($scene['tracks'] ?? null) ? array_values($scene['tracks']) : [];
        $tracks = [];
        $sceneRenderEnd = $sceneDuration !== null ? $sceneStart + $sceneDuration : null;

        usort($sceneTracksInput, static function (mixed $left, mixed $right): int {
            $leftLayer = is_array($left) && is_numeric($left['layer'] ?? null) ? (int) $left['layer'] : 0;
            $rightLayer = is_array($right) && is_numeric($right['layer'] ?? null) ? (int) $right['layer'] : 0;

            return ($leftLayer <=> $rightLayer)
                ?: strcmp((string) (is_array($left) ? ($left['id'] ?? '') : ''), (string) (is_array($right) ? ($right['id'] ?? '') : ''));
        });

        foreach (array_values($sceneTracksInput) as $trackIndex => $track) {
            if (! is_array($track)) {
                throw ValidationException::withMessages([
                    "scenes.{$sceneIndex}.tracks.{$trackIndex}" => 'Each track must be an array.',
                ]);
            }

            $tracks[] = $this->normalizeV3Track($track, $sceneIndex, $trackIndex, $sceneStart, $sceneDuration, $sceneRenderEnd, $assetIndex, $projectDuration);
        }

        if ($sceneDuration === null) {
            $sceneDuration = $this->deriveSceneDurationFromTracks($tracks, $sceneStart, $projectDuration);
        }

        return [
            'scene' => array_filter([
                'id' => isset($scene['id']) ? (string) $scene['id'] : 'scene-'.($sceneIndex + 1),
                'name' => isset($scene['name']) ? (string) $scene['name'] : 'Scene '.($sceneIndex + 1),
                'order' => isset($scene['order']) ? (int) $scene['order'] : $sceneIndex,
                'timeline' => array_filter([
                    'start' => $sceneStart,
                    'duration' => $sceneDuration,
                ], static fn ($value) => $value !== null && $value !== ''),
                'background' => $this->normalizeV3Background(is_array($scene['background'] ?? null) ? $scene['background'] : []),
                'tracks' => array_map(static fn (array $trackBundle): array => $trackBundle['track'], $tracks),
                'transitionIn' => is_array($scene['transitionIn'] ?? null) ? $scene['transitionIn'] : null,
                'transitionOut' => is_array($scene['transitionOut'] ?? null) ? $scene['transitionOut'] : null,
                'enabled' => array_key_exists('enabled', $scene) ? filter_var($scene['enabled'], FILTER_VALIDATE_BOOLEAN) : true,
            ], static fn ($value) => $value !== null && $value !== ''),
            'tracks' => $tracks,
        ];
    }

    /**
     * @param  array<string, mixed>  $track
     * @param  array<string, array<string, mixed>>  $assetIndex
     * @return array{track:array<string, mixed>,render_layer:array<string, mixed>|null}
     */
    private function normalizeV3Track(array $track, int $sceneIndex, int $trackIndex, float $sceneStart, ?float $sceneDuration, ?float $sceneRenderEnd, array $assetIndex, ?float $projectDuration): array
    {
        $type = (string) ($track['type'] ?? '');
        $legacyTrack = $this->buildV3LegacyTrack($track, $sceneStart, $sceneDuration, $sceneRenderEnd, $assetIndex, $projectDuration);

        if (! in_array($type, ['video', 'image', 'text', 'caption', 'sticker', 'drawing', 'shape', 'audio', 'adjustment', 'composition'], true)) {
            throw ValidationException::withMessages([
                "scenes.{$sceneIndex}.tracks.{$trackIndex}.type" => "Track type [{$type}] is not supported by the current renderer.",
            ]);
        }

        if (in_array($type, ['text', 'caption'], true)) {
            $content = is_array($track['content'] ?? null) ? $track['content'] : [];
            $textStyle = is_array($track['textStyle'] ?? null) ? $track['textStyle'] : [];
            $textBox = is_array($track['textBox'] ?? null) ? $track['textBox'] : [];
            $background = is_array($textBox['background'] ?? null) ? $textBox['background'] : [];
            $stroke = is_array($textStyle['stroke'] ?? null) ? $textStyle['stroke'] : [];
            $shadow = is_array($textStyle['shadow'] ?? null) ? $textStyle['shadow'] : [];
            $transform = $legacyTrack['transform'] ?? [];
            $text = trim((string) ($content['text'] ?? ''));
            $renderSource = is_array($track['renderSource'] ?? null) ? $track['renderSource'] : [];
            $rasterAsset = strtolower((string) ($renderSource['type'] ?? '')) === 'raster'
                ? $this->resolveV3AssetSource([
                    'assetId' => $renderSource['assetId'] ?? $renderSource['asset_id'] ?? null,
                ], $assetIndex)
                : [];

            if ($text === '') {
                throw ValidationException::withMessages([
                    "scenes.{$sceneIndex}.tracks.{$trackIndex}.content.text" => 'Text tracks require content.text.',
                ]);
            }

            if ($rasterAsset !== []) {
                return [
                    'track' => array_merge($legacyTrack, [
                        'content' => $content,
                        'text_style' => $textStyle,
                        'text_box' => $textBox,
                        'render_source' => $renderSource,
                    ]),
                    'render_layer' => $this->buildRasterRenderLayer($rasterAsset, $legacyTrack, $track, $sceneIndex, $trackIndex),
                ];
            }

            $this->assertFallbackTextTrackSupported($track, $sceneIndex, $trackIndex);
            $trackOpacity = (float) ($transform['opacity'] ?? 1.0);
            $textOpacity = (float) ($textStyle['opacity'] ?? 1.0);
            $fillOpacity = (float) data_get($textStyle, 'fill.opacity', 1.0);

            $renderLayer = array_filter([
                'id' => $legacyTrack['id'] ?? null,
                'type' => 'text',
                'text' => mb_substr($text, 0, 500),
                'font' => (string) ($textStyle['fontFamily'] ?? $legacyTrack['font'] ?? 'Arial'),
                'font_key' => isset($textStyle['fontAssetId']) ? (string) $textStyle['fontAssetId'] : null,
                'font_family' => isset($textStyle['fontFamily']) ? (string) $textStyle['fontFamily'] : null,
                'font_weight' => isset($textStyle['fontWeight']) ? (int) $textStyle['fontWeight'] : null,
                'font_style' => isset($textStyle['fontStyle']) ? (string) $textStyle['fontStyle'] : null,
                'size' => isset($textStyle['fontSize']) ? (int) $textStyle['fontSize'] : ($legacyTrack['size'] ?? 42),
                'color' => isset($textStyle['fill']['color']) ? (string) $textStyle['fill']['color'] : ($legacyTrack['color'] ?? '#FFFFFF'),
                'opacity' => max(0.0, min(1.0, $trackOpacity * $textOpacity * $fillOpacity)),
                'alignment' => isset($textStyle['alignment']) ? (string) $textStyle['alignment'] : null,
                'line_height' => isset($textStyle['lineHeight']) ? (float) $textStyle['lineHeight'] : null,
                'letter_spacing' => isset($textStyle['letterSpacing']) ? (float) $textStyle['letterSpacing'] : null,
                'box' => (bool) ($textBox['background']['enabled'] ?? $background['enabled'] ?? true),
                'box_color' => (string) ($background['color'] ?? $textBox['background']['color'] ?? $legacyTrack['box_color'] ?? 'black@0.35'),
                'box_border_width' => max(
                    (int) ($textBox['padding']['left'] ?? 0),
                    (int) ($textBox['padding']['right'] ?? 0),
                    (int) ($textBox['padding']['top'] ?? 0),
                    (int) ($textBox['padding']['bottom'] ?? 0)
                ),
                'x' => $transform['x'] ?? null,
                'y' => $transform['y'] ?? null,
                'width' => $transform['width'] ?? null,
                'height' => $transform['height'] ?? null,
                'rotation' => $transform['rotation'] ?? null,
                'scale_x' => $transform['scale_x'] ?? null,
                'scale_y' => $transform['scale_y'] ?? null,
                'anchor_x' => $transform['anchor_x'] ?? null,
                'anchor_y' => $transform['anchor_y'] ?? null,
                'margin_x' => $transform['margin_x'] ?? null,
                'margin_y' => $transform['margin_y'] ?? null,
                'padding_x' => $textBox['padding']['left'] ?? null,
                'padding_y' => $textBox['padding']['top'] ?? null,
                'start' => $legacyTrack['start'],
                'end' => $legacyTrack['end'],
                'enabled' => $legacyTrack['enabled'] ?? true,
                'visible' => $legacyTrack['visible'] ?? true,
                'z_index' => $legacyTrack['z_index'] ?? $trackIndex,
                'stroke' => $stroke,
                'shadow' => $shadow,
                'animation' => is_array(data_get($track, 'animations.in')) ? data_get($track, 'animations.in') : (is_array($track['animation'] ?? null) ? $track['animation'] : []),
                'keyframes' => $legacyTrack['keyframes'] ?? [],
                'metadata' => [
                    'source' => 'v3',
                    'scene_index' => $sceneIndex,
                    'track_index' => $trackIndex,
                    'content' => $content,
                    'textStyle' => $textStyle,
                    'textBox' => $textBox,
                    'raw' => $track,
                ],
            ], static fn ($value) => $value !== null && $value !== '');

            $legacyTrack['content'] = $content;
            $legacyTrack['background'] = $textBox['background'] ?? [];
            $legacyTrack['stroke'] = $stroke;
            $legacyTrack['shadow'] = $shadow;
            $legacyTrack['transform'] = $legacyTrack['transform'] ?? [];
            $legacyTrack['animation'] = is_array(data_get($track, 'animations.in')) ? data_get($track, 'animations.in') : (is_array($track['animation'] ?? null) ? $track['animation'] : []);
            $legacyTrack['metadata'] = [
                'source' => 'v3',
                'scene_index' => $sceneIndex,
                'track_index' => $trackIndex,
                'raw' => $track,
            ];

            return [
                'track' => $legacyTrack,
                'render_layer' => $renderLayer,
            ];
        }

        if (in_array($type, ['video', 'image', 'sticker', 'drawing'], true)) {
            $source = is_array($track['source'] ?? null) ? $track['source'] : [];
            $renderSource = is_array($track['renderSource'] ?? null) ? $track['renderSource'] : [];
            $asset = strtolower((string) ($renderSource['type'] ?? '')) === 'raster'
                ? $this->resolveV3AssetSource(['assetId' => $renderSource['assetId'] ?? $renderSource['asset_id'] ?? null], $assetIndex)
                : $this->resolveV3AssetSource($source, $assetIndex);
            $transform = $legacyTrack['transform'] ?? [];

            if (($asset['public_id'] ?? '') === '' && ($asset['asset_key'] ?? '') === '') {
                throw ValidationException::withMessages([
                    "scenes.{$sceneIndex}.tracks.{$trackIndex}.source" => 'Media tracks require an assetId that resolves to managed storage or Cloudinary.',
                ]);
            }

            $renderLayer = array_filter([
                'id' => $legacyTrack['id'] ?? null,
                'type' => $type,
                'public_id' => $asset['public_id'] ?? null,
                'asset_public_id' => $asset['asset_public_id'] ?? null,
                'asset_url' => $asset['asset_url'] ?? null,
                'asset_disk' => $asset['asset_disk'] ?? null,
                'asset_key' => $asset['asset_key'] ?? null,
                'x' => $transform['x'] ?? null,
                'y' => $transform['y'] ?? null,
                'width' => $transform['width'] ?? null,
                'height' => $transform['height'] ?? null,
                'rotation' => $transform['rotation'] ?? null,
                'opacity' => $transform['opacity'] ?? null,
                'scale_x' => $transform['scale_x'] ?? null,
                'scale_y' => $transform['scale_y'] ?? null,
                'anchor_x' => $transform['anchor_x'] ?? null,
                'anchor_y' => $transform['anchor_y'] ?? null,
                'fit' => $transform['fit'] ?? ($track['fit'] ?? null),
                'flip_horizontal' => $transform['flip_horizontal'] ?? false,
                'flip_vertical' => $transform['flip_vertical'] ?? false,
                'trim_start' => data_get($legacyTrack, 'timeline.trimStart'),
                'trim_end' => data_get($legacyTrack, 'timeline.trimEnd'),
                'playback_rate' => data_get($legacyTrack, 'timeline.playbackRate', 1.0),
                'reverse' => data_get($legacyTrack, 'timeline.reverse', false),
                'loop' => data_get($legacyTrack, 'timeline.loop', false),
                'freeze_at_end' => data_get($legacyTrack, 'timeline.freezeAtEnd', false),
                'margin_x' => $transform['margin_x'] ?? null,
                'margin_y' => $transform['margin_y'] ?? null,
                'start' => $legacyTrack['start'],
                'end' => $legacyTrack['end'],
                'enabled' => $legacyTrack['enabled'] ?? true,
                'visible' => $legacyTrack['visible'] ?? true,
                'z_index' => $legacyTrack['z_index'] ?? $trackIndex,
                'blend_mode' => $legacyTrack['blend_mode'] ?? [],
                'border' => $legacyTrack['border'] ?? [],
                'shadow' => $legacyTrack['shadow'] ?? [],
                'glow' => $legacyTrack['glow'] ?? [],
                'raw' => $track,
            ], static fn ($value) => $value !== null && $value !== '');

            $legacyTrack['source'] = $source;
            $legacyTrack['render_source'] = $renderSource;
            $legacyTrack['asset'] = $asset;
            $legacyTrack['metadata'] = [
                'source' => 'v3',
                'scene_index' => $sceneIndex,
                'track_index' => $trackIndex,
                'raw' => $track,
            ];

            return [
                'track' => $legacyTrack,
                'render_layer' => $renderLayer,
            ];
        }

        if ($type === 'audio') {
            $legacyTrack['source'] = is_array($track['source'] ?? null) ? $track['source'] : [];
            $legacyTrack['audio'] = is_array($track['audio'] ?? null) ? $track['audio'] : [];
            $legacyTrack['metadata'] = [
                'source' => 'v3',
                'scene_index' => $sceneIndex,
                'track_index' => $trackIndex,
                'raw' => $track,
            ];

            return [
                'track' => $legacyTrack,
                'render_layer' => null,
            ];
        }

        $legacyTrack['metadata'] = [
            'source' => 'v3',
            'scene_index' => $sceneIndex,
            'track_index' => $trackIndex,
            'raw' => $track,
        ];

        return [
            'track' => $legacyTrack,
            'render_layer' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $track
     * @param  array<string, array<string, mixed>>  $assetIndex
     * @return array<string, mixed>
     */
    private function buildV3LegacyTrack(array $track, float $sceneStart, ?float $sceneDuration, ?float $sceneRenderEnd, array $assetIndex, ?float $projectDuration): array
    {
        $type = (string) ($track['type'] ?? '');
        $transform = is_array($track['transform'] ?? null) ? $track['transform'] : [];
        $position = is_array($transform['position'] ?? null) ? $transform['position'] : [];
        $size = is_array($transform['size'] ?? null) ? $transform['size'] : [];
        $scale = is_array($transform['scale'] ?? null) ? $transform['scale'] : [];
        $timeline = is_array($track['timeline'] ?? null) ? $track['timeline'] : [];
        $source = is_array($track['source'] ?? null) ? $track['source'] : [];
        $asset = $this->resolveV3AssetSource($source, $assetIndex);

        $start = $sceneStart + $this->toFloat(data_get($timeline, 'start', data_get($track, 'start', 0)));
        $duration = data_get($timeline, 'duration', data_get($track, 'duration'));
        $end = null;

        if (is_numeric($duration)) {
            $end = $start + max(0.0, (float) $duration);
        } elseif (is_numeric(data_get($timeline, 'trimEnd'))) {
            $end = $sceneStart + max(0.0, (float) data_get($timeline, 'trimEnd'));
        } elseif ($sceneRenderEnd !== null) {
            $end = $sceneRenderEnd;
        } elseif ($projectDuration !== null) {
            $end = $projectDuration;
        }

        $normalized = [
            'id' => isset($track['id']) && $track['id'] !== '' ? (string) $track['id'] : 'track-'.Str::uuid()->toString(),
            'type' => (string) ($track['type'] ?? ''),
            'name' => isset($track['name']) ? (string) $track['name'] : null,
            'category' => isset($track['category']) ? (string) $track['category'] : null,
            'start' => max(0.0, $start),
            'end' => $end !== null ? max(0.0, $end) : null,
            'duration' => $end !== null ? max(0.0, $end - $start) : null,
            'z_index' => isset($track['layer']) ? (int) $track['layer'] : (isset($track['z_index']) ? (int) $track['z_index'] : 0),
            'enabled' => filter_var($track['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'locked' => filter_var($track['locked'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'muted' => filter_var($track['muted'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'visible' => array_key_exists('visible', $track) ? filter_var($track['visible'], FILTER_VALIDATE_BOOLEAN) : true,
            'selected' => filter_var($track['selected'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'group_id' => isset($track['groupId']) ? (string) $track['groupId'] : (isset($track['group_id']) ? (string) $track['group_id'] : null),
            'parent_composition_id' => isset($track['parentCompositionId']) ? (string) $track['parentCompositionId'] : null,
            'timeline' => array_filter([
                'start' => isset($timeline['start']) ? max(0.0, (float) $timeline['start']) : max(0.0, $start - $sceneStart),
                'duration' => is_numeric($timeline['duration'] ?? null) ? max(0.0, (float) $timeline['duration']) : null,
                'trimStart' => is_numeric($timeline['trimStart'] ?? null) ? max(0.0, (float) $timeline['trimStart']) : 0.0,
                'trimEnd' => is_numeric($timeline['trimEnd'] ?? null) ? max(0.0, (float) $timeline['trimEnd']) : null,
                'playbackRate' => is_numeric($timeline['playbackRate'] ?? null) ? (float) $timeline['playbackRate'] : 1.0,
                'reverse' => filter_var($timeline['reverse'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'loop' => filter_var($timeline['loop'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'freezeAtEnd' => filter_var($timeline['freezeAtEnd'] ?? false, FILTER_VALIDATE_BOOLEAN),
            ], static fn ($value) => $value !== null && $value !== ''),
            'transform' => array_filter([
                'coordinate_system' => isset($transform['coordinate_system']) ? (string) $transform['coordinate_system'] : 'canvas',
                'anchor_x' => $this->resolveV3AnchorCoordinate($transform, 'x'),
                'anchor_y' => $this->resolveV3AnchorCoordinate($transform, 'y'),
                'x' => isset($position['x']) ? (int) round((float) $position['x']) : (isset($track['x']) ? (int) round((float) $track['x']) : 0),
                'y' => isset($position['y']) ? (int) round((float) $position['y']) : (isset($track['y']) ? (int) round((float) $track['y']) : 0),
                'width' => isset($size['width']) ? max(1, (int) $size['width']) : (isset($track['width']) ? max(1, (int) $track['width']) : null),
                'height' => isset($size['height']) ? max(1, (int) $size['height']) : (isset($track['height']) ? max(1, (int) $track['height']) : null),
                'scale_x' => isset($scale['x']) ? (float) $scale['x'] : (isset($track['scale_x']) ? (float) $track['scale_x'] : 1.0),
                'scale_y' => isset($scale['y']) ? (float) $scale['y'] : (isset($track['scale_y']) ? (float) $track['scale_y'] : 1.0),
                'rotation' => isset($transform['rotation']) ? (float) $transform['rotation'] : (isset($track['rotation']) ? (float) $track['rotation'] : 0.0),
                'opacity' => isset($transform['opacity']) ? max(0.0, min(1.0, (float) $transform['opacity'])) : (isset($track['opacity']) ? max(0.0, min(1.0, (float) $track['opacity'])) : 1.0),
                'flip_horizontal' => filter_var($transform['flipHorizontal'] ?? $track['flip_horizontal'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'flip_vertical' => filter_var($transform['flipVertical'] ?? $track['flip_vertical'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'skew_x' => isset($transform['skew']['x']) ? (float) $transform['skew']['x'] : (isset($track['skew_x']) ? (float) $track['skew_x'] : 0.0),
                'skew_y' => isset($transform['skew']['y']) ? (float) $transform['skew']['y'] : (isset($track['skew_y']) ? (float) $track['skew_y'] : 0.0),
                'margin_x' => isset($track['margin_x']) ? (int) $track['margin_x'] : 0,
                'margin_y' => isset($track['margin_y']) ? (int) $track['margin_y'] : 0,
                'crop' => is_array($transform['crop'] ?? null) ? $transform['crop'] : [],
                'fit' => isset($transform['fit']) ? (string) $transform['fit'] : (isset($track['fit']) ? (string) $track['fit'] : null),
            ], static fn ($value) => $value !== null && $value !== ''),
            'crop' => array_filter([
                'enabled' => array_key_exists('crop', $track) ? filter_var(data_get($track, 'crop.enabled', false), FILTER_VALIDATE_BOOLEAN) : false,
                'x' => data_get($track, 'crop.x'),
                'y' => data_get($track, 'crop.y'),
                'width' => data_get($track, 'crop.width'),
                'height' => data_get($track, 'crop.height'),
                'unit' => data_get($track, 'crop.unit', 'normalized'),
            ], static fn ($value) => $value !== null && $value !== ''),
            'opacity' => isset($transform['opacity']) ? max(0.0, min(1.0, (float) $transform['opacity'])) : 1.0,
            'blend_mode' => isset($track['blendMode']) ? (string) $track['blendMode'] : (isset($track['blend_mode']) ? (string) $track['blend_mode'] : 'normal'),
            'mask' => is_array($track['mask'] ?? null) ? $track['mask'] : null,
            'border' => is_array($track['border'] ?? null) ? $track['border'] : null,
            'shadow' => is_array($track['shadow'] ?? null) ? $track['shadow'] : null,
            'glow' => is_array($track['glow'] ?? null) ? $track['glow'] : null,
            'animations' => is_array($track['animations'] ?? null) ? $track['animations'] : ['in' => null, 'loop' => null, 'out' => null],
            'keyframes' => is_array($track['keyframes'] ?? null) ? array_values($track['keyframes']) : [],
            'motion_path' => is_array($track['motionPath'] ?? null) ? $track['motionPath'] : null,
            'effects' => is_array($track['effects'] ?? null) ? array_values($track['effects']) : [],
            'source' => $source,
            'asset' => $asset,
            'content' => is_array($track['content'] ?? null) ? $track['content'] : [],
            'text_style' => is_array($track['textStyle'] ?? null) ? $track['textStyle'] : [],
            'text_box' => is_array($track['textBox'] ?? null) ? $track['textBox'] : [],
            'audio' => is_array($track['audio'] ?? null) ? $track['audio'] : [],
            'metadata' => is_array($track['metadata'] ?? null) ? $track['metadata'] : [],
            'raw' => $track,
        ];

        if ($type === 'caption' && isset($normalized['content']['text'])) {
            $normalized['content']['text'] = (string) $normalized['content']['text'];
        }

        return array_filter($normalized, static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $transform
     */
    private function resolveV3AnchorCoordinate(array $transform, string $axis): float
    {
        if (isset($transform['anchor'][$axis]) && is_numeric($transform['anchor'][$axis])) {
            return max(0.0, min(1.0, (float) $transform['anchor'][$axis]));
        }

        if (isset($transform['anchor_'.$axis]) && is_numeric($transform['anchor_'.$axis])) {
            return max(0.0, min(1.0, (float) $transform['anchor_'.$axis]));
        }

        $preset = strtolower(str_replace(['-', ' '], '_', (string) data_get($transform, 'anchor.preset', 'center')));
        $coordinates = [
            'top_left' => [0.0, 0.0],
            'top' => [0.5, 0.0],
            'top_center' => [0.5, 0.0],
            'top_right' => [1.0, 0.0],
            'left' => [0.0, 0.5],
            'center_left' => [0.0, 0.5],
            'center' => [0.5, 0.5],
            'right' => [1.0, 0.5],
            'center_right' => [1.0, 0.5],
            'bottom_left' => [0.0, 1.0],
            'bottom' => [0.5, 1.0],
            'bottom_center' => [0.5, 1.0],
            'bottom_right' => [1.0, 1.0],
        ];

        return ($coordinates[$preset] ?? $coordinates['center'])[$axis === 'x' ? 0 : 1];
    }

    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, array<string, mixed>>  $assetIndex
     * @return array<string, mixed>
     */
    private function resolveV3AssetSource(array $source, array $assetIndex): array
    {
        $assetId = isset($source['assetId']) ? (string) $source['assetId'] : (isset($source['asset_id']) ? (string) $source['asset_id'] : '');
        $asset = $assetId !== '' && isset($assetIndex[$assetId]) ? $assetIndex[$assetId] : [];

        $assetUrl = '';
        $storageProvider = (string) ($asset['storage_provider'] ?? '');
        $storageKey = (string) ($asset['storage_key'] ?? '');
        $publicId = (string) ($asset['public_id'] ?? '');

        if ($storageProvider !== '' && $storageKey !== '') {
            if ($storageProvider === 'cloudinary') {
                $publicId = $publicId !== '' ? $publicId : $storageKey;
            }
        }

        return array_filter([
            'asset_id' => $assetId !== '' ? $assetId : null,
            'asset_url' => $assetUrl !== '' ? $assetUrl : null,
            'asset_disk' => $storageProvider !== '' ? $storageProvider : null,
            'asset_key' => $storageKey !== '' ? $storageKey : null,
            'public_id' => $publicId !== '' ? $publicId : null,
            'asset_public_id' => $publicId !== '' ? $publicId : null,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Reject rich text effects that the drawtext fallback cannot reproduce.
     * The frontend can make these tracks render-safe by supplying renderSource.
     *
     * @param  array<string, mixed>  $track
     */
    private function assertFallbackTextTrackSupported(array $track, int $sceneIndex, int $trackIndex): void
    {
        $unsupported = abs((float) data_get($track, 'transform.rotation', 0)) > 0.000001
            || filter_var(data_get($track, 'textStyle.glow.enabled', false), FILTER_VALIDATE_BOOLEAN)
            || (string) data_get($track, 'textStyle.fill.type', 'solid') !== 'solid'
            || (filter_var(data_get($track, 'textStyle.shadow.enabled', false), FILTER_VALIDATE_BOOLEAN)
                && (float) data_get($track, 'textStyle.shadow.blur', 0) > 0)
            || collect((array) data_get($track, 'textBox.radius', []))->contains(fn (mixed $radius): bool => (float) $radius > 0)
            || (filter_var(data_get($track, 'textBox.background.enabled', false), FILTER_VALIDATE_BOOLEAN)
                && (string) data_get($track, 'textBox.background.type', 'solid') !== 'solid');

        if ($unsupported) {
            throw ValidationException::withMessages([
                "scenes.{$sceneIndex}.tracks.{$trackIndex}.renderSource" => 'This rich text track requires a frontend-generated raster renderSource.',
            ]);
        }
    }

    /**
     * Prefer a frontend-rasterized representation while preserving the editable
     * text/drawing model in the project document.
     *
     * @param  array<string, mixed>  $asset
     * @param  array<string, mixed>  $track
     * @param  array<string, mixed>  $rawTrack
     * @return array<string, mixed>
     */
    private function buildRasterRenderLayer(array $asset, array $track, array $rawTrack, int $sceneIndex, int $trackIndex): array
    {
        $transform = is_array($track['transform'] ?? null) ? $track['transform'] : [];

        if (($asset['public_id'] ?? '') === '' && ($asset['asset_key'] ?? '') === '') {
            throw ValidationException::withMessages([
                "scenes.{$sceneIndex}.tracks.{$trackIndex}.renderSource" => 'Raster render sources must reference a managed storage or Cloudinary asset.',
            ]);
        }

        return array_filter([
            'id' => $track['id'] ?? null,
            'type' => 'image',
            'source_track_type' => $track['type'] ?? null,
            'public_id' => $asset['public_id'] ?? null,
            'asset_public_id' => $asset['asset_public_id'] ?? null,
            'asset_disk' => $asset['asset_disk'] ?? null,
            'asset_key' => $asset['asset_key'] ?? null,
            'x' => $transform['x'] ?? null,
            'y' => $transform['y'] ?? null,
            'width' => $transform['width'] ?? null,
            'height' => $transform['height'] ?? null,
            'scale_x' => $transform['scale_x'] ?? null,
            'scale_y' => $transform['scale_y'] ?? null,
            'anchor_x' => $transform['anchor_x'] ?? null,
            'anchor_y' => $transform['anchor_y'] ?? null,
            'rotation' => $transform['rotation'] ?? null,
            'opacity' => $transform['opacity'] ?? null,
            'flip_horizontal' => $transform['flip_horizontal'] ?? false,
            'flip_vertical' => $transform['flip_vertical'] ?? false,
            'trim_start' => data_get($track, 'timeline.trimStart'),
            'trim_end' => data_get($track, 'timeline.trimEnd'),
            'playback_rate' => data_get($track, 'timeline.playbackRate', 1.0),
            'reverse' => data_get($track, 'timeline.reverse', false),
            'loop' => data_get($track, 'timeline.loop', false),
            'freeze_at_end' => data_get($track, 'timeline.freezeAtEnd', false),
            'start' => $track['start'] ?? null,
            'end' => $track['end'] ?? null,
            'enabled' => $track['enabled'] ?? true,
            'visible' => $track['visible'] ?? true,
            'z_index' => $track['z_index'] ?? $trackIndex,
            'raw' => $rawTrack,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Convert editor anchor coordinates into FFmpeg top-left coordinates once,
     * after applying canvas-to-output scaling.
     *
     * @param  array<int, array<string, mixed>>  $layers
     * @param  array<string, mixed>  $canvas
     * @param  array<string, mixed>  $output
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRenderPlanTransforms(array $layers, array $canvas, array $output): array
    {
        $canvasWidth = max(1.0, (float) ($canvas['width'] ?? 1080));
        $canvasHeight = max(1.0, (float) ($canvas['height'] ?? 1920));
        $outputWidth = max(1.0, (float) ($output['width'] ?? $canvasWidth));
        $outputHeight = max(1.0, (float) ($output['height'] ?? $canvasHeight));
        $canvasScaleX = $outputWidth / $canvasWidth;
        $canvasScaleY = $outputHeight / $canvasHeight;
        $normalized = [];

        foreach ($layers as $layer) {
            if (! is_array($layer)
                || ! filter_var($layer['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN)
                || (array_key_exists('visible', $layer) && ! filter_var($layer['visible'], FILTER_VALIDATE_BOOLEAN))) {
                continue;
            }

            $scaleX = abs((float) ($layer['scale_x'] ?? 1.0));
            $scaleY = abs((float) ($layer['scale_y'] ?? 1.0));
            $width = isset($layer['width']) ? max(1.0, (float) $layer['width']) : null;
            $height = isset($layer['height']) ? max(1.0, (float) $layer['height']) : null;
            $effectiveWidth = $width !== null ? $width * max(0.001, $scaleX) : null;
            $effectiveHeight = $height !== null ? $height * max(0.001, $scaleY) : null;
            $anchorX = max(0.0, min(1.0, (float) ($layer['anchor_x'] ?? 0.5)));
            $anchorY = max(0.0, min(1.0, (float) ($layer['anchor_y'] ?? 0.5)));
            $positionX = (float) ($layer['x'] ?? 0);
            $positionY = (float) ($layer['y'] ?? 0);
            $fit = strtolower((string) ($layer['fit'] ?? 'contain'));

            if (! in_array($fit, ['contain', 'cover', 'fill', 'none'], true)) {
                throw ValidationException::withMessages([
                    'scenes.tracks.fit' => "Fit mode [{$fit}] is not supported.",
                ]);
            }

            $layer['x'] = (int) round(($positionX - (($effectiveWidth ?? 0.0) * $anchorX)) * $canvasScaleX);
            $layer['y'] = (int) round(($positionY - (($effectiveHeight ?? 0.0) * $anchorY)) * $canvasScaleY);
            $layer['width'] = $effectiveWidth !== null ? max(1, (int) round($effectiveWidth * $canvasScaleX)) : null;
            $layer['height'] = $effectiveHeight !== null ? max(1, (int) round($effectiveHeight * $canvasScaleY)) : null;
            $layer['rotation_degrees'] = (float) ($layer['rotation'] ?? 0.0);
            $layer['rotation_radians'] = deg2rad($layer['rotation_degrees']);
            $layer['opacity'] = max(0.0, min(1.0, (float) ($layer['opacity'] ?? 1.0)));
            if (isset($layer['size'])) {
                $layer['size'] = max(1, (int) round((float) $layer['size'] * $canvasScaleY));
            }
            $layer['fit'] = $fit;
            $layer['canvas_scale_x'] = $canvasScaleX;
            $layer['canvas_scale_y'] = $canvasScaleY;
            $normalized[] = array_filter($layer, static fn ($value) => $value !== null && $value !== '');
        }

        usort($normalized, static function (array $left, array $right): int {
            return (((int) ($left['z_index'] ?? 0)) <=> ((int) ($right['z_index'] ?? 0)))
                ?: strcmp((string) ($left['id'] ?? ''), (string) ($right['id'] ?? ''));
        });

        return $normalized;
    }

    /**
     * @param  array<int, array<string, mixed>>  $assets
     * @return array<int, array<string, mixed>>
     */
    private function normalizeAssetReferences(array $assets, int $userId, ?int $videoId): array
    {
        $normalized = [];

        foreach (array_values($assets) as $index => $asset) {
            if (! is_array($asset)) {
                throw ValidationException::withMessages([
                    "assets.{$index}" => 'Each asset must be an array.',
                ]);
            }

            $reference = $this->normalizeAssetReference($asset, $index);
            $this->validateManagedAssetReference($reference, $index, $userId, $videoId);
            $normalized[] = $reference;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $asset
     */
    private function validateManagedAssetReference(array $asset, int $index, int $userId, ?int $videoId): void
    {
        $type = strtolower((string) ($asset['type'] ?? ''));
        $mimeType = strtolower((string) ($asset['mime_type'] ?? ''));
        $provider = (string) ($asset['storage_provider'] ?? '');
        $storageKey = ltrim((string) ($asset['storage_key'] ?? ''), '/');
        $publicId = trim((string) ($asset['public_id'] ?? ''), '/');

        if (! in_array($type, ['video', 'image', 'audio', 'font', 'sticker', 'drawing', 'lut', 'mask'], true)) {
            throw ValidationException::withMessages([
                "assets.{$index}.type" => "Asset type [{$type}] is not supported.",
            ]);
        }

        if ($mimeType !== '' && preg_match('#^(video|image|audio|font)/[a-z0-9.+-]+$#', $mimeType) !== 1) {
            throw ValidationException::withMessages([
                "assets.{$index}.mimeType" => 'The asset MIME type is not allowed.',
            ]);
        }

        $mimePrefixByType = [
            'video' => 'video/',
            'image' => 'image/',
            'sticker' => 'image/',
            'drawing' => 'image/',
            'mask' => 'image/',
            'audio' => 'audio/',
            'font' => 'font/',
        ];

        if ($mimeType !== '' && isset($mimePrefixByType[$type]) && ! str_starts_with($mimeType, $mimePrefixByType[$type])) {
            throw ValidationException::withMessages([
                "assets.{$index}.mimeType" => 'The asset MIME type does not match its declared type.',
            ]);
        }

        if (isset($asset['file_size']) && (int) $asset['file_size'] > 100 * 1024 * 1024) {
            throw ValidationException::withMessages([
                "assets.{$index}.fileSize" => 'Project assets may not exceed 100 MB.',
            ]);
        }

        if ($publicId !== '' && $provider !== 'cloudinary') {
            throw ValidationException::withMessages([
                "assets.{$index}.storageProvider" => 'Cloudinary public IDs must declare Cloudinary as their storage provider.',
            ]);
        }

        if ($provider === 'cloudinary') {
            if ($storageKey === '' && $publicId === '') {
                throw ValidationException::withMessages([
                    "assets.{$index}.storageKey" => 'Cloudinary assets require a managed public ID.',
                ]);
            }

            return;
        }

        if ($provider === '' || $storageKey === '') {
            return;
        }

        $editAssetPrefix = trim((string) config('video.edit_asset_directory', 'videos/edit-assets'), '/')."/{$userId}/";
        $videoAssetPrefix = $videoId !== null ? "videos/{$videoId}/assets/" : null;
        $belongsToProject = str_starts_with($storageKey, $editAssetPrefix)
            || ($videoAssetPrefix !== null && str_starts_with($storageKey, $videoAssetPrefix));

        if (! $belongsToProject) {
            throw ValidationException::withMessages([
                "assets.{$index}.storageKey" => 'The asset does not belong to this user or video project.',
            ]);
        }

        if (! array_key_exists($provider, (array) config('filesystems.disks', []))) {
            throw ValidationException::withMessages([
                "assets.{$index}.storageKey" => 'The managed project asset could not be found.',
            ]);
        }

        $disk = Storage::disk($provider);

        if (! $disk->exists($storageKey)) {
            throw ValidationException::withMessages([
                "assets.{$index}.storageKey" => 'The managed project asset could not be found.',
            ]);
        }

        $actualSize = $disk->size($storageKey);
        if ($actualSize > 100 * 1024 * 1024) {
            throw ValidationException::withMessages([
                "assets.{$index}.storageKey" => 'The managed project asset exceeds 100 MB.',
            ]);
        }

        $actualMimeType = strtolower((string) $disk->mimeType($storageKey));
        if ($actualMimeType !== '' && isset($mimePrefixByType[$type]) && ! str_starts_with($actualMimeType, $mimePrefixByType[$type])) {
            throw ValidationException::withMessages([
                "assets.{$index}.storageKey" => 'The stored asset content does not match its declared type.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $asset
     * @return array<string, mixed>
     */
    private function normalizeAssetReference(array $asset, int $index): array
    {
        return array_filter([
            'id' => isset($asset['id']) ? (string) $asset['id'] : 'asset-'.($index + 1),
            'type' => isset($asset['type']) ? (string) $asset['type'] : null,
            'storage_provider' => isset($asset['storageProvider']) ? (string) $asset['storageProvider'] : (isset($asset['storage_provider']) ? (string) $asset['storage_provider'] : null),
            'storage_key' => isset($asset['storageKey']) ? (string) $asset['storageKey'] : (isset($asset['storage_key']) ? (string) $asset['storage_key'] : null),
            'url' => isset($asset['url']) ? (string) $asset['url'] : null,
            'mime_type' => isset($asset['mimeType']) ? (string) $asset['mimeType'] : (isset($asset['mime_type']) ? (string) $asset['mime_type'] : null),
            'file_name' => isset($asset['fileName']) ? (string) $asset['fileName'] : (isset($asset['file_name']) ? (string) $asset['file_name'] : null),
            'file_size' => isset($asset['fileSize']) ? (int) $asset['fileSize'] : (isset($asset['file_size']) ? (int) $asset['file_size'] : null),
            'width' => isset($asset['width']) ? (int) $asset['width'] : null,
            'height' => isset($asset['height']) ? (int) $asset['height'] : null,
            'duration' => isset($asset['duration']) ? (float) $asset['duration'] : null,
            'fps' => isset($asset['fps']) ? (float) $asset['fps'] : null,
            'has_audio' => array_key_exists('hasAudio', $asset) ? filter_var($asset['hasAudio'], FILTER_VALIDATE_BOOLEAN) : (array_key_exists('has_audio', $asset) ? filter_var($asset['has_audio'], FILTER_VALIDATE_BOOLEAN) : null),
            'checksum' => isset($asset['checksum']) ? (string) $asset['checksum'] : null,
            'public_id' => isset($asset['publicId']) ? (string) $asset['publicId'] : (isset($asset['public_id']) ? (string) $asset['public_id'] : null),
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  array<int, array<string, mixed>>  $assets
     * @return array<string, array<string, mixed>>
     */
    private function indexAssetsById(array $assets): array
    {
        $indexed = [];

        foreach ($assets as $asset) {
            if (! is_array($asset)) {
                continue;
            }

            $id = (string) ($asset['id'] ?? '');

            if ($id !== '') {
                $indexed[$id] = $asset;
            }
        }

        return $indexed;
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function normalizeProjectMetadata(array $metadata): array
    {
        return array_filter([
            'id' => isset($metadata['id']) ? (string) $metadata['id'] : null,
            'name' => isset($metadata['name']) ? (string) $metadata['name'] : null,
            'description' => isset($metadata['description']) ? (string) $metadata['description'] : null,
            'createdAt' => isset($metadata['createdAt']) ? (string) $metadata['createdAt'] : null,
            'updatedAt' => isset($metadata['updatedAt']) ? (string) $metadata['updatedAt'] : null,
            'createdBy' => isset($metadata['createdBy']) ? (string) $metadata['createdBy'] : null,
            'duration' => isset($metadata['duration']) ? max(0.0, (float) $metadata['duration']) : null,
            'revision' => isset($metadata['revision']) ? (int) $metadata['revision'] : null,
            'source' => isset($metadata['source']) ? (string) $metadata['source'] : null,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $canvas
     * @return array<string, mixed>
     */
    private function normalizeV3Canvas(array $canvas): array
    {
        $background = is_array($canvas['background'] ?? null) ? $canvas['background'] : [];
        $safeArea = is_array($canvas['safeArea'] ?? null) ? $canvas['safeArea'] : [];

        return array_filter([
            'width' => isset($canvas['width']) ? max(1, (int) $canvas['width']) : 1080,
            'height' => isset($canvas['height']) ? max(1, (int) $canvas['height']) : 1920,
            'fps' => isset($canvas['fps']) ? max(1, (int) $canvas['fps']) : 30,
            'duration' => isset($canvas['duration']) ? max(0.0, (float) $canvas['duration']) : null,
            'background_color' => isset($canvas['backgroundColor']) ? $this->normalizeHexColor((string) $canvas['backgroundColor']) : (isset($background['color']) ? $this->normalizeHexColor((string) $background['color']) : '#000000'),
            'preview_width' => isset($canvas['previewWidth']) ? max(1, (int) $canvas['previewWidth']) : 360,
            'preview_height' => isset($canvas['previewHeight']) ? max(1, (int) $canvas['previewHeight']) : 640,
            'aspect_ratio' => isset($canvas['aspectRatio']) ? (string) $canvas['aspectRatio'] : '9:16',
            'background' => array_filter([
                'type' => isset($background['type']) ? (string) $background['type'] : null,
                'color' => isset($background['color']) ? $this->normalizeHexColor((string) $background['color']) : null,
                'opacity' => isset($background['opacity']) ? max(0.0, min(1.0, (float) $background['opacity'])) : null,
                'gradient' => is_array($background['gradient'] ?? null) ? $background['gradient'] : null,
                'asset_id' => isset($background['assetId']) ? (string) $background['assetId'] : (isset($background['asset_id']) ? (string) $background['asset_id'] : null),
                'blur' => isset($background['blur']) ? max(0, (int) $background['blur']) : null,
                'raw' => $background,
            ], static fn ($value) => $value !== null && $value !== ''),
            'safe_areas' => array_filter([
                'enabled' => array_key_exists('enabled', $safeArea) ? filter_var($safeArea['enabled'], FILTER_VALIDATE_BOOLEAN) : null,
                'top' => isset($safeArea['top']) ? (int) $safeArea['top'] : null,
                'right' => isset($safeArea['right']) ? (int) $safeArea['right'] : null,
                'bottom' => isset($safeArea['bottom']) ? (int) $safeArea['bottom'] : null,
                'left' => isset($safeArea['left']) ? (int) $safeArea['left'] : null,
                'raw' => $safeArea,
            ], static fn ($value) => $value !== null && $value !== ''),
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $output
     * @return array<string, mixed>
     */
    private function normalizeV3Output(array $output): array
    {
        $allowed = [
            'format' => [['format'], ['mp4']],
            'videoCodec' => [['videoCodec', 'video_codec'], ['h264']],
            'audioCodec' => [['audioCodec', 'audio_codec'], ['aac']],
            'pixelFormat' => [['pixelFormat', 'pixel_format'], ['yuv420p']],
            'encoderPreset' => [['encoderPreset', 'preset'], ['veryfast', 'faster', 'fast', 'medium']],
        ];

        foreach ($allowed as $field => [$aliases, $values]) {
            $sourceField = collect($aliases)->first(fn (string $alias): bool => isset($output[$alias]) && $output[$alias] !== '');

            if ($sourceField === null) {
                continue;
            }

            $value = strtolower(trim((string) $output[$sourceField]));
            if (! in_array($value, $values, true)) {
                throw ValidationException::withMessages([
                    "output.{$field}" => "The selected {$field} is not supported.",
                ]);
            }

            $output[$sourceField] = $value;
        }

        if (isset($output['width'])) {
            $output['width'] = max(16, min(3840, (int) $output['width']));
        }

        if (isset($output['height'])) {
            $output['height'] = max(16, min(3840, (int) $output['height']));
        }

        if (isset($output['fps'])) {
            $output['fps'] = max(1, min(60, (int) $output['fps']));
        }

        if (isset($output['crf'])) {
            $output['crf'] = max(0, min(51, (int) $output['crf']));
        }

        $mapped = [
            'format' => $output['format'] ?? null,
            'quality' => $output['quality'] ?? null,
            'width' => $output['width'] ?? null,
            'height' => $output['height'] ?? null,
            'fps' => $output['fps'] ?? null,
            'crf' => $output['crf'] ?? null,
            'video_bitrate' => $output['videoBitrate'] ?? ($output['video_bitrate'] ?? null),
            'audio_bitrate' => $output['audioBitrate'] ?? ($output['audio_bitrate'] ?? null),
            'audio_sample_rate' => $output['audioSampleRate'] ?? ($output['audio_sample_rate'] ?? null),
            'audio_channels' => $output['audioChannels'] ?? ($output['audio_channels'] ?? null),
            'pixel_format' => $output['pixelFormat'] ?? ($output['pixel_format'] ?? null),
            'preset' => $output['encoderPreset'] ?? ($output['preset'] ?? null),
            'audio' => $output['audio'] ?? null,
            'bitrate' => $output['videoBitrate'] ?? ($output['bitrate'] ?? null),
            'poster' => $output['poster'] ?? null,
        ];

        $normalized = $this->normalizeOutput($mapped);

        if (array_key_exists('videoCodec', $output) && $output['videoCodec'] !== null && $output['videoCodec'] !== '') {
            $normalized['video_codec'] = (string) $output['videoCodec'];
        }

        if (array_key_exists('audioCodec', $output) && $output['audioCodec'] !== null && $output['audioCodec'] !== '') {
            $normalized['audio_codec'] = (string) $output['audioCodec'];
        }

        if (array_key_exists('fastStart', $output)) {
            $normalized['fast_start'] = filter_var($output['fastStart'], FILTER_VALIDATE_BOOLEAN);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $track
     * @param  array<string, array<string, mixed>>  $assetIndex
     * @return array<string, mixed>
     */
    private function normalizeV3Background(array $background): array
    {
        return array_filter([
            'type' => isset($background['type']) ? (string) $background['type'] : null,
            'color' => isset($background['color']) ? $this->normalizeHexColor((string) $background['color']) : null,
            'opacity' => isset($background['opacity']) ? max(0.0, min(1.0, (float) $background['opacity'])) : null,
            'gradient' => is_array($background['gradient'] ?? null) ? $background['gradient'] : null,
            'asset_id' => isset($background['assetId']) ? (string) $background['assetId'] : (isset($background['asset_id']) ? (string) $background['asset_id'] : null),
            'blur' => isset($background['blur']) ? max(0, (int) $background['blur']) : null,
            'raw' => $background,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  array<int, array<string, mixed>>  $tracks
     */
    private function deriveSceneDurationFromTracks(array $tracks, float $sceneStart, ?float $projectDuration): ?float
    {
        $durations = [];

        foreach ($tracks as $trackBundle) {
            if (! is_array($trackBundle)) {
                continue;
            }

            $track = $trackBundle['track'] ?? [];
            $end = $track['end'] ?? null;

            if (is_numeric($end)) {
                $durations[] = max(0.0, (float) $end) - $sceneStart;
            }
        }

        if ($durations !== []) {
            return max(0.0, max($durations));
        }

        if ($projectDuration !== null) {
            return max(0.0, $projectDuration - $sceneStart);
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $scenes
     * @param  array<int, array<string, mixed>>  $tracks
     */
    private function deriveDurationFromScenes(array $scenes, array $tracks): ?float
    {
        $maxEnd = null;

        foreach ($scenes as $scene) {
            if (! is_array($scene)) {
                continue;
            }

            $timeline = is_array($scene['timeline'] ?? null) ? $scene['timeline'] : [];
            $sceneStart = isset($timeline['start']) && is_numeric($timeline['start']) ? max(0.0, (float) $timeline['start']) : 0.0;
            $sceneDuration = isset($timeline['duration']) && is_numeric($timeline['duration']) ? max(0.0, (float) $timeline['duration']) : null;

            if ($sceneDuration !== null) {
                $sceneEnd = $sceneStart + $sceneDuration;
                $maxEnd = $maxEnd === null ? $sceneEnd : max($maxEnd, $sceneEnd);
            }
        }

        foreach ($tracks as $track) {
            if (! is_array($track)) {
                continue;
            }

            if (is_numeric($track['end'] ?? null)) {
                $maxEnd = $maxEnd === null ? (float) $track['end'] : max($maxEnd, (float) $track['end']);
            }
        }

        return $maxEnd;
    }

    /**
     * @param  array<int, mixed>  $globalAudioTracks
     * @return array<int, mixed>
     */
    private function normalizeV3GlobalAudioTracks(array $globalAudioTracks): array
    {
        return array_values(array_filter($globalAudioTracks, static fn ($value) => $value !== null && $value !== ''));
    }

    /**
     * @param  array<int, mixed>  $globalEffects
     * @return array<int, mixed>
     */
    private function normalizeV3GlobalEffects(array $globalEffects): array
    {
        return array_values(array_filter($globalEffects, static fn ($value) => $value !== null && $value !== ''));
    }

    /**
     * @param  array<int, mixed>  $guides
     * @return array<int, mixed>
     */
    private function normalizeV3Guides(array $guides): array
    {
        return array_values(array_filter($guides, static fn ($value) => $value !== null && $value !== ''));
    }

    private function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * @param  array<string, mixed>  $track
     */
    private function validateRichTrackCapabilities(array $track, int $index): void
    {
        // Rich track effects are intentionally permissive here.
        // Renderer selection decides whether Cloudinary or FFmpeg handles the payload.
    }

    /**
     * @param  array<string, mixed>  $track
     * @return array{track:array<string, mixed>,render_layer:array<string, mixed>|null}
     */
    private function normalizeTrack(array $track, int $index, ?float $projectDuration): array
    {
        $type = (string) ($track['type'] ?? '');

        if (! in_array($type, ['text', 'captions', 'audio', 'voiceover', 'image', 'sticker', 'animated_sticker', 'drawing', 'shape', 'effect', 'adjustment', 'composition', 'mask', 'matte', 'background', 'guide', 'transition'], true)) {
            throw ValidationException::withMessages([
                "project.tracks.{$index}.type" => "Track type [{$type}] is not supported by the current renderer.",
            ]);
        }

        $baseTrack = $this->normalizeBaseTrack($track, $index, $projectDuration);
        $rawTrack = $track;
        $richPassThroughTypes = ['shape', 'effect', 'adjustment', 'composition', 'mask', 'matte', 'background', 'guide', 'transition'];

        if (in_array($type, ['text', 'captions'], true)) {
            $textContent = is_array($track['content'] ?? null) ? $track['content'] : [];
            $background = is_array($track['background'] ?? null) ? $track['background'] : [];
            $stroke = is_array($track['stroke'] ?? null) ? $track['stroke'] : [];
            $shadow = is_array($track['shadow'] ?? null) ? $track['shadow'] : [];
            $transition = is_array($track['transition'] ?? null) ? $track['transition'] : [];
            $content = $this->normalizeTextContent($track, $textContent);
            $transform = $this->normalizeTransform($track, $track['transform'] ?? null, $projectDuration);

            $renderLayer = array_filter([
                'type' => 'text',
                'text' => $content['text'],
                'font' => $content['font_key'] ?? $content['font_family'] ?? $track['font'] ?? 'Arial',
                'font_key' => $content['font_key'] ?? null,
                'font_family' => $content['font_family'] ?? null,
                'font_weight' => $content['font_weight'] ?? null,
                'font_style' => $content['font_style'] ?? null,
                'size' => $content['font_size'],
                'color' => $content['color'],
                'opacity' => $content['opacity'],
                'alignment' => $content['alignment'] ?? null,
                'line_height' => $content['line_height'] ?? null,
                'letter_spacing' => $content['letter_spacing'] ?? null,
                'box' => (bool) ($background['enabled'] ?? $track['box'] ?? true),
                'box_color' => $background['color'] ?? $track['box_color'] ?? 'black@0.35',
                'box_border_width' => max(
                    (int) ($background['padding_x'] ?? 0),
                    (int) ($background['padding_y'] ?? 0),
                    (int) ($track['box_border_width'] ?? 0)
                ),
                'x' => $transform['x'] ?? null,
                'y' => $transform['y'] ?? null,
                'width' => $transform['width'] ?? null,
                'height' => $transform['height'] ?? null,
                'rotation' => $transform['rotation'] ?? null,
                'scale_x' => $transform['scale_x'] ?? null,
                'scale_y' => $transform['scale_y'] ?? null,
                'margin_x' => $transform['margin_x'] ?? null,
                'margin_y' => $transform['margin_y'] ?? null,
                'padding_x' => $background['padding_x'] ?? null,
                'padding_y' => $background['padding_y'] ?? null,
                'start' => $baseTrack['start'],
                'end' => $baseTrack['end'],
                'enabled' => $baseTrack['enabled'],
                'z_index' => $baseTrack['z_index'],
                'metadata' => [
                    'background' => $background,
                    'stroke' => $stroke,
                    'shadow' => $shadow,
                    'transition' => $transition,
                    'animation' => is_array($track['animation'] ?? null) ? $track['animation'] : [],
                    'keyframes' => is_array($track['keyframes'] ?? null) ? $track['keyframes'] : [],
                    'blend_mode' => is_array($track['blend_mode'] ?? null) ? $track['blend_mode'] : [],
                    'border' => is_array($track['border'] ?? null) ? $track['border'] : [],
                    'glow' => is_array($track['glow'] ?? null) ? $track['glow'] : [],
                    'raw' => $rawTrack,
                ],
            ], static fn ($value) => $value !== null && $value !== '');

            $baseTrack['content'] = $content;
            $baseTrack['background'] = $background;
            $baseTrack['stroke'] = $stroke;
            $baseTrack['shadow'] = $shadow;
            $baseTrack['transition'] = $transition;
            $baseTrack['transform'] = $transform;
            $baseTrack['blend_mode'] = is_array($track['blend_mode'] ?? null) ? $track['blend_mode'] : [];
            $baseTrack['border'] = is_array($track['border'] ?? null) ? $track['border'] : [];
            $baseTrack['glow'] = is_array($track['glow'] ?? null) ? $track['glow'] : [];
            $baseTrack['raw'] = $rawTrack;

            return [
                'track' => $baseTrack,
                'render_layer' => $renderLayer,
            ];
        }

        if (in_array($type, $richPassThroughTypes, true)) {
            $transform = $this->normalizeTransform($track, $track['transform'] ?? null, $projectDuration);

            $renderLayer = array_filter([
                'type' => $type,
                'name' => $baseTrack['name'] ?? null,
                'start' => $baseTrack['start'],
                'end' => $baseTrack['end'],
                'duration' => $baseTrack['duration'] ?? null,
                'enabled' => $baseTrack['enabled'],
                'locked' => $baseTrack['locked'],
                'muted' => $baseTrack['muted'],
                'z_index' => $baseTrack['z_index'],
                'group_id' => isset($track['group_id']) ? (string) $track['group_id'] : null,
                'visible' => array_key_exists('visible', $track) ? filter_var($track['visible'], FILTER_VALIDATE_BOOLEAN) : null,
                'transform' => $transform,
                'effects' => $baseTrack['effects'],
                'keyframes' => $baseTrack['keyframes'],
                'animation' => $baseTrack['animation'],
                'metadata' => $baseTrack['metadata'],
                'blend_mode' => is_array($track['blend_mode'] ?? null) ? $track['blend_mode'] : [],
                'border' => is_array($track['border'] ?? null) ? $track['border'] : [],
                'shadow' => is_array($track['shadow'] ?? null) ? $track['shadow'] : [],
                'glow' => is_array($track['glow'] ?? null) ? $track['glow'] : [],
                'raw' => $rawTrack,
            ], static fn ($value) => $value !== null && $value !== '');

            $baseTrack['transform'] = $transform;
            $baseTrack['group_id'] = isset($track['group_id']) ? (string) $track['group_id'] : null;
            $baseTrack['visible'] = array_key_exists('visible', $track) ? filter_var($track['visible'], FILTER_VALIDATE_BOOLEAN) : null;
            $baseTrack['blend_mode'] = is_array($track['blend_mode'] ?? null) ? $track['blend_mode'] : [];
            $baseTrack['border'] = is_array($track['border'] ?? null) ? $track['border'] : [];
            $baseTrack['shadow'] = is_array($track['shadow'] ?? null) ? $track['shadow'] : [];
            $baseTrack['glow'] = is_array($track['glow'] ?? null) ? $track['glow'] : [];
            $baseTrack['raw'] = $rawTrack;

            return [
                'track' => $baseTrack,
                'render_layer' => $renderLayer,
            ];
        }

        $transform = $this->normalizeTransform($track, $track['transform'] ?? null, $projectDuration);
        $asset = $this->normalizeAsset($track);

        $renderLayer = array_filter([
            'type' => $type,
            'public_id' => $asset['public_id'] ?? null,
            'asset_public_id' => $asset['asset_public_id'] ?? null,
            'asset_url' => $asset['asset_url'] ?? null,
            'asset_disk' => $asset['asset_disk'] ?? null,
            'asset_key' => $asset['asset_key'] ?? null,
            'x' => $transform['x'] ?? null,
            'y' => $transform['y'] ?? null,
            'width' => $transform['width'] ?? null,
            'height' => $transform['height'] ?? null,
            'rotation' => $transform['rotation'] ?? null,
            'opacity' => $transform['opacity'] ?? null,
            'scale_x' => $transform['scale_x'] ?? null,
            'scale_y' => $transform['scale_y'] ?? null,
            'margin_x' => $transform['margin_x'] ?? null,
            'margin_y' => $transform['margin_y'] ?? null,
            'start' => $baseTrack['start'] ?? null,
            'end' => $baseTrack['end'] ?? null,
            'enabled' => $baseTrack['enabled'] ?? null,
            'z_index' => $baseTrack['z_index'] ?? null,
            'blend_mode' => is_array($track['blend_mode'] ?? null) ? $track['blend_mode'] : [],
            'border' => is_array($track['border'] ?? null) ? $track['border'] : [],
            'shadow' => is_array($track['shadow'] ?? null) ? $track['shadow'] : [],
            'glow' => is_array($track['glow'] ?? null) ? $track['glow'] : [],
            'raw' => $rawTrack,
        ], static fn ($value) => $value !== null && $value !== '');

        $baseTrack['asset'] = $asset;
        $baseTrack['transform'] = $transform;
        $baseTrack['blend_mode'] = is_array($track['blend_mode'] ?? null) ? $track['blend_mode'] : [];
        $baseTrack['border'] = is_array($track['border'] ?? null) ? $track['border'] : [];
        $baseTrack['shadow'] = is_array($track['shadow'] ?? null) ? $track['shadow'] : [];
        $baseTrack['glow'] = is_array($track['glow'] ?? null) ? $track['glow'] : [];
        $baseTrack['raw'] = $rawTrack;

        return [
            'track' => $baseTrack,
            'render_layer' => $renderLayer,
        ];
    }

    /**
     * @param  array<string, mixed>  $layer
     * @return array{track:array<string, mixed>,render_layer:array<string, mixed>}
     */
    private function normalizeLegacyLayer(array $layer, int $index, int $userId): array
    {
        $type = (string) ($layer['type'] ?? '');

        if (! in_array($type, ['text', 'drawing', 'image', 'sticker', 'watermark', 'audio'], true)) {
            throw ValidationException::withMessages([
                "layers.{$index}.type" => 'Unsupported layer type.',
            ]);
        }

        $baseTrack = $this->normalizeBaseTrack($layer, $index, null);
        $transform = $this->normalizeTransform($layer, $layer['transform'] ?? null, null);
        $asset = $this->normalizeAsset($layer);

        if ($type === 'text') {
            $content = $this->normalizeTextContent($layer, is_array($layer['content'] ?? null) ? $layer['content'] : []);
            $background = is_array($layer['background'] ?? null) ? $layer['background'] : [];
            $stroke = is_array($layer['stroke'] ?? null) ? $layer['stroke'] : [];
            $shadow = is_array($layer['shadow'] ?? null) ? $layer['shadow'] : [];

            $renderLayer = array_filter([
                'type' => 'text',
                'text' => $content['text'],
                'font' => $content['font_key'] ?? $content['font_family'] ?? $layer['font'] ?? 'Arial',
                'font_key' => $content['font_key'] ?? null,
                'font_family' => $content['font_family'] ?? null,
                'font_weight' => $content['font_weight'] ?? null,
                'font_style' => $content['font_style'] ?? null,
                'size' => $content['font_size'],
                'color' => $content['color'],
                'opacity' => $content['opacity'],
                'alignment' => $content['alignment'] ?? null,
                'line_height' => $content['line_height'] ?? null,
                'letter_spacing' => $content['letter_spacing'] ?? null,
                'box' => (bool) ($background['enabled'] ?? $layer['box'] ?? true),
                'box_color' => $background['color'] ?? $layer['box_color'] ?? 'black@0.35',
                'box_border_width' => max(
                    (int) ($background['padding_x'] ?? 0),
                    (int) ($background['padding_y'] ?? 0),
                    (int) ($layer['box_border_width'] ?? 0)
                ),
                'x' => $transform['x'] ?? null,
                'y' => $transform['y'] ?? null,
                'width' => $transform['width'] ?? null,
                'height' => $transform['height'] ?? null,
                'rotation' => $transform['rotation'] ?? null,
                'scale_x' => $transform['scale_x'] ?? null,
                'scale_y' => $transform['scale_y'] ?? null,
                'margin_x' => $transform['margin_x'] ?? null,
                'margin_y' => $transform['margin_y'] ?? null,
                'padding_x' => $background['padding_x'] ?? null,
                'padding_y' => $background['padding_y'] ?? null,
                'start' => $baseTrack['start'],
                'end' => $baseTrack['end'],
            ], static fn ($value) => $value !== null && $value !== '');

            $baseTrack['content'] = $content;
            $baseTrack['background'] = $background;
            $baseTrack['stroke'] = $stroke;
            $baseTrack['shadow'] = $shadow;
            $baseTrack['transform'] = $transform;

            return [
                'track' => $baseTrack,
                'render_layer' => $renderLayer,
            ];
        }

        $renderLayer = array_filter([
            'type' => $type,
            'public_id' => $asset['public_id'] ?? null,
            'asset_public_id' => $asset['asset_public_id'] ?? null,
            'asset_url' => $asset['asset_url'] ?? null,
            'asset_disk' => $asset['asset_disk'] ?? null,
            'asset_key' => $asset['asset_key'] ?? null,
            'x' => $transform['x'] ?? null,
            'y' => $transform['y'] ?? null,
            'width' => $transform['width'] ?? null,
            'height' => $transform['height'] ?? null,
            'rotation' => $transform['rotation'] ?? null,
            'opacity' => $transform['opacity'] ?? null,
            'scale_x' => $transform['scale_x'] ?? null,
            'scale_y' => $transform['scale_y'] ?? null,
            'margin_x' => $transform['margin_x'] ?? null,
            'margin_y' => $transform['margin_y'] ?? null,
            'start' => $baseTrack['start'] ?? null,
            'end' => $baseTrack['end'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');

        $baseTrack['asset'] = $asset;
        $baseTrack['transform'] = $transform;

        return [
            'track' => $baseTrack,
            'render_layer' => $renderLayer,
        ];
    }

    /**
     * @param  array<string, mixed>  $track
     * @return array<string, mixed>
     */
    private function normalizeBaseTrack(array $track, int $index, ?float $projectDuration): array
    {
        $start = max(0.0, (float) ($track['start'] ?? 0));
        $end = array_key_exists('end', $track) && $track['end'] !== null
            ? max(0.0, (float) $track['end'])
            : null;

        if ($end === null && $projectDuration !== null) {
            $end = $projectDuration;
        }

        if ($end !== null && $end <= $start) {
            throw ValidationException::withMessages([
                "tracks.{$index}.end" => 'Track end time must be greater than start time.',
            ]);
        }

        if ($projectDuration !== null) {
            $start = min($start, $projectDuration);
            if ($end !== null) {
                $end = min($end, $projectDuration);
            }
        }

        $normalized = [
            'id' => isset($track['id']) && $track['id'] !== '' ? (string) $track['id'] : 'track-'.($index + 1),
            'type' => (string) ($track['type'] ?? ''),
            'name' => isset($track['name']) ? (string) $track['name'] : null,
            'category' => isset($track['category']) ? (string) $track['category'] : null,
            'start' => $start,
            'end' => $end,
            'duration' => $end !== null ? max(0.0, $end - $start) : null,
            'z_index' => (int) ($track['z_index'] ?? $index),
            'enabled' => filter_var($track['enabled'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'locked' => filter_var($track['locked'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'muted' => filter_var($track['muted'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'trim' => is_array($track['trim'] ?? null) ? $track['trim'] : [],
            'effects' => is_array($track['effects'] ?? null) ? array_values($track['effects']) : [],
            'keyframes' => is_array($track['keyframes'] ?? null) ? array_values($track['keyframes']) : [],
            'animation' => is_array($track['animation'] ?? null) ? $track['animation'] : [],
            'metadata' => is_array($track['metadata'] ?? null) ? $track['metadata'] : [],
            'group_id' => isset($track['group_id']) ? (string) $track['group_id'] : null,
            'visible' => array_key_exists('visible', $track) ? filter_var($track['visible'], FILTER_VALIDATE_BOOLEAN) : null,
            'blend_mode' => is_array($track['blend_mode'] ?? null) ? $track['blend_mode'] : [],
            'border' => is_array($track['border'] ?? null) ? $track['border'] : [],
            'shadow' => is_array($track['shadow'] ?? null) ? $track['shadow'] : [],
            'glow' => is_array($track['glow'] ?? null) ? $track['glow'] : [],
            'raw' => $track,
        ];

        if ($projectDuration !== null) {
            $normalized['start'] = max(0.0, min($normalized['start'], $projectDuration));
            if ($normalized['end'] !== null) {
                $normalized['end'] = max($normalized['start'], min($normalized['end'], $projectDuration));
                $normalized['duration'] = max(0.0, $normalized['end'] - $normalized['start']);
            }
        }

        return array_filter($normalized, static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $track
     * @return array<string, mixed>
     */
    private function normalizeTextContent(array $track, array $content): array
    {
        $text = trim((string) ($content['text'] ?? $track['text'] ?? ''));

        if ($text === '') {
            throw ValidationException::withMessages([
                'text' => 'Text layers require text.',
            ]);
        }

        return array_filter([
            'text' => mb_substr($text, 0, 500),
            'font_family' => isset($content['font_family']) ? (string) $content['font_family'] : null,
            'font_key' => isset($content['font_key']) ? (string) $content['font_key'] : null,
            'font_size' => max(8, (int) ($content['font_size'] ?? $track['size'] ?? $track['font_size'] ?? 42)),
            'font_weight' => isset($content['font_weight']) ? (int) $content['font_weight'] : (isset($track['font_weight']) ? (int) $track['font_weight'] : null),
            'font_style' => isset($content['font_style']) ? (string) $content['font_style'] : ($track['font_style'] ?? null),
            'letter_spacing' => isset($content['letter_spacing']) ? (float) $content['letter_spacing'] : (isset($track['letter_spacing']) ? (float) $track['letter_spacing'] : null),
            'line_spacing' => isset($content['line_spacing']) ? (float) $content['line_spacing'] : (isset($track['line_spacing']) ? (float) $track['line_spacing'] : null),
            'line_height' => isset($content['line_height']) ? (float) $content['line_height'] : (isset($track['line_height']) ? (float) $track['line_height'] : null),
            'alignment' => isset($content['alignment']) ? (string) $content['alignment'] : ($track['alignment'] ?? null),
            'vertical_alignment' => isset($content['vertical_alignment']) ? (string) $content['vertical_alignment'] : ($track['vertical_alignment'] ?? null),
            'color' => $this->normalizeHexColor((string) ($content['color'] ?? $track['color'] ?? '#FFFFFF')),
            'opacity' => isset($content['opacity']) ? max(0.0, min(1.0, (float) $content['opacity'])) : (isset($track['opacity']) ? max(0.0, min(1.0, (float) $track['opacity'])) : 1.0),
            'uppercase' => filter_var($content['uppercase'] ?? $track['uppercase'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'max_width' => isset($content['max_width']) ? max(1, (int) $content['max_width']) : (isset($track['max_width']) ? max(1, (int) $track['max_width']) : null),
            'wrap' => filter_var($content['wrap'] ?? $track['wrap'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'preset' => isset($content['preset']) ? (string) $content['preset'] : (isset($track['preset']) ? (string) $track['preset'] : null),
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $track
     * @return array<string, mixed>
     */
    private function normalizeTransform(array $track, mixed $transform, ?float $projectDuration): array
    {
        $transform = is_array($transform) ? $transform : [];
        $startX = $track['x'] ?? 0;
        $startY = $track['y'] ?? 0;

        return array_filter([
            'coordinate_system' => isset($transform['coordinate_system']) ? (string) $transform['coordinate_system'] : 'canvas',
            'anchor_x' => isset($transform['anchor_x']) ? (float) $transform['anchor_x'] : 0.5,
            'anchor_y' => isset($transform['anchor_y']) ? (float) $transform['anchor_y'] : 0.5,
            'x' => (int) round((float) ($transform['x'] ?? $startX)),
            'y' => (int) round((float) ($transform['y'] ?? $startY)),
            'normalized_x' => isset($transform['normalized_x']) ? max(0.0, min(1.0, (float) $transform['normalized_x'])) : null,
            'normalized_y' => isset($transform['normalized_y']) ? max(0.0, min(1.0, (float) $transform['normalized_y'])) : null,
            'width' => isset($transform['width']) ? max(1, (int) $transform['width']) : (isset($track['width']) ? max(1, (int) $track['width']) : null),
            'height' => isset($transform['height']) ? max(1, (int) $transform['height']) : (isset($track['height']) ? max(1, (int) $track['height']) : null),
            'scale_x' => isset($transform['scale_x']) ? (float) $transform['scale_x'] : 1.0,
            'scale_y' => isset($transform['scale_y']) ? (float) $transform['scale_y'] : 1.0,
            'rotation' => isset($transform['rotation']) ? (float) $transform['rotation'] : (isset($track['rotation']) ? (float) $track['rotation'] : 0.0),
            'opacity' => isset($transform['opacity']) ? max(0.0, min(1.0, (float) $transform['opacity'])) : (isset($track['opacity']) ? max(0.0, min(1.0, (float) $track['opacity'])) : 1.0),
            'flip_horizontal' => filter_var($transform['flip_horizontal'] ?? $track['flip_horizontal'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'flip_vertical' => filter_var($transform['flip_vertical'] ?? $track['flip_vertical'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'skew_x' => isset($transform['skew_x']) ? (float) $transform['skew_x'] : (isset($track['skew_x']) ? (float) $track['skew_x'] : 0.0),
            'skew_y' => isset($transform['skew_y']) ? (float) $transform['skew_y'] : (isset($track['skew_y']) ? (float) $track['skew_y'] : 0.0),
            'margin_x' => isset($transform['margin_x']) ? (int) $transform['margin_x'] : (isset($track['margin_x']) ? (int) $track['margin_x'] : 0),
            'margin_y' => isset($transform['margin_y']) ? (int) $transform['margin_y'] : (isset($track['margin_y']) ? (int) $track['margin_y'] : 0),
            'crop' => is_array($transform['crop'] ?? null) ? $transform['crop'] : [],
            'fit' => isset($transform['fit']) ? (string) $transform['fit'] : null,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $track
     * @return array<string, mixed>
     */
    private function normalizeAsset(array $track): array
    {
        return array_filter([
            'media_id' => isset($track['asset']['media_id']) ? (int) $track['asset']['media_id'] : (isset($track['media_id']) ? (int) $track['media_id'] : null),
            'disk' => isset($track['asset']['disk']) ? (string) $track['asset']['disk'] : (isset($track['asset_disk']) ? (string) $track['asset_disk'] : null),
            'path' => isset($track['asset']['path']) ? (string) $track['asset']['path'] : (isset($track['asset_key']) ? (string) $track['asset_key'] : null),
            'format' => isset($track['asset']['format']) ? (string) $track['asset']['format'] : null,
            'animated' => filter_var($track['asset']['animated'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'public_id' => isset($track['public_id']) ? trim((string) $track['public_id'], '/') : (isset($track['asset_public_id']) ? trim((string) $track['asset_public_id'], '/') : null),
            'asset_public_id' => isset($track['asset_public_id']) ? trim((string) $track['asset_public_id'], '/') : null,
            'asset_url' => isset($track['asset_url']) ? (string) $track['asset_url'] : null,
            'asset_disk' => isset($track['asset_disk']) ? (string) $track['asset_disk'] : null,
            'asset_key' => isset($track['asset_key']) ? (string) $track['asset_key'] : null,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeCanvas(array $payload): array
    {
        $background = is_array($payload['background'] ?? null) ? $payload['background'] : [];
        $safeAreas = is_array($payload['safe_areas'] ?? null) ? $payload['safe_areas'] : [];

        return array_filter([
            'width' => isset($payload['width']) ? max(1, (int) $payload['width']) : 1080,
            'height' => isset($payload['height']) ? max(1, (int) $payload['height']) : 1920,
            'fps' => isset($payload['fps']) ? max(1, (int) $payload['fps']) : 30,
            'background_color' => isset($payload['background_color']) ? $this->normalizeHexColor((string) $payload['background_color']) : '#000000',
            'preview_width' => isset($payload['preview_width']) ? max(1, (int) $payload['preview_width']) : 360,
            'preview_height' => isset($payload['preview_height']) ? max(1, (int) $payload['preview_height']) : 640,
            'aspect_ratio' => isset($payload['aspect_ratio']) ? (string) $payload['aspect_ratio'] : '9:16',
            'background' => array_filter([
                'type' => isset($background['type']) ? (string) $background['type'] : null,
                'color' => isset($background['color']) ? $this->normalizeHexColor((string) $background['color']) : null,
                'opacity' => isset($background['opacity']) ? max(0.0, min(1.0, (float) $background['opacity'])) : null,
                'gradient' => is_array($background['gradient'] ?? null) ? $background['gradient'] : null,
                'asset_id' => isset($background['asset_id']) ? (string) $background['asset_id'] : null,
                'blur' => isset($background['blur']) ? max(0, (int) $background['blur']) : null,
                'raw' => $background,
            ], static fn ($value) => $value !== null && $value !== ''),
            'safe_areas' => array_filter([
                'enabled' => array_key_exists('enabled', $safeAreas) ? filter_var($safeAreas['enabled'], FILTER_VALIDATE_BOOLEAN) : null,
                'top' => isset($safeAreas['top']) ? (int) $safeAreas['top'] : null,
                'right' => isset($safeAreas['right']) ? (int) $safeAreas['right'] : null,
                'bottom' => isset($safeAreas['bottom']) ? (int) $safeAreas['bottom'] : null,
                'left' => isset($safeAreas['left']) ? (int) $safeAreas['left'] : null,
                'raw' => $safeAreas,
            ], static fn ($value) => $value !== null && $value !== ''),
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeOutput(array $payload): array
    {
        $normalized = [];

        foreach (['format', 'quality', 'crop', 'audio', 'video_codec', 'audio_codec', 'preset', 'pixel_format'] as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== null && $payload[$key] !== '') {
                $normalized[$key] = (string) $payload[$key];
            }
        }

        foreach (['width', 'height', 'fps', 'crf', 'audio_sample_rate', 'audio_channels'] as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== null && $payload[$key] !== '') {
                $normalized[$key] = (int) $payload[$key];
            }
        }

        if (array_key_exists('bitrate', $payload) && $payload['bitrate'] !== null && $payload['bitrate'] !== '') {
            $normalized['bitrate'] = (string) $payload['bitrate'];
        }

        if (array_key_exists('video_bitrate', $payload) && $payload['video_bitrate'] !== null && $payload['video_bitrate'] !== '') {
            $normalized['video_bitrate'] = (string) $payload['video_bitrate'];
        }

        if (array_key_exists('audio_bitrate', $payload) && $payload['audio_bitrate'] !== null && $payload['audio_bitrate'] !== '') {
            $normalized['audio_bitrate'] = (string) $payload['audio_bitrate'];
        }

        if (array_key_exists('max_bitrate', $payload) && $payload['max_bitrate'] !== null && $payload['max_bitrate'] !== '') {
            $normalized['max_bitrate'] = (string) $payload['max_bitrate'];
        }

        if (array_key_exists('buffer_size', $payload) && $payload['buffer_size'] !== null && $payload['buffer_size'] !== '') {
            $normalized['buffer_size'] = (string) $payload['buffer_size'];
        }

        if (array_key_exists('poster', $payload) && is_array($payload['poster'])) {
            $normalized['poster'] = array_filter([
                'width' => isset($payload['poster']['width']) ? (int) $payload['poster']['width'] : null,
                'height' => isset($payload['poster']['height']) ? (int) $payload['poster']['height'] : null,
            ], static fn ($value) => $value !== null && $value !== '');
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeFilters(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $normalized = [];

        foreach (['brightness', 'contrast', 'saturation', 'hue', 'gamma'] as $key) {
            if (array_key_exists($key, $payload) && $payload[$key] !== null && $payload[$key] !== '') {
                $normalized[$key] = (int) $payload[$key];
            }
        }

        foreach (['grayscale', 'sepia'] as $key) {
            if (array_key_exists($key, $payload)) {
                $normalized[$key] = filter_var($payload[$key], FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeAudio(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        return array_values($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeTrim(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $normalized = [];

        if (array_key_exists('start', $payload) && $payload['start'] !== null && $payload['start'] !== '') {
            $normalized['start'] = max(0, (float) $payload['start']);
        }

        if (array_key_exists('end', $payload) && $payload['end'] !== null && $payload['end'] !== '') {
            $normalized['end'] = max(0, (float) $payload['end']);
        }

        return $normalized;
    }

    private function normalizeHexColor(string $color): string
    {
        $color = trim($color);

        if ($color === '') {
            return '#000000';
        }

        if (! str_starts_with($color, '#')) {
            $color = '#'.$color;
        }

        return preg_match('/^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/', $color) === 1 ? strtoupper($color) : '#000000';
    }
}
