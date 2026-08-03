# Kulsah Video Project v3 Frontend Contract

This document describes the exact project payload the Laravel backend accepts for the v3 editor contract.

Source of truth in backend:
- `app/Http/Controllers/Api/V1/Video/VideoController.php`
- `app/Http/Requests/Api/V1/Video/VideoRenderRequest.php`
- `app/Services/VideoProjectNormalizer.php`
- `app/Services/VideoEditService.php`

## Summary

Send the v3 project as a JSON object with these top-level keys:

- `schemaVersion`
- `metadata`
- `canvas`
- `output`
- `assets`
- `scenes`
- `globalAudioTracks`
- `globalEffects`
- `guides`

The backend also still accepts the older `project`, `layers`, `overlays`, `filters`, `trim`, `audio`, `output`, `canvas`, `tracks`, and `global_filters` shapes for backward compatibility.

Preferred v3 submission shape:

```json
{
  "schemaVersion": "3.0.0",
  "metadata": {},
  "canvas": {},
  "output": {},
  "assets": [],
  "scenes": [],
  "globalAudioTracks": [],
  "globalEffects": [],
  "guides": {}
}
```

## Top-Level Fields

| Field | Type | Required | Notes |
|---|---|---:|---|
| `schemaVersion` | string | yes | Must be `3.0.0` or another 3.x value. Backend stores the literal string. |
| `metadata` | object | no | Project metadata stored on the project document. |
| `canvas` | object | no | Canvas configuration. Defaults are applied when omitted. |
| `output` | object | no | Render/output settings. Defaults are applied when omitted. |
| `assets` | array | no | Asset registry referenced by `source.assetId` inside scenes/tracks. |
| `scenes` | array | recommended | Primary editor timeline structure. If provided, should contain at least one scene. |
| `globalAudioTracks` | array | no | Preserved and forwarded to timeline audio. |
| `globalEffects` | array | no | Preserved in project metadata. |
| `guides` | object | no | Preserved in project metadata. |

The backend also accepts these optional legacy-compatible top-level fields:

- `filters`
- `trim`
- `audio`
- `global_filters`
- `layers`
- `overlays`
- `tracks`
- `project`

## `metadata`

| Field | Type | Required | Notes |
|---|---|---:|---|
| `id` | string | no | Project id. |
| `name` | string | no | Project name. |
| `description` | string | no | Freeform description. |
| `createdAt` | string | no | ISO timestamp string recommended. |
| `updatedAt` | string | no | ISO timestamp string recommended. |
| `createdBy` | string | no | User id or UUID string. |
| `duration` | number | no | Project duration in seconds. |
| `revision` | integer | no | Revision counter. |
| `source` | string | no | Must be one of `mobile`, `web`, `template`, `import`. |

The backend preserves `metadata` in the stored project and uses `metadata.duration` as a fallback for total duration.

## `canvas`

| Field | Type | Required | Notes |
|---|---|---:|---|
| `width` | number | no | Defaults to `1080`. |
| `height` | number | no | Defaults to `1920`. |
| `fps` | number | no | Defaults to `30`. |
| `duration` | number | no | Optional project duration in seconds. |
| `aspectRatio` | string | no | Defaults to `9:16`. |
| `backgroundColor` | string | no | Hex color string. |
| `previewWidth` | number | no | Defaults to `360`. |
| `previewHeight` | number | no | Defaults to `640`. |
| `background` | object | no | Canvas background config. |
| `safeArea` | object | no | Safe area config. |

### `canvas.background`

| Field | Type | Required | Notes |
|---|---|---:|---|
| `type` | string | no | Background type such as `color`, `gradient`, `image`, `video`, `blurred_source`, `transparent`. |
| `color` | string | no | Hex color string. |
| `opacity` | number | no | 0 to 1. |
| `gradient` | object | no | Gradient config. Stored as-is. |
| `assetId` | string | no | Asset reference for image/video backgrounds. |
| `asset_id` | string | no | Alternate snake_case alias accepted by the normalizer. |
| `blur` | integer | no | Blur radius. |

### `canvas.safeArea`

| Field | Type | Required | Notes |
|---|---|---:|---|
| `enabled` | boolean | no | Enables safe area metadata. |
| `top` | number | no | Pixels. |
| `right` | number | no | Pixels. |
| `bottom` | number | no | Pixels. |
| `left` | number | no | Pixels. |

## `output`

| Field | Type | Required | Notes |
|---|---|---:|---|
| `format` | string | no | Supported values: `mp4`, `mov`, `webm`. |
| `quality` | string | no | Backend accepts the string and normalizes it. |
| `width` | number | no | Output width. |
| `height` | number | no | Output height. |
| `fps` | number | no | Output frame rate. |
| `videoCodec` | string | no | Example: `h264`, `h265`, `vp9`, `av1`. |
| `audioCodec` | string | no | Example: `aac`, `mp3`, `opus`. |
| `encoderPreset` | string | no | Example: `ultrafast`, `veryfast`, `medium`, `slow`. |
| `pixelFormat` | string | no | Example: `yuv420p`. |
| `crf` | integer | no | Constant rate factor. |
| `videoBitrate` | string | no | Video bitrate string. |
| `audioBitrate` | string | no | Audio bitrate string. |
| `audioSampleRate` | integer | no | Sample rate in Hz. |
| `audioChannels` | integer | no | Number of channels. |
| `fastStart` | boolean | no | Stored as `fast_start` in normalized output. |
| `poster` | object | no | Poster size config. |

Legacy aliases also accepted in backend normalization:

- `video_bitrate`
- `audio_bitrate`
- `audio_sample_rate`
- `audio_channels`
- `pixel_format`
- `preset`
- `bitrate`

## `assets`

Each asset entry is an object with these fields:

| Field | Type | Required | Notes |
|---|---|---:|---|
| `id` | string | yes | Asset id used by `source.assetId`. |
| `type` | string | no | Example: `video`, `image`, `audio`, `font`, `sticker`, `drawing`, `lut`, `mask`. |
| `storageProvider` | string | no | Example: `s3`, `spaces`, `local`, `cloudinary`. |
| `storageKey` | string | no | Storage key or Cloudinary public id depending on provider. |
| `url` | string | no | Fallback URL. |
| `mimeType` | string | no | MIME type. |
| `fileName` | string | no | File name. |
| `fileSize` | number | no | File size in bytes. |
| `width` | number | no | Asset width. |
| `height` | number | no | Asset height. |
| `duration` | number | no | Duration in seconds. |
| `fps` | number | no | Frames per second. |
| `hasAudio` | boolean | no | Whether media has audio. |
| `checksum` | string | no | Optional checksum. |

The normalizer also accepts snake_case aliases like `storage_provider`, `storage_key`, `mime_type`, `file_name`, `file_size`, and `has_audio`.

## `scenes`

Each scene is an object with:

| Field | Type | Required | Notes |
|---|---|---:|---|
| `id` | string | no | Scene id. Defaults to `scene-<index>`. |
| `name` | string | no | Scene name. Defaults to `Scene <index>`. |
| `order` | integer | no | Scene order. Defaults to array index. |
| `timeline` | object | no | Scene timing info. |
| `background` | object | no | Scene background config. |
| `tracks` | array | yes | Tracks inside the scene. |
| `transitionIn` | object | no | Preserved metadata. |
| `transitionOut` | object | no | Preserved metadata. |
| `enabled` | boolean | no | Defaults to `true`. |

### `scene.timeline`

| Field | Type | Required | Notes |
|---|---|---:|---|
| `start` | number | no | Scene start time in seconds. |
| `duration` | number | no | Scene duration in seconds. |

### `scene.background`

Same shape as `canvas.background`.

## Track Model

The backend flattens scenes into a track array for rendering. Every track may include shared fields:

| Field | Type | Required | Notes |
|---|---|---:|---|
| `id` | string | no | Track id. |
| `type` | string | yes | See supported track types below. |
| `name` | string | no | Track label. |
| `layer` | integer | no | Z-order. |
| `enabled` | boolean | no | Defaults to `true`. |
| `visible` | boolean | no | Preserved. |
| `locked` | boolean | no | Preserved. |
| `muted` | boolean | no | Preserved. |
| `selected` | boolean | no | Preserved. |
| `groupId` | string | no | Group id. |
| `parentCompositionId` | string | no | Parent composition id. |
| `timeline` | object | no | Track timing info. |
| `transform` | object | no | Position, size, scale, rotation. |
| `crop` | object | no | Crop config. |
| `opacity` | number | no | 0 to 1. |
| `blendMode` | string | no | Example: `normal`, `multiply`, `screen`, etc. |
| `mask` | object | no | Preserved. |
| `border` | object | no | Preserved. |
| `shadow` | object | no | Preserved. |
| `glow` | object | no | Preserved. |
| `animations` | object | no | Preserved. |
| `keyframes` | array | no | Stored and used by parts of the renderer. |
| `motionPath` | object | no | Preserved. |
| `effects` | array | no | Preserved. |
| `metadata` | object | no | Stored as raw metadata. |

### `track.timeline`

| Field | Type | Required | Notes |
|---|---|---:|---|
| `start` | number | no | Start offset within the scene. |
| `duration` | number | no | Duration in seconds. |
| `trimStart` | number | no | Trim start. |
| `trimEnd` | number | no | Trim end. |
| `playbackRate` | number | no | Defaults to `1`. |
| `reverse` | boolean | no | Preserved. |
| `loop` | boolean | no | Preserved. |
| `freezeAtEnd` | boolean | no | Preserved. |

### `track.transform`

| Field | Type | Required | Notes |
|---|---|---:|---|
| `position` | object | no | `{ x, y }`. |
| `size` | object | no | `{ width, height, maxWidth, maxHeight, maintainAspectRatio }`. |
| `scale` | object | no | `{ x, y, uniform }`. |
| `anchor` | object | no | `{ preset, x, y }`. |
| `rotation` | number | no | Degrees. |
| `skew` | object | no | `{ x, y }`. |
| `opacity` | number | no | 0 to 1. |
| `flipHorizontal` | boolean | no | Preserved. |
| `flipVertical` | boolean | no | Preserved. |

### `track.crop`

| Field | Type | Required | Notes |
|---|---|---:|---|
| `enabled` | boolean | no | Crop enabled flag. |
| `x` | number | no | Normalized crop x or px, depending on your implementation. |
| `y` | number | no | Normalized crop y or px. |
| `width` | number | no | Width. |
| `height` | number | no | Height. |
| `unit` | string | no | Backend preserves `normalized` when present. |

## Supported Track Types

The backend validator currently allows these types:

- `video`
- `image`
- `text`
- `caption`
- `sticker`
- `drawing`
- `shape`
- `audio`
- `adjustment`
- `composition`

It also accepts these additional renderer-only types in some paths:

- `captions`
- `voiceover`
- `animated_sticker`
- `mask`
- `matte`
- `background`
- `guide`
- `transition`
- `effect`

## Track Type Requirements

### `text` and `caption`

Required:

- `content.text`

Commonly used:

- `content.runs`
- `textStyle`
- `textBox`

Backend maps these into render layers using:

- `textStyle.fontFamily`
- `textStyle.fontAssetId`
- `textStyle.fontSize`
- `textStyle.fontWeight`
- `textStyle.fontStyle`
- `textStyle.fill.color`
- `textStyle.opacity`
- `textStyle.alignment`
- `textStyle.lineHeight`
- `textStyle.letterSpacing`
- `textStyle.stroke`
- `textStyle.shadow`
- `textBox.padding`
- `textBox.margin`
- `textBox.background`
- `textBox.border`
- `textBox.radius`

### `video`, `image`, `sticker`, `drawing`

Required source options:

- `source.assetId`
- or `source.fallbackUrl`

The backend resolves the source through the asset registry first. It also accepts raw render-layer fields:

- `public_id`
- `asset_public_id`
- `asset_url`
- `asset_disk`
- `asset_key`

### `audio`

Accepted and preserved:

- `source`
- `audio`

The backend stores audio track metadata and passes the audio timeline through, but the current FFmpeg render path does not yet implement the full v3 audio-mixing model.

### `shape`

Common shape fields:

- `shapeType`
- `points`
- `fill`
- `stroke`

The backend currently renders shapes as solid boxes in the FFmpeg path when possible and preserves the richer shape metadata.

### `adjustment`, `composition`, `mask`, `matte`, `background`, `guide`, `transition`, `effect`

These are accepted and preserved in the project data model.

Current rendering support is partial:

- some are used for renderer selection
- some are stored for future rendering
- some are not yet turned into FFmpeg layers

## Animation and Keyframes

The backend accepts and stores:

- `animations`
- `keyframes`
- `motionPath`

The renderer currently uses a simplified interpretation of keyframes for positional and opacity changes on supported layers.

Recommended keyframe shape:

```json
{
  "id": "kf-1",
  "property": "transform.position.x",
  "time": 1.5,
  "value": 480,
  "interpolation": "linear",
  "easing": {
    "type": "linear",
    "cubicBezier": null,
    "spring": null
  }
}
```

## Backend Normalization Rules

- Missing `metadata.duration` may fall back to the video duration.
- Missing scene `timeline.duration` may be derived from the tracks in the scene.
- Missing track `end` may fall back to the project duration.
- `schemaVersion` is stored as the literal string you send.
- `project.version` is normalized to `3`.
- Raw input is preserved in `project.raw_payload` and `timeline.raw_payload`.

## What Is Rendered Today

Supported in the current FFmpeg render path:

- text/caption layers
- image/video/sticker/drawing overlays when a source can be resolved
- basic position, size, opacity, stroke, shadow, and timing behavior

Accepted and preserved, but not fully rendered yet:

- advanced scene transitions
- global effects
- advanced audio mixing
- full motion path behavior
- full mask/composition pipeline

## Example v3 Payload

```json
{
  "schemaVersion": "3.0.0",
  "metadata": {
    "id": "project-v3",
    "name": "Sample Project",
    "description": "v3 editor payload",
    "createdAt": "2026-08-03T11:00:00Z",
    "updatedAt": "2026-08-03T11:05:00Z",
    "createdBy": "6",
    "duration": 18,
    "revision": 1,
    "source": "web"
  },
  "canvas": {
    "width": 1080,
    "height": 1920,
    "aspectRatio": "9:16",
    "fps": 30,
    "duration": 18,
    "backgroundColor": "#000000",
    "background": {
      "type": "color",
      "color": "#000000",
      "opacity": 1
    },
    "safeArea": {
      "enabled": true,
      "top": 120,
      "right": 80,
      "bottom": 120,
      "left": 80
    }
  },
  "output": {
    "format": "mp4",
    "quality": "high",
    "width": 1080,
    "height": 1920,
    "fps": 30,
    "videoCodec": "h264",
    "audioCodec": "aac",
    "encoderPreset": "veryfast",
    "pixelFormat": "yuv420p",
    "fastStart": true
  },
  "assets": [
    {
      "id": "asset-image-1",
      "type": "image",
      "storageProvider": "cloudinary",
      "storageKey": "samples/v3/image-1",
      "url": "https://example.com/image-1.png",
      "mimeType": "image/png",
      "fileName": "image-1.png",
      "width": 800,
      "height": 800
    }
  ],
  "scenes": [
    {
      "id": "scene-1",
      "name": "Intro",
      "order": 0,
      "timeline": {
        "start": 0,
        "duration": 18
      },
      "background": {
        "type": "color",
        "color": "#000000",
        "opacity": 1
      },
      "tracks": [
        {
          "id": "track-text-1",
          "type": "text",
          "name": "Title",
          "layer": 1,
          "timeline": {
            "start": 0,
            "duration": 18,
            "trimStart": 0,
            "trimEnd": 18,
            "playbackRate": 1,
            "reverse": false,
            "loop": false,
            "freezeAtEnd": false
          },
          "transform": {
            "position": { "x": 120, "y": 240 },
            "size": { "width": 720, "height": 160 },
            "scale": { "x": 1, "y": 1, "uniform": true },
            "anchor": { "preset": "center", "x": 0.5, "y": 0.5 },
            "rotation": 0,
            "skew": { "x": 0, "y": 0 },
            "opacity": 1,
            "flipHorizontal": false,
            "flipVertical": false
          },
          "content": {
            "text": "Hello v3",
            "runs": []
          },
          "textStyle": {
            "fontFamily": "Arial",
            "fontSize": 48,
            "fontWeight": 700,
            "fontStyle": "normal",
            "color": "#FFFFFF",
            "opacity": 1,
            "alignment": "center",
            "verticalAlignment": "middle",
            "direction": "ltr",
            "lineHeight": 1.2,
            "letterSpacing": 0,
            "wordSpacing": 0,
            "paragraphSpacing": 0,
            "textTransform": "none",
            "maxWidth": null,
            "maxHeight": null,
            "maxLines": null,
            "autoWrap": true,
            "autoFit": true,
            "autoShrink": false,
            "overflow": "hidden",
            "fill": {
              "type": "solid",
              "color": "#FFFFFF",
              "opacity": 1,
              "gradient": null
            },
            "stroke": {
              "enabled": false,
              "color": "#000000",
              "width": 0,
              "opacity": 1,
              "join": "round"
            },
            "shadow": {
              "enabled": false,
              "color": "#000000",
              "opacity": 0,
              "blur": 0,
              "spread": 0,
              "offsetX": 0,
              "offsetY": 0
            },
            "glow": {
              "enabled": false,
              "color": "#FFFFFF",
              "opacity": 0,
              "blur": 0,
              "spread": 0,
              "intensity": 0
            }
          },
          "textBox": {
            "padding": { "top": 12, "right": 12, "bottom": 12, "left": 12 },
            "margin": { "top": 0, "right": 0, "bottom": 0, "left": 0 },
            "background": {
              "enabled": true,
              "type": "solid",
              "color": "#000000",
              "opacity": 0.35,
              "gradient": null,
              "blur": 0
            },
            "border": {
              "enabled": false,
              "color": "#000000",
              "width": 0,
              "opacity": 1,
              "style": "solid",
              "radius": {
                "topLeft": 0,
                "topRight": 0,
                "bottomRight": 0,
                "bottomLeft": 0
              }
            },
            "radius": {
              "topLeft": 0,
              "topRight": 0,
              "bottomRight": 0,
              "bottomLeft": 0
            }
          }
        },
        {
          "id": "track-image-1",
          "type": "image",
          "name": "Sticker",
          "layer": 2,
          "timeline": {
            "start": 2,
            "duration": 8,
            "trimStart": 0,
            "trimEnd": 8,
            "playbackRate": 1,
            "reverse": false,
            "loop": false,
            "freezeAtEnd": false
          },
          "transform": {
            "position": { "x": 500, "y": 900 },
            "size": { "width": 320, "height": 320 },
            "scale": { "x": 1, "y": 1, "uniform": true },
            "anchor": { "preset": "center", "x": 0.5, "y": 0.5 },
            "rotation": 0,
            "skew": { "x": 0, "y": 0 },
            "opacity": 1,
            "flipHorizontal": false,
            "flipVertical": false
          },
          "source": {
            "assetId": "asset-image-1",
            "fallbackUrl": null
          },
          "fit": "contain"
        }
      ],
      "transitionIn": null,
      "transitionOut": null,
      "enabled": true
    }
  ],
  "globalAudioTracks": [],
  "globalEffects": [],
  "guides": {
    "showSafeArea": true,
    "showCenterGuides": true,
    "showRuleOfThirds": false,
    "showBoundingBoxes": true,
    "snappingEnabled": true,
    "snapThreshold": 8
  }
}
```

## Practical Frontend Guidance

- Use `schemaVersion: "3.0.0"` for all new editor payloads.
- Prefer `scenes` for new work.
- Use `assets` + `source.assetId` for media references.
- Include `metadata.duration` or scene durations if you want stable open-ended timing.
- Keep `textStyle.fill.color` and `textBox.background.color` in hex strings.
- Keep opacity values in the `0` to `1` range.
- Use `timeline.duration` and `track.timeline.duration` to avoid accidental open-ended renders.

